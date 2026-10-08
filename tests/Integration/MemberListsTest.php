<?php
/**
 * Integration tests for member lists.
 *
 * @package BuddyPress-Hidden-Profiles
 */

declare( strict_types=1 );

namespace Automattic\BuddyPressHiddenProfiles\Tests\Integration;

use BP_User_Query;
use WP_Error;

/**
 * Covers member directories and @mention suggestions, and that internal queries still see everyone.
 *
 * @covers \Automattic\BuddyPressHiddenProfiles\Visibility
 */
final class MemberListsTest extends TestCase {

	/**
	 * Run a bare BP_User_Query, as internal lookups do, and return the user IDs it found.
	 *
	 * @return int[] User IDs.
	 */
	private function user_query_ids(): array {
		$query = new BP_User_Query(
			array(
				'type'     => 'active',
				'per_page' => 100,
			)
		);

		return array_map( 'intval', $query->user_ids );
	}

	public function test_members_loop_excludes_hidden_users_for_visitors(): void {
		$this->hide( $this->member_id );

		$ids = $this->members_loop_ids();

		$this->assertNotContains( $this->member_id, $ids );
		$this->assertContains( $this->admin_id, $ids );
	}

	public function test_members_loop_excludes_hidden_users_for_other_members(): void {
		$this->hide( $this->member_id );
		wp_set_current_user( $this->create_active_member() );

		$this->assertNotContains( $this->member_id, $this->members_loop_ids() );
	}

	public function test_members_loop_shows_hidden_users_to_admins(): void {
		$this->hide( $this->member_id );
		wp_set_current_user( $this->admin_id );

		$this->assertContains( $this->member_id, $this->members_loop_ids() );
	}

	public function test_members_loop_shows_hidden_users_to_themselves(): void {
		$this->hide( $this->member_id );
		wp_set_current_user( $this->member_id );

		$this->assertContains( $this->member_id, $this->members_loop_ids() );
	}

	public function test_members_loop_cannot_ask_for_hidden_users_by_id(): void {
		$this->hide( $this->member_id );

		$this->assertSame(
			array( $this->admin_id ),
			$this->members_loop_ids( array( 'user_ids' => array( $this->member_id, $this->admin_id ) ) ),
			'user_ids skips the exclude clause, so hidden users must be removed from it.'
		);
		$this->assertSame( array(), $this->members_loop_ids( array( 'include' => $this->member_id ) ) );
	}

	public function test_existing_exclusions_are_kept(): void {
		$this->hide( $this->member_id );

		$args = $this->visibility->exclude_hidden_from_query_args( array( 'exclude' => '5,6' ) );

		$this->assertSame( array( 5, 6, $this->member_id ), $args['exclude'] );
	}

	public function test_query_args_from_a_short_circuiting_filter_are_left_alone(): void {
		$this->hide( $this->member_id );
		$error = new WP_Error( 'nope' );

		$this->assertSame( $error, $this->visibility->exclude_hidden_from_query_args( $error ) );
	}

	public function test_other_user_queries_still_see_hidden_users(): void {
		$this->hide( $this->member_id );

		$this->assertContains(
			$this->member_id,
			$this->user_query_ids(),
			'Internal lookups, such as activity authors, must still find hidden users.'
		);
	}

	public function test_mention_suggestions_exclude_hidden_users(): void {
		$hidden_id  = $this->create_active_member( array( 'user_login' => 'hpmention-hidden' ) );
		$visible_id = $this->create_active_member( array( 'user_login' => 'hpmention-visible' ) );
		$this->hide( $hidden_id );
		wp_set_current_user( $this->create_active_member() );

		$suggestions = bp_core_get_suggestions(
			array(
				'term' => 'hpmention',
				'type' => 'members',
			)
		);

		$this->assertIsArray( $suggestions );
		$this->assertSame( array( $visible_id ), array_map( 'intval', wp_list_pluck( $suggestions, 'user_id' ) ) );
	}

	public function test_primed_friend_mentions_exclude_hidden_users(): void {
		$this->hide( $this->member_id );
		$visible_id = $this->create_active_member();
		$viewer_id  = $this->create_active_member();
		friends_add_friend( $viewer_id, $this->member_id, true );
		friends_add_friend( $viewer_id, $visible_id, true );
		wp_set_current_user( $viewer_id );
		$GLOBALS['wp_scripts'] = null;
		add_filter( 'bp_activity_maybe_load_mentions_scripts', '__return_true' );

		bp_activity_mentions_script();

		$primed = (string) wp_scripts()->get_data( 'bp-mentions', 'data' );
		$this->assertStringContainsString( get_userdata( $visible_id )->user_nicename, $primed, 'The friends list should be primed.' );
		$this->assertStringNotContainsString( get_userdata( $this->member_id )->user_nicename, $primed );
	}
}
