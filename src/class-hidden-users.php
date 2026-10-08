<?php
/**
 * Hidden users class.
 *
 * @package BuddyPress-Hidden-Profiles
 */

namespace Automattic\BuddyPressHiddenProfiles;

/**
 * Who is hidden, and who may see them.
 *
 * The single source of truth that member lists and profile checks both read.
 */
class Hidden_Users {
	const META_KEY          = 'profile_visibility';
	const META_HIDDEN_VALUE = 'hidden';
	const CACHE_KEY         = 'bp_hidden_user_ids';
	const CACHE_GROUP       = 'buddypress_hidden_profiles';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		// User meta is network-wide, so the hidden list must be too.
		wp_cache_add_global_groups( self::CACHE_GROUP );

		// Clear cache when who is hidden may have changed.
		add_action( 'added_user_meta', array( $this, 'maybe_clear_hidden_cache' ), 10, 3 );
		add_action( 'updated_user_meta', array( $this, 'maybe_clear_hidden_cache' ), 10, 3 );
		add_action( 'deleted_user_meta', array( $this, 'maybe_clear_hidden_cache' ), 10, 3 );
		add_action( 'set_user_role', array( $this, 'clear_hidden_cache' ) );
		add_action( 'delete_user', array( $this, 'clear_hidden_cache' ) );
		add_action( 'user_register', array( $this, 'clear_hidden_cache' ) );
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
	public function get_hidden_user_ids_for_current_user() {
		if ( current_user_can( 'manage_options' ) ) {
			return array();
		}

		return array_values( array_diff( $this->get_hidden_user_ids(), array( get_current_user_id() ) ) );
	}

	/**
	 * Clear the hidden users cache.
	 */
	public function clear_hidden_cache() {
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
	}

	/**
	 * Clear the hidden users cache when a user's visibility meta changes.
	 *
	 * This covers the profile screen, WP-CLI and any other code that changes the meta.
	 *
	 * @param int|int[] $meta_ids Meta ID(s), unused.
	 * @param int       $user_id  User ID, unused.
	 * @param string    $meta_key Meta key.
	 */
	public function maybe_clear_hidden_cache( $meta_ids, $user_id, $meta_key ) {
		if ( self::META_KEY === $meta_key ) {
			$this->clear_hidden_cache();
		}
	}

	/**
	 * Get the IDs of hidden users.
	 *
	 * @return int[] The IDs of hidden users.
	 */
	public function get_hidden_user_ids() {
		global $wpdb;

		// Try to get from cache first.
		$hidden_ids = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );

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

			// Merge the arrays and remove duplicates. A callback may not return an array.
			$hidden_ids = array_values( wp_parse_id_list( array_merge( $meta_hidden, (array) $additional_hidden ) ) );

			// Cache for 1 day - we clear the cache on user changes.
			wp_cache_set( self::CACHE_KEY, $hidden_ids, self::CACHE_GROUP, DAY_IN_SECONDS );
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
		 * This filter allows other code to decide whether a single profile is hidden,
		 * for its profile page and REST requests for it by ID. It doesn't affect
		 * member lists: use buddypress_hidden_profiles_additional_hidden_ids to hide
		 * a user everywhere. Return true to hide the profile, false to show it, or
		 * null to fall back to the list of hidden user IDs.
		 *
		 * @since 1.0.0
		 *
		 * @param bool|null $is_hidden Whether the profile should be hidden. Null to use the hidden list.
		 * @param int       $user_id   The user ID to check.
		 */
		$is_hidden = apply_filters( 'buddypress_hidden_profiles_is_hidden', null, $user_id );

		// If the filter returns a boolean, use that value.
		if ( is_bool( $is_hidden ) ) {
			return $is_hidden;
		}

		// Otherwise fall back to the same list that member lists use.
		return in_array( (int) $user_id, $this->get_hidden_user_ids(), true );
	}
}
