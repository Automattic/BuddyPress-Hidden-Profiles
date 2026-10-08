<?php
/**
 * Integration tests for who is hidden.
 *
 * @package BuddyPress-Hidden-Profiles
 */

declare( strict_types=1 );

namespace Automattic\BuddyPressHiddenProfiles\Tests\Integration;

use Automattic\BuddyPressHiddenProfiles\Hidden_Users;
use WP_User;

/**
 * Covers who is hidden, both filters, and the cache.
 *
 * @covers \Automattic\BuddyPressHiddenProfiles\Hidden_Users
 */
final class HiddenUsersTest extends TestCase {

	public function test_user_is_not_hidden_by_default(): void {
		$this->assertFalse( $this->hidden_users->is_hidden( $this->member_id ) );
	}

	public function test_user_with_hidden_meta_is_hidden(): void {
		$this->hide( $this->member_id );

		$this->assertTrue( $this->hidden_users->is_hidden( $this->member_id ) );
	}

	public function test_is_hidden_filter_overrides_the_hidden_list_in_both_directions(): void {
		$this->hide( $this->member_id );

		add_filter( 'buddypress_hidden_profiles_is_hidden', '__return_false' );
		$this->assertFalse( $this->hidden_users->is_hidden( $this->member_id ), 'Filter returning false should reveal a hidden user.' );
		remove_filter( 'buddypress_hidden_profiles_is_hidden', '__return_false' );

		add_filter( 'buddypress_hidden_profiles_is_hidden', '__return_true' );
		$this->assertTrue( $this->hidden_users->is_hidden( $this->admin_id ), 'Filter returning true should hide any user.' );
	}

	public function test_is_hidden_filter_returning_non_boolean_falls_back_to_the_hidden_list(): void {
		$this->hide( $this->member_id );
		add_filter( 'buddypress_hidden_profiles_is_hidden', '__return_empty_string' );

		$this->assertTrue( $this->hidden_users->is_hidden( $this->member_id ) );
	}

	public function test_users_from_the_additional_ids_filter_are_hidden_everywhere(): void {
		add_filter( 'buddypress_hidden_profiles_additional_hidden_ids', fn() => array( $this->member_id ) );

		$this->assertTrue( $this->hidden_users->is_hidden( $this->member_id ), 'Single profile checks should agree with member lists.' );
		$this->assertNotContains( $this->member_id, $this->members_loop_ids() );
		$this->assertSame( 404, $this->rest_get( '/members/' . $this->member_id )->get_status() );
	}

	public function test_hidden_ids_are_unique_integers_from_meta_and_the_filter(): void {
		$this->hide( $this->member_id );
		add_filter(
			'buddypress_hidden_profiles_additional_hidden_ids',
			fn() => array( (string) $this->member_id, (string) $this->admin_id )
		);

		$ids = $this->hidden_users->get_hidden_user_ids();

		$this->assertTrue( array_is_list( $ids ) );
		$expected = array( $this->admin_id, $this->member_id );
		sort( $expected );
		sort( $ids );
		$this->assertSame( $expected, $ids );
	}

	public function test_additional_ids_filter_returning_a_non_array_is_ignored(): void {
		$this->hide( $this->member_id );
		add_filter( 'buddypress_hidden_profiles_additional_hidden_ids', '__return_null' );

		$this->assertSame( array( $this->member_id ), $this->hidden_users->get_hidden_user_ids() );
	}

	public function test_hidden_ids_come_from_the_cache_when_present(): void {
		wp_cache_set( Hidden_Users::CACHE_KEY, array( 123 ), Hidden_Users::CACHE_GROUP );

		$this->assertSame( array( 123 ), $this->hidden_users->get_hidden_user_ids() );
	}

	public function test_cache_is_cleared_whenever_the_hidden_meta_changes(): void {
		$this->assertSame( array(), $this->hidden_users->get_hidden_user_ids() );

		$this->hide( $this->member_id );
		$this->assertSame( array( $this->member_id ), $this->hidden_users->get_hidden_user_ids(), 'Adding the meta' );

		update_user_meta( $this->member_id, Hidden_Users::META_KEY, 'visible' );
		$this->assertSame( array(), $this->hidden_users->get_hidden_user_ids(), 'Updating the meta' );

		$this->hide( $this->member_id );
		$this->hidden_users->get_hidden_user_ids();
		delete_user_meta( $this->member_id, Hidden_Users::META_KEY );
		$this->assertSame( array(), $this->hidden_users->get_hidden_user_ids(), 'Deleting the meta' );
	}

	public function test_cache_is_left_alone_when_other_meta_changes(): void {
		wp_cache_set( Hidden_Users::CACHE_KEY, array( 123 ), Hidden_Users::CACHE_GROUP );

		update_user_meta( $this->member_id, 'nickname', 'changed' );

		$this->assertSame( array( 123 ), $this->hidden_users->get_hidden_user_ids() );
	}

	/**
	 * Events that can change who is hidden, through the additional IDs filter.
	 *
	 * @return array<string, array{callable(int): void}>
	 */
	public static function data_user_lifecycle_events(): array {
		return array(
			'user registers'    => array( fn( int $user_id ) => self::factory()->user->create() ),
			'user role changes' => array( fn( int $user_id ) => ( new WP_User( $user_id ) )->set_role( 'editor' ) ),
			'user is deleted'   => array(
				function ( int $user_id ): void {
					// BuddyPress flushes the whole cache here, which would hide a missing hook.
					remove_action( 'delete_user', 'bp_core_remove_data_on_delete_user' );
					require_once ABSPATH . 'wp-admin/includes/user.php';
					wp_delete_user( $user_id );
				},
			),
		);
	}

	/**
	 * @dataProvider data_user_lifecycle_events
	 *
	 * @param callable $event Triggers the event for a user ID.
	 */
	public function test_cache_is_cleared_on_user_lifecycle_events( callable $event ): void {
		wp_cache_set( Hidden_Users::CACHE_KEY, array( 123 ), Hidden_Users::CACHE_GROUP );

		$event( $this->member_id );

		$this->assertFalse( wp_cache_get( Hidden_Users::CACHE_KEY, Hidden_Users::CACHE_GROUP ) );
	}

	public function test_clearing_the_cache_reaches_every_site_on_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Only multisite keeps a cache per site.' );
		}
		$other_site_id = self::factory()->blog->create();
		switch_to_blog( $other_site_id );
		$this->hidden_users->get_hidden_user_ids();
		restore_current_blog();

		$this->hide( $this->member_id );

		switch_to_blog( $other_site_id );
		$ids = $this->hidden_users->get_hidden_user_ids();
		restore_current_blog();
		$this->assertSame( array( $this->member_id ), $ids );
	}
}
