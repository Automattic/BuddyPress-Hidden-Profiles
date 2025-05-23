<?php
/**
 * BuddyPress Hidden Profiles
 *
 * @package           BuddyPress-Hidden-Profiles
 * @author            WordPress VIP
 * @copyright         2025-onwards Shared and distributed between contributors.
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       BuddyPress Hidden Profiles
 * Description:       Allows site admins to mark BuddyPress user profiles as hidden, excluding them from appearing in directories, searches, and making their profile pages return a 404 for non-admins.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            WordPress VIP
 * Text Domain:       buddypress-hidden-profiles
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
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

		// 2) AJAX exclusion for *all* directory loads
		add_filter( 'bp_ajax_querystring', array( $this, 'ajax_exclude_hidden' ), 20, 2 );

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
		if ( ! $uid
			|| user_can( bp_loggedin_user_id(), 'manage_options' )
			|| get_current_user_id() === $uid
			|| ! $this->is_hidden( $uid )
		) {
			return;
		}
		status_header( 404 );
		nocache_headers();
		include get_404_template();
		exit;
	}

	/**
	 * Exclude hidden users from AJAX requests.
	 *
	 * @param string $qs          The query string.
	 * @param string $object_type The object type.
	 * @return string The modified query string.
	 */
	public function ajax_exclude_hidden( $qs, $object_type ) {
		if ( 'members' !== $object_type
			|| user_can( bp_loggedin_user_id(), 'manage_options' )
		) {
			return $qs;
		}

		$args = wp_parse_args( $qs );

		// Get all hidden user IDs.
		$hidden = $this->get_hidden_user_ids();
		if ( $hidden ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
			$args['exclude'] = array_merge( (array) ( $args['exclude'] ?? array() ), $hidden );
		}

		return http_build_query( $args );
	}

	/**
	 * Display the visibility setting UI.
	 *
	 * @param object $user The user object.
	 */
	public function visibility_setting_ui( $user ) {
		if ( ! user_can( bp_loggedin_user_id(), 'manage_options' ) ) {
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
		if ( ! user_can( bp_loggedin_user_id(), 'manage_options' ) ) {
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
