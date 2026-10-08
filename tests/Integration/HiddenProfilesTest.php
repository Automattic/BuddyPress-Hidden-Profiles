<?php
/**
 * Integration tests for hiding profiles.
 *
 * @package BuddyPress-Hidden-Profiles
 */

declare( strict_types=1 );

namespace Automattic\BuddyPressHiddenProfiles\Tests\Integration;

use Automattic\BuddyPressHiddenProfiles\BuddyPress_Hidden_Profiles;
use BP_Groups_Member;
use BP_Nouveau_Group_Invite_Query;
use BP_User_Query;
use WP_Error;
use WP_HTML_Tag_Processor;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Covers who is hidden, and from whom.
 *
 * Hooked behaviour runs through the instance the plugin creates on bp_loaded.
 * $plugin is a second instance for calling methods directly; the two share
 * state only through user meta and the object cache.
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
	 * Subscriber user ID, which most tests hide.
	 *
	 * @var int
	 */
	private $member_id;

	/**
	 * Set up a fresh plugin instance, active users and an empty cache.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->plugin    = new BuddyPress_Hidden_Profiles();
		$this->admin_id  = $this->create_active_member( array( 'role' => 'administrator' ) );
		$this->member_id = $this->create_active_member();

		$this->plugin->clear_hidden_cache();
	}

	/**
	 * Clean up the superglobal, the REST server and the displayed user.
	 */
	protected function tearDown(): void {
		$_POST                          = array();
		$GLOBALS['wp_rest_server']      = null;
		buddypress()->displayed_user    = new \stdClass();
		buddypress()->current_component = '';
		parent::tearDown();
	}

	/**
	 * Mark a user as hidden the way WP-CLI does.
	 *
	 * @param int $user_id User ID.
	 */
	private function hide( int $user_id ): void {
		update_user_meta( $user_id, BuddyPress_Hidden_Profiles::META_KEY, BuddyPress_Hidden_Profiles::META_HIDDEN_VALUE );
	}

	/**
	 * Create a member who shows up in "active" member lists.
	 *
	 * BuddyPress member lists only include users with a last activity date.
	 *
	 * @param array $args Optional user arguments.
	 * @return int User ID.
	 */
	private function create_active_member( array $args = array() ): int {
		$user_id = self::factory()->user->create( $args );
		bp_update_user_last_activity( $user_id );

		return $user_id;
	}

	/**
	 * Simulate a profile screen submission.
	 *
	 * @param string $hook    Save hook: personal_options_update for your own profile, edit_user_profile_update for someone else's.
	 * @param int    $user_id User being edited.
	 * @param bool   $checked Whether the hidden checkbox is ticked.
	 * @param bool   $nonce   Whether to send a valid nonce.
	 */
	private function submit_profile( string $hook, int $user_id, bool $checked, bool $nonce = true ): void {
		// Core checks the email field when people save their own profile, and before
		// WordPress 7.0.3 it read the user ID from the form too.
		$_POST = array(
			'user_id' => $user_id,
			'email'   => get_userdata( $user_id )->user_email,
		);
		if ( $nonce ) {
			$_POST['buddypress_hidden_profiles_nonce'] = wp_create_nonce( 'buddypress_hidden_profiles_visibility' );
		}
		if ( $checked ) {
			$_POST[ BuddyPress_Hidden_Profiles::META_KEY ] = BuddyPress_Hidden_Profiles::META_HIDDEN_VALUE;
		}

		do_action( $hook, $user_id );
	}

	/**
	 * Render the profile screen section for a user, as the current user.
	 *
	 * @param int $user_id User being viewed.
	 * @return string HTML.
	 */
	private function render_profile_section( int $user_id ): string {
		ob_start();
		do_action( 'edit_user_profile', get_userdata( $user_id ) );

		return (string) ob_get_clean();
	}

	/**
	 * Run a members loop, as the directory does, and return the user IDs it found.
	 *
	 * @param array $args bp_has_members() arguments.
	 * @return int[] User IDs.
	 */
	private function members_loop_ids( array $args = array() ): array {
		bp_has_members( $args + array( 'per_page' => 100 ) );

		return array_map( 'intval', wp_list_pluck( $GLOBALS['members_template']->members, 'ID' ) );
	}

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

	/**
	 * Create a public group whose members are the hidden member and a visible one.
	 *
	 * @return int[] Group ID, visible member ID and group admin ID.
	 */
	private function create_group_with_members(): array {
		$group_admin_id = $this->create_active_member( array( 'user_login' => 'hpgroup-admin' ) );
		$visible_id     = $this->create_active_member( array( 'user_login' => 'hpgroup-visible' ) );
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
	 * Send a GET request to the BuddyPress REST API.
	 *
	 * @param string $route  Route after the namespace, e.g. '/members'.
	 * @param array  $params Query parameters.
	 * @return WP_REST_Response
	 */
	private function rest_get( string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/' . bp_rest_namespace() . '/' . bp_rest_version() . $route );
		$request->set_query_params( $params );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Load a member's profile page, as far as working out who is displayed.
	 *
	 * @param int $user_id User whose profile to load.
	 */
	private function go_to_profile( int $user_id ): void {
		// The test case resets query vars between tests, so BuddyPress's need registering again.
		bp_add_rewrite_tags();
		$this->go_to( bp_members_get_user_url( $user_id ) );
	}

	/*
	 * Who is hidden.
	 */

	public function test_user_is_not_hidden_by_default(): void {
		$this->assertFalse( $this->plugin->is_hidden( $this->member_id ) );
	}

	public function test_user_with_hidden_meta_is_hidden(): void {
		$this->hide( $this->member_id );

		$this->assertTrue( $this->plugin->is_hidden( $this->member_id ) );
	}

	public function test_is_hidden_filter_overrides_the_hidden_list_in_both_directions(): void {
		$this->hide( $this->member_id );

		add_filter( 'buddypress_hidden_profiles_is_hidden', '__return_false' );
		$this->assertFalse( $this->plugin->is_hidden( $this->member_id ), 'Filter returning false should reveal a hidden user.' );
		remove_filter( 'buddypress_hidden_profiles_is_hidden', '__return_false' );

		add_filter( 'buddypress_hidden_profiles_is_hidden', '__return_true' );
		$this->assertTrue( $this->plugin->is_hidden( $this->admin_id ), 'Filter returning true should hide any user.' );
	}

	public function test_is_hidden_filter_returning_non_boolean_falls_back_to_the_hidden_list(): void {
		$this->hide( $this->member_id );
		add_filter( 'buddypress_hidden_profiles_is_hidden', '__return_empty_string' );

		$this->assertTrue( $this->plugin->is_hidden( $this->member_id ) );
	}

	public function test_users_from_the_additional_ids_filter_are_hidden_everywhere(): void {
		add_filter( 'buddypress_hidden_profiles_additional_hidden_ids', fn() => array( $this->member_id ) );

		$this->assertTrue( $this->plugin->is_hidden( $this->member_id ), 'Single profile checks should agree with member lists.' );
		$this->assertNotContains( $this->member_id, $this->members_loop_ids() );
		$this->assertSame( 404, $this->rest_get( '/members/' . $this->member_id )->get_status() );
	}

	public function test_hidden_ids_are_unique_integers_from_meta_and_the_filter(): void {
		$this->hide( $this->member_id );
		add_filter(
			'buddypress_hidden_profiles_additional_hidden_ids',
			fn() => array( (string) $this->member_id, (string) $this->admin_id )
		);

		$ids = $this->plugin->get_hidden_user_ids();

		$this->assertTrue( array_is_list( $ids ) );
		$expected = array( $this->admin_id, $this->member_id );
		sort( $expected );
		sort( $ids );
		$this->assertSame( $expected, $ids );
	}

	public function test_additional_ids_filter_returning_a_non_array_is_ignored(): void {
		$this->hide( $this->member_id );
		add_filter( 'buddypress_hidden_profiles_additional_hidden_ids', '__return_null' );

		$this->assertSame( array( $this->member_id ), $this->plugin->get_hidden_user_ids() );
	}

	/*
	 * Caching.
	 */

	public function test_hidden_ids_come_from_the_cache_when_present(): void {
		wp_cache_set( BuddyPress_Hidden_Profiles::CACHE_KEY, array( 123 ), BuddyPress_Hidden_Profiles::CACHE_GROUP );

		$this->assertSame( array( 123 ), $this->plugin->get_hidden_user_ids() );
	}

	public function test_cache_is_cleared_whenever_the_hidden_meta_changes(): void {
		$this->assertSame( array(), $this->plugin->get_hidden_user_ids() );

		$this->hide( $this->member_id );
		$this->assertSame( array( $this->member_id ), $this->plugin->get_hidden_user_ids(), 'Adding the meta' );

		update_user_meta( $this->member_id, BuddyPress_Hidden_Profiles::META_KEY, 'visible' );
		$this->assertSame( array(), $this->plugin->get_hidden_user_ids(), 'Updating the meta' );

		$this->hide( $this->member_id );
		$this->plugin->get_hidden_user_ids();
		delete_user_meta( $this->member_id, BuddyPress_Hidden_Profiles::META_KEY );
		$this->assertSame( array(), $this->plugin->get_hidden_user_ids(), 'Deleting the meta' );
	}

	public function test_cache_is_left_alone_when_other_meta_changes(): void {
		wp_cache_set( BuddyPress_Hidden_Profiles::CACHE_KEY, array( 123 ), BuddyPress_Hidden_Profiles::CACHE_GROUP );

		update_user_meta( $this->member_id, 'nickname', 'changed' );

		$this->assertSame( array( 123 ), $this->plugin->get_hidden_user_ids() );
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
		wp_cache_set( BuddyPress_Hidden_Profiles::CACHE_KEY, array( 123 ), BuddyPress_Hidden_Profiles::CACHE_GROUP );

		$event( $this->member_id );

		$this->assertFalse( wp_cache_get( BuddyPress_Hidden_Profiles::CACHE_KEY, BuddyPress_Hidden_Profiles::CACHE_GROUP ) );
	}

	public function test_clearing_the_cache_reaches_every_site_on_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Only multisite keeps a cache per site.' );
		}
		$other_site_id = self::factory()->blog->create();
		switch_to_blog( $other_site_id );
		$this->plugin->get_hidden_user_ids();
		restore_current_blog();

		$this->hide( $this->member_id );

		switch_to_blog( $other_site_id );
		$ids = $this->plugin->get_hidden_user_ids();
		restore_current_blog();
		$this->assertSame( array( $this->member_id ), $ids );
	}

	/*
	 * Profile pages.
	 */

	/**
	 * Who may see a hidden member's profile page.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function data_profile_viewers(): array {
		return array(
			'visitor'      => array( 'visitor', true ),
			'other member' => array( 'other', true ),
			'owner'        => array( 'owner', false ),
			'admin'        => array( 'admin', false ),
		);
	}

	/**
	 * @dataProvider data_profile_viewers
	 *
	 * @param string $viewer       Who is looking.
	 * @param bool   $expect_404   Whether they should get a 404.
	 */
	public function test_hidden_profile_page_is_a_404_for_others( string $viewer, bool $expect_404 ): void {
		$this->hide( $this->member_id );
		$viewers = array(
			'visitor' => 0,
			'other'   => $this->create_active_member(),
			'owner'   => $this->member_id,
			'admin'   => $this->admin_id,
		);
		wp_set_current_user( $viewers[ $viewer ] );

		$this->go_to_profile( $this->member_id );

		$this->assertSame( $this->member_id, bp_displayed_user_id(), 'The profile URL should resolve to the member.' );
		$this->assertSame( $expect_404, $this->plugin->is_hidden_profile_request() );
	}

	public function test_visible_profile_page_is_not_a_404(): void {
		$this->hide( $this->member_id );

		$this->go_to_profile( $this->admin_id );

		$this->assertSame( $this->admin_id, bp_displayed_user_id() );
		$this->assertFalse( $this->plugin->is_hidden_profile_request() );
	}

	public function test_non_profile_pages_are_not_a_404(): void {
		$this->hide( $this->member_id );

		$this->go_to( home_url( '/' ) );

		$this->assertFalse( $this->plugin->is_hidden_profile_request() );
	}

	public function test_profile_check_runs_before_buddypress_request_handlers(): void {
		$this->plugin->run();

		$priority = has_action( 'bp_template_redirect', array( $this->plugin, 'maybe_hide_profile' ) );

		$this->assertIsInt( $priority );
		$this->assertLessThan( has_action( 'bp_template_redirect', 'bp_redirect_canonical' ), $priority, 'Should run before the canonical redirect.' );
		$this->assertLessThan( has_action( 'bp_template_redirect', 'bp_actions' ), $priority, 'Should run before feeds and other actions.' );
		$this->assertLessThan( has_action( 'bp_template_redirect', 'bp_screens' ), $priority, 'Should run before screens load templates.' );
	}

	/*
	 * Member lists.
	 */

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

		$args = $this->plugin->exclude_hidden_from_query_args( array( 'exclude' => '5,6' ) );

		$this->assertSame( array( 5, 6, $this->member_id ), $args['exclude'] );
	}

	public function test_query_args_from_a_short_circuiting_filter_are_left_alone(): void {
		$this->hide( $this->member_id );
		$error = new WP_Error( 'nope' );

		$this->assertSame( $error, $this->plugin->exclude_hidden_from_query_args( $error ) );
	}

	public function test_other_user_queries_still_see_hidden_users(): void {
		$this->hide( $this->member_id );

		$this->assertContains(
			$this->member_id,
			$this->user_query_ids(),
			'Internal lookups, such as activity authors, must still find hidden users.'
		);
	}

	/*
	 * @mentions.
	 */

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

	public function test_group_mention_suggestions_exclude_hidden_users(): void {
		wp_update_user(
			array(
				'ID'         => $this->member_id,
				'user_login' => 'hpgroup-hidden',
			)
		);
		$this->hide( $this->member_id );
		list( $group_id, $visible_id, $group_admin_id ) = $this->create_group_with_members();
		wp_set_current_user( $this->create_active_member() );

		$suggestions = bp_core_get_suggestions(
			array(
				'term'     => 'hpgroup',
				'type'     => 'members',
				'group_id' => $group_id,
			)
		);

		$this->assertIsArray( $suggestions );
		$ids = array_map( 'intval', wp_list_pluck( $suggestions, 'user_id' ) );
		sort( $ids );
		$expected = array( $group_admin_id, $visible_id );
		sort( $expected );
		$this->assertSame( $expected, $ids );
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

	/*
	 * REST API.
	 */

	public function test_rest_members_list_excludes_hidden_users_for_visitors(): void {
		$this->hide( $this->member_id );

		$response = $this->rest_get( '/members' );

		$this->assertSame( 200, $response->get_status() );
		$ids = wp_list_pluck( $response->get_data(), 'id' );
		$this->assertNotContains( $this->member_id, $ids );
		$this->assertContains( $this->admin_id, $ids );
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

	/**
	 * REST routes that look up a single member.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_rest_member_routes(): array {
		return array(
			'member'        => array( '/members/%d' ),
			'avatar'        => array( '/members/%d/avatar' ),
			'cover'         => array( '/members/%d/cover' ),
			'profile field' => array( '/xprofile/1/data/%d' ),
		);
	}

	/**
	 * @dataProvider data_rest_member_routes
	 *
	 * @param string $route Route, with %d for the member ID.
	 */
	public function test_rest_hidden_member_looks_like_a_missing_member_to_visitors( string $route ): void {
		$this->hide( $this->member_id );

		$hidden  = $this->rest_get( sprintf( $route, $this->member_id ) );
		$missing = $this->rest_get( sprintf( $route, 999999999 ) );

		$this->assertSame( 404, $hidden->get_status() );
		$this->assertSame( $missing->get_status(), $hidden->get_status() );
		$this->assertSame( $missing->get_data()['code'], $hidden->get_data()['code'] );
	}

	/**
	 * @dataProvider data_rest_member_routes
	 *
	 * @param string $route Route, with %d for the member ID.
	 */
	public function test_rest_visible_member_is_unaffected( string $route ): void {
		$this->hide( $this->member_id );
		xprofile_set_field_data( 1, $this->admin_id, 'Admin' );

		$response = $this->rest_get( sprintf( $route, $this->admin_id ) );

		// A cover request can fail for want of an image, but not for want of a member.
		$this->assertNotSame( 'bp_rest_member_invalid_id', $response->get_data()['code'] ?? '' );
	}

	/**
	 * Requests where the query string names a different member from the route.
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public static function data_rest_conflicting_member_ids(): array {
		// Route, query parameter, and whether the hidden member is in the route (else in the query).
		return array(
			'member route, other user_id in query'    => array( '/members/%d', 'user_id', true ),
			'avatar route, other id in query'         => array( '/members/%d/avatar', 'id', true ),
			'avatar route, hidden user_id in query'   => array( '/members/%d/avatar', 'user_id', false ),
		);
	}

	/**
	 * WordPress lets query parameters override route parameters, so the check must
	 * read the member ID the same way the endpoint does.
	 *
	 * @dataProvider data_rest_conflicting_member_ids
	 *
	 * @param string $route           Route, with %d for the member ID.
	 * @param string $param           Query parameter naming the other member.
	 * @param bool   $hidden_in_route Whether the route names the hidden member.
	 */
	public function test_rest_hidden_member_cannot_be_reached_with_a_conflicting_id( string $route, string $param, bool $hidden_in_route ): void {
		$this->hide( $this->member_id );
		list( $route_id, $query_id ) = $hidden_in_route
			? array( $this->member_id, $this->admin_id )
			: array( $this->admin_id, $this->member_id );

		$response = $this->rest_get( sprintf( $route, $route_id ), array( $param => $query_id ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_rest_hidden_member_is_shown_to_themselves_and_admins(): void {
		$this->hide( $this->member_id );

		wp_set_current_user( $this->member_id );
		$this->assertSame( 200, $this->rest_get( '/members/' . $this->member_id )->get_status(), 'Owner' );

		wp_set_current_user( $this->admin_id );
		$this->assertSame( 200, $this->rest_get( '/members/' . $this->member_id )->get_status(), 'Admin' );
	}

	public function test_rest_member_actions_treat_hidden_members_as_missing(): void {
		$this->hide( $this->member_id );
		wp_set_current_user( $this->create_active_member() );
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'id', $this->member_id );

		// BuddyBoss's follow/unfollow endpoint; BuddyPress has no equivalent route to dispatch to.
		$result = apply_filters( 'bp_rest_members_action_update_item_permissions_check', true, $request );

		$this->assertWPError( $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/*
	 * Groups.
	 */

	public function test_group_member_list_excludes_hidden_users_for_visitors(): void {
		$this->hide( $this->member_id );
		list( $group_id, $visible_id ) = $this->create_group_with_members();

		$ids = $this->group_members_loop_ids( $group_id );

		$this->assertNotContains( $this->member_id, $ids );
		$this->assertContains( $visible_id, $ids );
	}

	/**
	 * Group roles that can manage members.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_group_managers(): array {
		return array(
			'admin'     => array( 'admin' ),
			'moderator' => array( 'mod' ),
		);
	}

	/**
	 * @dataProvider data_group_managers
	 *
	 * @param string $role Group role.
	 */
	public function test_group_member_list_shows_hidden_users_to_group_managers( string $role ): void {
		$this->hide( $this->member_id );
		list( $group_id ) = $this->create_group_with_members();
		$manager_id       = $this->create_active_member();
		groups_join_group( $group_id, $manager_id );
		( new BP_Groups_Member( $manager_id, $group_id ) )->promote( $role );
		wp_set_current_user( $manager_id );

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

	public function test_rest_group_member_list_shows_hidden_users_to_group_admins(): void {
		$this->hide( $this->member_id );
		list( $group_id, , $group_admin_id ) = $this->create_group_with_members();
		wp_set_current_user( $group_admin_id );

		$ids = wp_list_pluck( $this->rest_get( '/groups/' . $group_id . '/members' )->get_data(), 'id' );

		$this->assertContains( $this->member_id, $ids );
	}

	public function test_group_invite_list_excludes_hidden_users_even_for_group_admins(): void {
		$this->hide( $this->member_id );
		$group_admin_id = $this->create_active_member();
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
		$query = new BP_Nouveau_Group_Invite_Query(
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
		$this->assertContains( $this->admin_id, $ids );
	}

	/*
	 * Profile screen setting.
	 */

	public function test_admin_can_hide_and_unhide_another_profile(): void {
		wp_set_current_user( $this->admin_id );

		$this->submit_profile( 'edit_user_profile_update', $this->member_id, true );
		$this->assertTrue( $this->plugin->is_hidden( $this->member_id ), 'Ticking the box should hide the profile.' );

		$this->submit_profile( 'edit_user_profile_update', $this->member_id, false );
		$this->assertFalse( $this->plugin->is_hidden( $this->member_id ), 'Unticking the box should reveal the profile.' );
	}

	public function test_admin_can_hide_their_own_profile(): void {
		wp_set_current_user( $this->admin_id );

		$this->submit_profile( 'personal_options_update', $this->admin_id, true );

		$this->assertTrue( $this->plugin->is_hidden( $this->admin_id ) );
	}

	public function test_saving_updates_member_lists_straight_away(): void {
		wp_set_current_user( $this->admin_id );
		$this->plugin->get_hidden_user_ids();

		$this->submit_profile( 'edit_user_profile_update', $this->member_id, true );

		$this->assertSame( array( $this->member_id ), $this->plugin->get_hidden_user_ids() );
	}

	public function test_save_without_a_valid_nonce_changes_nothing(): void {
		wp_set_current_user( $this->admin_id );

		$this->submit_profile( 'edit_user_profile_update', $this->member_id, true, false );

		$this->assertFalse( $this->plugin->is_hidden( $this->member_id ) );
	}

	public function test_non_admin_cannot_hide_a_profile(): void {
		wp_set_current_user( $this->member_id );

		$this->submit_profile( 'personal_options_update', $this->member_id, true );

		$this->assertFalse( $this->plugin->is_hidden( $this->member_id ) );
	}

	public function test_setting_is_not_shown_to_non_admins(): void {
		wp_set_current_user( $this->member_id );

		ob_start();
		do_action( 'show_user_profile', get_userdata( $this->member_id ) );

		$this->assertStringNotContainsString( 'buddypress_hidden_profiles_nonce', (string) ob_get_clean() );
	}

	/**
	 * Whether the checkbox starts ticked.
	 *
	 * @return array<string, array{bool}>
	 */
	public static function data_hidden_states(): array {
		return array(
			'hidden'  => array( true ),
			'visible' => array( false ),
		);
	}

	/**
	 * @dataProvider data_hidden_states
	 *
	 * @param bool $hidden Whether the user is hidden.
	 */
	public function test_setting_form_shows_the_current_value_and_can_be_submitted( bool $hidden ): void {
		if ( $hidden ) {
			$this->hide( $this->member_id );
		}
		wp_set_current_user( $this->admin_id );

		$tags = new WP_HTML_Tag_Processor( $this->render_profile_section( $this->member_id ) );

		$this->assertTrue( $tags->next_tag( 'label' ) );
		$label_for = $tags->get_attribute( 'for' );
		$this->assertTrue( $tags->next_tag( 'input' ) );
		$this->assertSame( 'checkbox', $tags->get_attribute( 'type' ) );
		$this->assertSame( BuddyPress_Hidden_Profiles::META_KEY, $tags->get_attribute( 'name' ) );
		$this->assertSame( $label_for, $tags->get_attribute( 'id' ), 'The label should be associated with the checkbox.' );
		$this->assertSame( $hidden, null !== $tags->get_attribute( 'checked' ) );
		$this->assertTrue( $tags->next_tag( 'input' ) );
		$this->assertSame( 'buddypress_hidden_profiles_nonce', $tags->get_attribute( 'name' ) );
		$this->assertSame( 1, wp_verify_nonce( $tags->get_attribute( 'value' ), 'buddypress_hidden_profiles_visibility' ) );
	}
}
