<?php
/**
 * Integration tests for hiding profiles.
 *
 * @package BuddyPress-Hidden-Profiles
 */

declare( strict_types=1 );

namespace Automattic\BuddyPressHiddenProfiles\Tests\Integration;

use Automattic\BuddyPressHiddenProfiles\BuddyPress_Hidden_Profiles;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Covers who is hidden, and from whom.
 *
 * @covers \Automattic\BuddyPressHiddenProfiles\BuddyPress_Hidden_Profiles
 */
final class HiddenProfilesTest extends TestCase {

	/**
	 * System under test.
	 *
	 * @var BuddyPress_Hidden_Profiles
	 */
	private $plugin;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private $member_id;

	/**
	 * Set up a fresh plugin instance, users and an empty cache.
	 */
	public function set_up() {
		parent::set_up();

		$this->plugin    = new BuddyPress_Hidden_Profiles();
		$this->admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->member_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_cache_delete( 'bp_hidden_user_ids' );
	}

	/**
	 * Mark a user as hidden the way the profile screen does.
	 *
	 * @param int $user_id User ID.
	 */
	private function hide( int $user_id ): void {
		update_user_meta( $user_id, BuddyPress_Hidden_Profiles::META_KEY, BuddyPress_Hidden_Profiles::META_HIDDEN_VALUE );
	}

	/**
	 * Simulate a profile screen submission.
	 *
	 * @param int  $user_id User being edited.
	 * @param bool $checked Whether the hidden checkbox is ticked.
	 * @param bool $nonce   Whether to send a valid nonce.
	 */
	private function submit_profile( int $user_id, bool $checked, bool $nonce = true ): void {
		$_POST = array();
		if ( $nonce ) {
			$_POST['buddypress_hidden_profiles_nonce'] = wp_create_nonce( 'buddypress_hidden_profiles_visibility' );
		}
		if ( $checked ) {
			$_POST[ BuddyPress_Hidden_Profiles::META_KEY ] = BuddyPress_Hidden_Profiles::META_HIDDEN_VALUE;
		}

		$this->plugin->save_visibility_setting( $user_id );
	}

	/**
	 * Clean up the superglobal.
	 */
	public function tear_down() {
		$_POST = array();
		parent::tear_down();
	}

	public function test_user_is_not_hidden_by_default(): void {
		$this->assertFalse( $this->plugin->is_hidden( $this->member_id ) );
	}

	public function test_user_with_hidden_meta_is_hidden(): void {
		$this->hide( $this->member_id );

		$this->assertTrue( $this->plugin->is_hidden( $this->member_id ) );
	}

	public function test_is_hidden_filter_overrides_meta_in_both_directions(): void {
		$this->hide( $this->member_id );

		add_filter( 'buddypress_hidden_profiles_is_hidden', '__return_false' );
		$this->assertFalse( $this->plugin->is_hidden( $this->member_id ), 'Filter returning false should reveal a hidden user.' );
		remove_filter( 'buddypress_hidden_profiles_is_hidden', '__return_false' );

		add_filter( 'buddypress_hidden_profiles_is_hidden', '__return_true' );
		$this->assertTrue( $this->plugin->is_hidden( $this->admin_id ), 'Filter returning true should hide any user.' );
	}

	public function test_is_hidden_filter_returning_non_boolean_falls_back_to_meta(): void {
		$this->hide( $this->member_id );
		add_filter( 'buddypress_hidden_profiles_is_hidden', '__return_empty_string' );

		$this->assertTrue( $this->plugin->is_hidden( $this->member_id ) );
	}

	public function test_hidden_ids_include_meta_and_filtered_users_without_duplicates(): void {
		$this->hide( $this->member_id );
		add_filter(
			'buddypress_hidden_profiles_additional_hidden_ids',
			fn() => array( (string) $this->member_id, (string) $this->admin_id )
		);

		$ids = array_map( 'intval', $this->plugin->get_hidden_user_ids() );
		sort( $ids );

		$expected = array( $this->admin_id, $this->member_id );
		sort( $expected );
		$this->assertSame( $expected, $ids );
	}

	public function test_hidden_ids_are_cached_until_cleared(): void {
		$this->assertSame( array(), $this->plugin->get_hidden_user_ids() );

		$this->hide( $this->member_id );
		$this->assertSame( array(), $this->plugin->get_hidden_user_ids(), 'A cached result should be reused.' );

		$this->plugin->clear_hidden_cache();
		$this->assertSame( array( (string) $this->member_id ), $this->plugin->get_hidden_user_ids() );
	}

	public function test_cache_is_cleared_when_a_user_registers(): void {
		wp_cache_set( 'bp_hidden_user_ids', array( '123' ) );

		self::factory()->user->create();

		$this->assertFalse( wp_cache_get( 'bp_hidden_user_ids' ) );
	}

	public function test_member_directory_excludes_hidden_users_for_non_admins(): void {
		$this->hide( $this->member_id );
		wp_set_current_user( $this->member_id );

		parse_str( $this->plugin->ajax_exclude_hidden( 'type=active&exclude=5', 'members' ), $args );

		$this->assertSame( 'active', $args['type'] );
		$this->assertSame( array( '5', (string) $this->member_id ), $args['exclude'] );
	}

	public function test_member_directory_is_unfiltered_for_admins(): void {
		$this->hide( $this->member_id );
		wp_set_current_user( $this->admin_id );

		$this->assertSame( 'type=active', $this->plugin->ajax_exclude_hidden( 'type=active', 'members' ) );
	}

	public function test_other_directories_are_unfiltered(): void {
		$this->hide( $this->member_id );
		wp_set_current_user( $this->member_id );

		$this->assertSame( 'type=active', $this->plugin->ajax_exclude_hidden( 'type=active', 'groups' ) );
	}

	public function test_admin_can_hide_and_unhide_a_profile(): void {
		wp_set_current_user( $this->admin_id );

		$this->submit_profile( $this->member_id, true );
		$this->assertTrue( $this->plugin->is_hidden( $this->member_id ), 'Ticking the box should hide the profile.' );

		$this->submit_profile( $this->member_id, false );
		$this->assertFalse( $this->plugin->is_hidden( $this->member_id ), 'Unticking the box should reveal the profile.' );
	}

	public function test_saving_clears_the_hidden_ids_cache(): void {
		wp_set_current_user( $this->admin_id );
		$this->plugin->get_hidden_user_ids();

		$this->submit_profile( $this->member_id, true );

		$this->assertSame( array( (string) $this->member_id ), $this->plugin->get_hidden_user_ids() );
	}

	public function test_save_without_a_valid_nonce_changes_nothing(): void {
		wp_set_current_user( $this->admin_id );

		$this->submit_profile( $this->member_id, true, false );

		$this->assertFalse( $this->plugin->is_hidden( $this->member_id ) );
	}

	public function test_non_admin_cannot_hide_a_profile(): void {
		wp_set_current_user( $this->member_id );

		$this->submit_profile( $this->member_id, true );

		$this->assertFalse( $this->plugin->is_hidden( $this->member_id ) );
	}

	public function test_setting_is_shown_to_admins_only(): void {
		$user = get_userdata( $this->member_id );

		wp_set_current_user( $this->member_id );
		ob_start();
		$this->plugin->visibility_setting_ui( $user );
		$this->assertSame( '', ob_get_clean(), 'Non-admins should not see the setting.' );

		wp_set_current_user( $this->admin_id );
		ob_start();
		$this->plugin->visibility_setting_ui( $user );
		$this->assertStringContainsString( 'name="profile_visibility"', ob_get_clean() );
	}
}
