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
	 * Create a member who shows up in "active" member lists.
	 *
	 * BuddyPress member lists only include users with a last activity date.
	 *
	 * @param string $login Optional user login.
	 * @return int User ID.
	 */
	private function create_active_member( string $login = '' ): int {
		$user_id = self::factory()->user->create( $login ? array( 'user_login' => $login ) : array() );
		bp_update_user_last_activity( $user_id );

		return $user_id;
	}

	/**
	 * Run a members loop, as the directory does, and return the user IDs it found.
	 *
	 * @param array $args bp_has_members() arguments.
	 * @return int[] User IDs.
	 */
	private function members_loop_ids( array $args = array() ): array {
		bp_update_user_last_activity( $this->member_id );
		bp_update_user_last_activity( $this->admin_id );
		bp_has_members( $args + array( 'per_page' => 100 ) );

		return array_map( 'intval', wp_list_pluck( $GLOBALS['members_template']->members, 'ID' ) );
	}

	/**
	 * Run a bare BP_User_Query, as internal lookups do, and return the user IDs it found.
	 *
	 * @return int[] User IDs.
	 */
	private function user_query_ids(): array {
		bp_update_user_last_activity( $this->member_id );
		$query = new \BP_User_Query(
			array(
				'type'     => 'active',
				'per_page' => 100,
			)
		);

		return array_map( 'intval', $query->user_ids );
	}

	/**
	 * Create a public group whose members are the hidden member and a visible one.
	 *
	 * @return int[] Group ID, visible member ID and group admin ID.
	 */
	private function create_group_with_members(): array {
		$group_admin_id = $this->create_active_member();
		$visible_id     = $this->create_active_member();
		$group_id       = groups_create_group(
			array(
				'creator_id' => $group_admin_id,
				'name'       => 'Hidden profiles test group',
				'status'     => 'public',
			)
		);
		groups_join_group( $group_id, $this->member_id );
		groups_join_group( $group_id, $visible_id );

		return array( $group_id, $visible_id, $group_admin_id );
	}

	/**
	 * Run a group members loop, as the group's Members tab does, and return the user IDs it found.
	 *
	 * @param int $group_id Group ID.
	 * @return int[] User IDs.
	 */
	private function group_members_loop_ids( int $group_id ): array {
		bp_group_has_members(
			array(
				'group_id'            => $group_id,
				'exclude_admins_mods' => false,
				'per_page'            => 100,
			)
		);

		return array_map( 'intval', wp_list_pluck( $GLOBALS['members_template']->members, 'user_id' ) );
	}

	/**
	 * Send a GET request to the BuddyPress (or BuddyBoss) REST API.
	 *
	 * @param string $route  Route after the namespace, e.g. '/members'.
	 * @param array  $params Query parameters.
	 * @return \WP_REST_Response
	 */
	private function rest_get( string $route, array $params = array() ): \WP_REST_Response {
		bp_update_user_last_activity( $this->member_id );
		bp_update_user_last_activity( $this->admin_id );

		$request = new \WP_REST_Request( 'GET', '/' . bp_rest_namespace() . '/' . bp_rest_version() . $route );
		$request->set_query_params( $params );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Clean up the superglobal and the REST server.
	 */
	public function tear_down() {
		$_POST                     = array();
		$GLOBALS['wp_rest_server'] = null;
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

	public function test_profile_check_runs_before_buddypress_request_handlers(): void {
		$this->plugin->run();

		$priority = has_action( 'bp_template_redirect', array( $this->plugin, 'maybe_hide_profile' ) );

		$this->assertIsInt( $priority );
		$this->assertLessThan( has_action( 'bp_template_redirect', 'bp_redirect_canonical' ), $priority, 'Should run before the canonical redirect.' );
		$this->assertLessThan( has_action( 'bp_template_redirect', 'bp_actions' ), $priority, 'Should run before feeds and other actions.' );
		$this->assertLessThan( has_action( 'bp_template_redirect', 'bp_screens' ), $priority, 'Should run before screens load templates.' );
	}

	public function test_members_loop_excludes_hidden_users_for_visitors(): void {
		$this->hide( $this->member_id );
		$visible_id = $this->create_active_member();

		$ids = $this->members_loop_ids();

		$this->assertNotContains( $this->member_id, $ids );
		$this->assertContains( $visible_id, $ids );
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
		$visible_id = $this->create_active_member();

		$this->assertSame(
			array( $visible_id ),
			$this->members_loop_ids( array( 'user_ids' => array( $this->member_id, $visible_id ) ) ),
			'user_ids skips the exclude clause, so hidden users must be removed from it.'
		);
		$this->assertSame( array(), $this->members_loop_ids( array( 'include' => $this->member_id ) ) );
	}

	public function test_existing_exclusions_are_kept(): void {
		$this->hide( $this->member_id );

		$args = $this->plugin->exclude_hidden_from_query_args( array( 'exclude' => '5,6' ) );

		$this->assertSame( array( 5, 6, $this->member_id ), $args['exclude'] );
	}

	public function test_query_args_from_a_short_circuiting_filter_are_left_alone(): void {
		$this->hide( $this->member_id );
		$error = new \WP_Error( 'nope' );

		$this->assertSame( $error, $this->plugin->exclude_hidden_from_query_args( $error ) );
	}

	public function test_mention_suggestions_exclude_hidden_users(): void {
		$hidden_id  = $this->create_active_member( 'hpmention-hidden' );
		$visible_id = $this->create_active_member( 'hpmention-visible' );
		$this->hide( $hidden_id );
		wp_set_current_user( $this->create_active_member() );

		$suggestions = bp_core_get_suggestions(
			array(
				'term' => 'hpmention',
				'type' => 'members',
			)
		);

		$this->assertIsArray( $suggestions );
		$ids = array_map( 'intval', wp_list_pluck( $suggestions, 'user_id' ) );
		$this->assertSame( array( $visible_id ), $ids );
	}

	public function test_rest_members_list_excludes_hidden_users_for_visitors(): void {
		$this->hide( $this->member_id );
		$visible_id = $this->create_active_member();

		$response = $this->rest_get( '/members' );

		$this->assertSame( 200, $response->get_status() );
		$ids = wp_list_pluck( $response->get_data(), 'id' );
		$this->assertNotContains( $this->member_id, $ids );
		$this->assertContains( $visible_id, $ids );
	}

	public function test_rest_members_list_cannot_include_hidden_users(): void {
		$this->hide( $this->member_id );

		$response = $this->rest_get( '/members', array( 'include' => array( $this->member_id ) ) );

		$this->assertSame( array(), $response->get_data() );
	}

	public function test_rest_members_list_shows_hidden_users_to_admins(): void {
		$this->hide( $this->member_id );
		wp_set_current_user( $this->admin_id );

		$ids = wp_list_pluck( $this->rest_get( '/members' )->get_data(), 'id' );

		$this->assertContains( $this->member_id, $ids );
	}

	public function test_rest_hidden_member_looks_like_a_missing_member_to_visitors(): void {
		$this->hide( $this->member_id );

		$hidden  = $this->rest_get( '/members/' . $this->member_id );
		$missing = $this->rest_get( '/members/999999999' );

		$this->assertSame( 404, $hidden->get_status() );
		$this->assertSame( $missing->get_status(), $hidden->get_status() );
		$this->assertSame( $missing->get_data()['code'], $hidden->get_data()['code'] );
	}

	public function test_rest_hidden_member_is_shown_to_themselves_and_admins(): void {
		$this->hide( $this->member_id );

		wp_set_current_user( $this->member_id );
		$this->assertSame( 200, $this->rest_get( '/members/' . $this->member_id )->get_status(), 'Owner' );

		wp_set_current_user( $this->admin_id );
		$this->assertSame( 200, $this->rest_get( '/members/' . $this->member_id )->get_status(), 'Admin' );
	}

	public function test_group_member_list_excludes_hidden_users_for_visitors(): void {
		$this->hide( $this->member_id );
		list( $group_id, $visible_id ) = $this->create_group_with_members();

		$ids = $this->group_members_loop_ids( $group_id );

		$this->assertNotContains( $this->member_id, $ids );
		$this->assertContains( $visible_id, $ids );
	}

	public function test_group_member_list_shows_hidden_users_to_group_admins(): void {
		$this->hide( $this->member_id );
		list( $group_id, , $group_admin_id ) = $this->create_group_with_members();
		wp_set_current_user( $group_admin_id );

		$this->assertContains( $this->member_id, $this->group_members_loop_ids( $group_id ) );
	}

	public function test_rest_group_member_list_excludes_hidden_users_for_visitors(): void {
		$this->hide( $this->member_id );
		list( $group_id, $visible_id ) = $this->create_group_with_members();

		$response = $this->rest_get( '/groups/' . $group_id . '/members' );

		$this->assertSame( 200, $response->get_status() );
		$ids = wp_list_pluck( $response->get_data(), 'id' );
		$this->assertNotContains( $this->member_id, $ids );
		$this->assertContains( $visible_id, $ids );
	}

	public function test_primed_mention_suggestions_exclude_hidden_users(): void {
		$this->hide( $this->member_id );
		$visible_id = $this->create_active_member();
		wp_set_current_user( $this->create_active_member() );

		// Stands in for the prime callbacks, which build a BP_User_Query with no arguments filter.
		$ids = array();
		$prime = function () use ( &$ids ) {
			$ids = $this->user_query_ids();
		};
		add_action( 'bp_activity_mentions_prime_results', $prime );
		do_action( 'bp_activity_mentions_prime_results' );

		$this->assertNotContains( $this->member_id, $ids );
		$this->assertContains( $visible_id, $ids );
	}

	public function test_other_user_queries_still_see_hidden_users(): void {
		$this->hide( $this->member_id );

		$this->assertContains(
			$this->member_id,
			$this->user_query_ids(),
			'Internal lookups, such as activity authors, must still find hidden users.'
		);
	}

	public function test_group_invite_list_excludes_hidden_users_even_for_group_admins(): void {
		$this->hide( $this->member_id );
		$group_admin_id = $this->create_active_member();
		$visible_id     = $this->create_active_member();
		$group_id       = groups_create_group(
			array(
				'creator_id' => $group_admin_id,
				'name'       => 'Hidden profiles invite group',
				'status'     => 'public',
			)
		);
		wp_set_current_user( $group_admin_id );

		// The Nouveau template pack only loads its group classes on front-end requests.
		require_once buddypress()->plugin_dir . 'bp-templates/bp-nouveau/includes/groups/classes.php';
		$query = new \BP_Nouveau_Group_Invite_Query(
			array(
				'group_id'     => $group_id,
				'type'         => 'alphabetical',
				'per_page'     => 100,
				'is_confirmed' => true,
			)
		);
		$ids   = array_map( 'intval', $query->user_ids );

		$this->assertNotContains( $this->member_id, $ids );
		$this->assertNotContains( $group_admin_id, $ids, 'Existing group members should still be excluded.' );
		$this->assertContains( $visible_id, $ids );
	}

	public function test_rest_member_actions_treat_hidden_members_as_missing(): void {
		$this->hide( $this->member_id );
		wp_set_current_user( $this->create_active_member() );
		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'id', $this->member_id );

		// BuddyBoss's follow/unfollow endpoint; BuddyPress has no equivalent route to dispatch to.
		$result = apply_filters( 'bp_rest_members_action_update_item_permissions_check', true, $request );

		$this->assertWPError( $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_rest_visible_member_is_unaffected(): void {
		$this->hide( $this->member_id );

		$this->assertSame( 200, $this->rest_get( '/members/' . $this->create_active_member() )->get_status() );
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
