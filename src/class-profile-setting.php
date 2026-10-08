<?php
/**
 * Profile setting class.
 *
 * @package BuddyPress-Hidden-Profiles
 */

namespace Automattic\BuddyPressHiddenProfiles;

/**
 * The Hidden Profile checkbox on user profile screens, for admins.
 */
class Profile_Setting {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'show_user_profile', array( $this, 'visibility_setting_ui' ) );
		add_action( 'edit_user_profile', array( $this, 'visibility_setting_ui' ) );
		add_action( 'personal_options_update', array( $this, 'save_visibility_setting' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_visibility_setting' ) );
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
		$value = get_user_meta( $user->ID, Hidden_Users::META_KEY, true );
		?>
		<h2><?php esc_html_e( 'Profile Visibility', 'buddypress-hidden-profiles' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="buddypress-hidden-profiles-visibility"><?php esc_html_e( 'Hidden Profile', 'buddypress-hidden-profiles' ); ?></label></th>
				<td>
					<input type="checkbox"
							id="buddypress-hidden-profiles-visibility"
							name="<?php echo esc_attr( Hidden_Users::META_KEY ); ?>"
							value="<?php echo esc_attr( Hidden_Users::META_HIDDEN_VALUE ); ?>"
							<?php checked( $value, Hidden_Users::META_HIDDEN_VALUE ); ?> />
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
	 * The hidden users cache clears itself when the meta changes.
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
		if ( isset( $_POST[ Hidden_Users::META_KEY ] ) ) {
			update_user_meta( $user_id, Hidden_Users::META_KEY, Hidden_Users::META_HIDDEN_VALUE );
		} else {
			delete_user_meta( $user_id, Hidden_Users::META_KEY );
		}
	}
}
