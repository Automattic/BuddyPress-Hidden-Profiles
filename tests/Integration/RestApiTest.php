<?php
/**
 * Integration tests for the REST API.
 *
 * @package BuddyPress-Hidden-Profiles
 */

declare( strict_types=1 );

namespace Automattic\BuddyPressHiddenProfiles\Tests\Integration;

use WP_REST_Request;

/**
 * Covers the members list and the routes that look up a single member.
 *
 * @covers \Automattic\BuddyPressHiddenProfiles\Visibility
 */
final class RestApiTest extends TestCase {

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
}
