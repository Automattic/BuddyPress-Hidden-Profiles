<?php
/**
 * Base class for the integration tests.
 *
 * @package BuddyPress-Hidden-Profiles
 */

declare( strict_types=1 );

namespace Automattic\BuddyPressHiddenProfiles\Tests\Integration;

use Automattic\BuddyPressHiddenProfiles\Hidden_Users;
use Automattic\BuddyPressHiddenProfiles\Visibility;
use WP_REST_Request;
use WP_REST_Response;
use Yoast\WPTestUtils\WPIntegration\TestCase as YoastTestCase;

/**
 * Shared fixtures: an admin, a member most tests hide, and an empty cache.
 *
 * Hooked behaviour runs through the instances the plugin creates on bp_loaded.
 * $hidden_users and $visibility are second instances for calling methods
 * directly; they share state only through user meta and the object cache.
 */
abstract class TestCase extends YoastTestCase {

	/**
	 * Who is hidden.
	 *
	 * @var Hidden_Users
	 */
	protected $hidden_users;

	/**
	 * What hidden users are kept out of.
	 *
	 * @var Visibility
	 */
	protected $visibility;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Subscriber user ID, which most tests hide.
	 *
	 * @var int
	 */
	protected $member_id;

	/**
	 * Set up fresh plugin instances, active users and an empty cache.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->hidden_users = new Hidden_Users();
		$this->visibility   = new Visibility( $this->hidden_users );
		$this->admin_id     = $this->create_active_member( array( 'role' => 'administrator' ) );
		$this->member_id    = $this->create_active_member();

		$this->hidden_users->clear_hidden_cache();
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
	protected function hide( int $user_id ): void {
		update_user_meta( $user_id, Hidden_Users::META_KEY, Hidden_Users::META_HIDDEN_VALUE );
	}

	/**
	 * Create a member who shows up in "active" member lists.
	 *
	 * BuddyPress member lists only include users with a last activity date.
	 *
	 * @param array $args Optional user arguments.
	 * @return int User ID.
	 */
	protected function create_active_member( array $args = array() ): int {
		$user_id = self::factory()->user->create( $args );
		bp_update_user_last_activity( $user_id );

		return $user_id;
	}

	/**
	 * Run a members loop, as the directory does, and return the user IDs it found.
	 *
	 * @param array $args bp_has_members() arguments.
	 * @return int[] User IDs.
	 */
	protected function members_loop_ids( array $args = array() ): array {
		bp_has_members( $args + array( 'per_page' => 100 ) );

		return array_map( 'intval', wp_list_pluck( $GLOBALS['members_template']->members, 'ID' ) );
	}

	/**
	 * Send a GET request to the BuddyPress REST API.
	 *
	 * @param string $route  Route after the namespace, e.g. '/members'.
	 * @param array  $params Query parameters.
	 * @return WP_REST_Response
	 */
	protected function rest_get( string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/' . bp_rest_namespace() . '/' . bp_rest_version() . $route );
		$request->set_query_params( $params );

		return rest_get_server()->dispatch( $request );
	}
}
