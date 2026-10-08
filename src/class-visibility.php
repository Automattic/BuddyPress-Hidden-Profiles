<?php
/**
 * Visibility class.
 *
 * @package BuddyPress-Hidden-Profiles
 */

namespace Automattic\BuddyPressHiddenProfiles;

/**
 * Keeps hidden users out of what BuddyPress shows: profile pages, member lists and the REST API.
 */
class Visibility {

	/**
	 * Constructor.
	 *
	 * @param Hidden_Users $hidden_users Who is hidden.
	 */
	public function __construct( private Hidden_Users $hidden_users ) {
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		// 1) 404 direct profile URLs. Priority 1 runs ahead of BuddyPress's
		// bp_actions (4) and bp_screens (6), whose handlers, such as activity
		// feeds, can print a response and exit before a later check runs.
		add_action( 'bp_template_redirect', array( $this, 'maybe_hide_profile' ), 1 );

		// 2) Exclude from member queries that reach non-admins. These are hooked at the
		// output boundaries rather than BP_User_Query itself, because that class also
		// backs internal work (activity author lookups, background friend counts) that
		// must still see every user.
		add_filter( 'bp_after_has_members_parse_args', array( $this, 'exclude_hidden_from_query_args' ) );
		add_filter( 'bp_members_suggestions_query_args', array( $this, 'exclude_hidden_from_query_args' ) );
		add_filter( 'bp_groups_member_suggestions_query_args', array( $this, 'exclude_hidden_from_query_args' ) );
		add_filter( 'bp_rest_members_get_items_query_args', array( $this, 'exclude_hidden_from_query_args' ) );
		add_filter( 'bp_rest_members_get_item_permissions_check', array( $this, 'rest_hide_member' ), 10, 2 );
		add_filter( 'bp_after_group_has_members_parse_args', array( $this, 'exclude_hidden_from_group_member_args' ) );
		add_filter( 'bp_rest_group_members_get_items_query_args', array( $this, 'exclude_hidden_from_group_member_args' ) );
		add_filter( 'bp_rest_members_action_update_item_permissions_check', array( $this, 'rest_hide_member' ), 10, 2 );
		add_filter( 'bp_rest_attachments_member_avatar_get_item_permissions_check', array( $this, 'rest_hide_member' ), 10, 2 );
		add_filter( 'bp_rest_attachments_member_cover_get_item_permissions_check', array( $this, 'rest_hide_member' ), 10, 2 );
		add_filter( 'bp_rest_xprofile_data_get_item_permissions_check', array( $this, 'rest_hide_member' ), 10, 2 );
		add_action( 'bp_pre_user_query_construct', array( $this, 'exclude_hidden_from_unfiltered_lists' ), 20 );
	}

	/**
	 * Maybe hide the profile.
	 *
	 * Respond to the request with a 404 status code if the user should be hidden.
	 *
	 * Profiles are not hidden for the user themselves, or for admins.
	 *
	 * @return void
	 */
	public function maybe_hide_profile() {
		if ( ! $this->is_hidden_profile_request() ) {
			return;
		}
		status_header( 404 );
		nocache_headers();
		include get_404_template();
		exit;
	}

	/**
	 * Whether the current request is for a profile the current user may not see.
	 *
	 * @return bool True if the request should get a 404.
	 */
	public function is_hidden_profile_request() {
		return function_exists( 'bp_is_user' ) && bp_is_user() && ! $this->hidden_users->current_user_can_view( bp_displayed_user_id() );
	}

	/**
	 * Exclude hidden users from a set of BP_User_Query arguments.
	 *
	 * Used for member loops (directories, widgets, friends lists), @mention
	 * suggestions and the REST members collection, which all pass the same
	 * argument shape through to BP_User_Query.
	 *
	 * @param array|mixed $args BP_User_Query arguments.
	 * @return array|mixed The arguments with hidden users excluded.
	 */
	public function exclude_hidden_from_query_args( $args ) {
		// Another filter may have short-circuited with a WP_Error.
		if ( ! is_array( $args ) ) {
			return $args;
		}

		$hidden = $this->hidden_users->get_hidden_user_ids_for_current_user();
		if ( ! $hidden ) {
			return $args;
		}

		// BP_User_Query skips its SQL, and so ignores 'exclude', when 'user_ids' is set.
		if ( ! empty( $args['user_ids'] ) ) {
			$args['user_ids'] = array_values( array_diff( wp_parse_id_list( $args['user_ids'] ), $hidden ) );
		}

		// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
		$args['exclude'] = array_merge( wp_parse_id_list( $args['exclude'] ?? array() ), $hidden );

		return $args;
	}

	/**
	 * Exclude hidden users from a group's member list.
	 *
	 * The group's own admins and moderators still see everyone, so that they can
	 * manage, promote or remove hidden members.
	 *
	 * @param array|mixed $args BP_Group_Member_Query arguments.
	 * @return array|mixed The arguments with hidden users excluded.
	 */
	public function exclude_hidden_from_group_member_args( $args ) {
		$group_id = is_array( $args ) && is_numeric( $args['group_id'] ?? null ) ? (int) $args['group_id'] : 0;
		$user_id  = get_current_user_id();

		if ( $group_id && $user_id && ( groups_is_user_admin( $user_id, $group_id ) || groups_is_user_mod( $user_id, $group_id ) ) ) {
			return $args;
		}

		return $this->exclude_hidden_from_query_args( $args );
	}

	/**
	 * Exclude hidden users from member lists that offer no arguments filter.
	 *
	 * Covers the @mention suggestions primed on page load and the lists of people
	 * who could be invited to a group. Every other BP_User_Query is left alone.
	 *
	 * Group admins get no exemption here, unlike for their group's member list:
	 * anyone can create a group, so an exemption would let anyone list hidden users.
	 *
	 * Runs at priority 20 because BP_Nouveau_Group_Invite_Query replaces 'exclude'
	 * at priority 10.
	 *
	 * @param \BP_User_Query $query The query, before it runs.
	 */
	public function exclude_hidden_from_unfiltered_lists( $query ) {
		if ( $query instanceof \BP_Nouveau_Group_Invite_Query
			|| doing_action( 'bp_activity_mentions_prime_results' )
			|| doing_action( 'bbp_forums_mentions_prime_results' )
		) {
			$query->query_vars = $this->exclude_hidden_from_query_args( $query->query_vars );
		}
	}

	/**
	 * Respond to REST requests for a single hidden member as if they don't exist.
	 *
	 * Covers the member itself and routes about them, such as their avatar,
	 * which name the member 'user_id' rather than 'id'.
	 *
	 * @param true|\WP_Error   $retval  The permission check result so far.
	 * @param \WP_REST_Request $request The REST request.
	 * @return true|\WP_Error The permission check result.
	 */
	public function rest_hide_member( $retval, $request ) {
		// Use the name the route gives the member, but read it as the endpoint does:
		// a query parameter of the same name overrides the route's value.
		$param = array_key_exists( 'user_id', $request->get_url_params() ) ? 'user_id' : 'id';

		if ( true !== $retval || $this->hidden_users->current_user_can_view( (int) $request->get_param( $param ) ) ) {
			return $retval;
		}

		// Match the BuddyPress/BuddyBoss response for a non-existent member.
		return new \WP_Error(
			'bp_rest_member_invalid_id',
			__( 'Invalid member ID.', 'buddypress-hidden-profiles' ),
			array( 'status' => 404 )
		);
	}
}
