<?php
/**
 * BuddyPress Hidden Profiles class.
 *
 * @package BuddyPress-Hidden-Profiles
 */

namespace Automattic\BuddyPressHiddenProfiles;

/**
 * BuddyPress Hidden Profiles.
 */
class BuddyPress_Hidden_Profiles {
	const META_KEY          = 'profile_visibility';
	const META_HIDDEN_VALUE = 'hidden';

	/**
	 * Run the plugin.
	 *
	 * @return void
	 */
	public function run() {
		// 1) 404 direct profile URLs
		add_action( 'bp_template_redirect', array( $this, 'maybe_hide_profile' ) );

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
		add_action( 'bp_pre_user_query_construct', array( $this, 'exclude_hidden_from_unfiltered_lists' ), 20 );

		// 3) Admin UI on profile screens
		add_action( 'show_user_profile', array( $this, 'visibility_setting_ui' ) );
		add_action( 'edit_user_profile', array( $this, 'visibility_setting_ui' ) );
		add_action( 'personal_options_update', array( $this, 'save_visibility_setting' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_visibility_setting' ) );

		// 4) Clear cache when users are added/removed.
		add_action( 'set_user_role', array( $this, 'clear_hidden_cache' ) );
		add_action( 'delete_user', array( $this, 'clear_hidden_cache' ) );
		add_action( 'user_register', array( $this, 'clear_hidden_cache' ) );
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
		if ( ! function_exists( 'bp_is_user' ) || ! bp_is_user() ) {
			return;
		}
		$uid = bp_displayed_user_id();
		if ( ! $uid || $this->current_user_can_view( $uid ) ) {
			return;
		}
		status_header( 404 );
		nocache_headers();
		include get_404_template();
		exit;
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

		$hidden = $this->get_hidden_user_ids_for_current_user();
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
	 * @param true|\WP_Error   $retval  The permission check result so far.
	 * @param \WP_REST_Request $request The REST request.
	 * @return true|\WP_Error The permission check result.
	 */
	public function rest_hide_member( $retval, $request ) {
		if ( true !== $retval || $this->current_user_can_view( (int) $request['id'] ) ) {
			return $retval;
		}

		// Match the BuddyPress/BuddyBoss response for a non-existent member.
		return new \WP_Error(
			'bp_rest_member_invalid_id',
			__( 'Invalid member ID.', 'buddypress-hidden-profiles' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Whether the current user may see a given user's profile.
	 *
	 * @param int $user_id The user ID.
	 * @return bool True if the current user is an admin, the user themselves, or the user isn't hidden.
	 */
	public function current_user_can_view( $user_id ) {
		return current_user_can( 'manage_options' )
			|| get_current_user_id() === $user_id
			|| ! $this->is_hidden( $user_id );
	}

	/**
	 * Get the IDs of hidden users that the current user should not see.
	 *
	 * @return int[] Hidden user IDs, excluding the current user. Empty for admins.
	 */
	private function get_hidden_user_ids_for_current_user() {
		if ( current_user_can( 'manage_options' ) ) {
			return array();
		}

		return array_values( array_diff( wp_parse_id_list( $this->get_hidden_user_ids() ), array( get_current_user_id() ) ) );
	}

	/**
	 * Display the visibility setting UI.
	 *
	 * @param object $user The user object.
	 */
	public function visibility_setting_ui( $user ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$value = get_user_meta( $user->ID, self::META_KEY, true );
		?>
		<h3><?php esc_html_e( 'Profile Visibility', 'buddypress-hidden-profiles' ); ?></h3>
		<table class="form-table">
			<tr>
				<th><label for="<?php echo esc_attr( self::META_KEY ); ?>"><?php esc_html_e( 'Hidden Profile', 'buddypress-hidden-profiles' ); ?></label></th>
				<td>
					<input type="checkbox"
							name="<?php echo esc_attr( self::META_KEY ); ?>"
							value="<?php echo esc_attr( self::META_HIDDEN_VALUE ); ?>"
							<?php checked( $value, self::META_HIDDEN_VALUE ); ?> />
					<span class="description"><?php esc_html_e( 'Hide this profile from non-admins.', 'buddypress-hidden-profiles' ); ?></span>
					<?php wp_nonce_field( 'buddypress_hidden_profiles_visibility', 'buddypress_hidden_profiles_nonce' ); ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the visibility setting for a user.
	 *
	 * @param int $user_id The user ID.
	 */
	public function save_visibility_setting( $user_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_POST['buddypress_hidden_profiles_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( $_POST['buddypress_hidden_profiles_nonce'] ), 'buddypress_hidden_profiles_visibility' ) ) {
			return;
		}
		if ( isset( $_POST[ self::META_KEY ] ) ) {
			update_user_meta( $user_id, self::META_KEY, self::META_HIDDEN_VALUE );
		} else {
			delete_user_meta( $user_id, self::META_KEY );
		}

		// Clear the cache when a user's visibility changes.
		$this->clear_hidden_cache();
	}

	/**
	 * Clear the hidden users cache.
	 */
	public function clear_hidden_cache() {
		wp_cache_delete( 'bp_hidden_user_ids' );
	}

	/**
	 * Get the IDs of hidden users.
	 *
	 * @return array The IDs of hidden users.
	 */
	public function get_hidden_user_ids() {
		global $wpdb;

		// Try to get from cache first.
		$cache_key  = 'bp_hidden_user_ids';
		$hidden_ids = wp_cache_get( $cache_key );

		if ( false === $hidden_ids ) {
			// Get users with the meta key set.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$meta_hidden = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->usermeta}
					WHERE meta_key = %s AND meta_value = %s",
					self::META_KEY,
					self::META_HIDDEN_VALUE
				)
			);

			/**
			 * Filter the list of hidden user IDs.
			 *
			 * This filter allows other code to add user IDs to the list of hidden users.
			 * The IDs should be determined by a performant query, as this is used in
			 * directory listings and other high-traffic areas.
			 *
			 * @since 1.0.0
			 *
			 * @param array $additional_hidden Array of additional user IDs to hide.
			 */
			$additional_hidden = apply_filters( 'buddypress_hidden_profiles_additional_hidden_ids', array() );

			// Merge the arrays and remove duplicates.
			$hidden_ids = array_unique( array_merge( $meta_hidden, $additional_hidden ) );

			// Cache for 1 day - we clear the cache on user changes.
			wp_cache_set( $cache_key, $hidden_ids, '', DAY_IN_SECONDS );
		}

		return $hidden_ids;
	}

	/**
	 * Check if a user is hidden.
	 *
	 * @param int $user_id The user ID.
	 * @return bool True if the user is hidden, false otherwise.
	 */
	public function is_hidden( $user_id ) {
		/**
		 * Filter whether a user's profile should be hidden.
		 *
		 * This filter allows other code to determine if a profile should be hidden,
		 * overriding the default meta-based check. Return true to hide the profile,
		 * false to show it, or null to fall back to the default meta check.
		 *
		 * @since 1.0.0
		 *
		 * @param bool|null $is_hidden Whether the profile should be hidden. Null to use default check.
		 * @param int       $user_id   The user ID to check.
		 */
		$is_hidden = apply_filters( 'buddypress_hidden_profiles_is_hidden', null, $user_id );

		// If the filter returns a boolean, use that value.
		if ( is_bool( $is_hidden ) ) {
			return $is_hidden;
		}

		// Otherwise fall back to the default meta check.
		return get_user_meta( $user_id, self::META_KEY, true ) === self::META_HIDDEN_VALUE;
	}
}
