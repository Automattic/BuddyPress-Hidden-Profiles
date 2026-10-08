<?php
/**
 * Integration tests for the profile screen setting.
 *
 * @package BuddyPress-Hidden-Profiles
 */

declare( strict_types=1 );

namespace Automattic\BuddyPressHiddenProfiles\Tests\Integration;

use Automattic\BuddyPressHiddenProfiles\Hidden_Users;
use WP_HTML_Tag_Processor;

/**
 * Covers the Hidden Profile checkbox on profile screens.
 *
 * @covers \Automattic\BuddyPressHiddenProfiles\Profile_Setting
 */
final class ProfileSettingTest extends TestCase {

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
			$_POST[ Hidden_Users::META_KEY ] = Hidden_Users::META_HIDDEN_VALUE;
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

	public function test_admin_can_hide_and_unhide_another_profile(): void {
		wp_set_current_user( $this->admin_id );

		$this->submit_profile( 'edit_user_profile_update', $this->member_id, true );
		$this->assertTrue( $this->hidden_users->is_hidden( $this->member_id ), 'Ticking the box should hide the profile.' );

		$this->submit_profile( 'edit_user_profile_update', $this->member_id, false );
		$this->assertFalse( $this->hidden_users->is_hidden( $this->member_id ), 'Unticking the box should reveal the profile.' );
	}

	public function test_admin_can_hide_their_own_profile(): void {
		wp_set_current_user( $this->admin_id );

		$this->submit_profile( 'personal_options_update', $this->admin_id, true );

		$this->assertTrue( $this->hidden_users->is_hidden( $this->admin_id ) );
	}

	public function test_saving_updates_member_lists_straight_away(): void {
		wp_set_current_user( $this->admin_id );
		$this->hidden_users->get_hidden_user_ids();

		$this->submit_profile( 'edit_user_profile_update', $this->member_id, true );

		$this->assertSame( array( $this->member_id ), $this->hidden_users->get_hidden_user_ids() );
	}

	public function test_save_without_a_valid_nonce_changes_nothing(): void {
		wp_set_current_user( $this->admin_id );

		$this->submit_profile( 'edit_user_profile_update', $this->member_id, true, false );

		$this->assertFalse( $this->hidden_users->is_hidden( $this->member_id ) );
	}

	public function test_non_admin_cannot_hide_a_profile(): void {
		wp_set_current_user( $this->member_id );

		$this->submit_profile( 'personal_options_update', $this->member_id, true );

		$this->assertFalse( $this->hidden_users->is_hidden( $this->member_id ) );
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
		$this->assertSame( Hidden_Users::META_KEY, $tags->get_attribute( 'name' ) );
		$this->assertSame( $label_for, $tags->get_attribute( 'id' ), 'The label should be associated with the checkbox.' );
		$this->assertSame( $hidden, null !== $tags->get_attribute( 'checked' ) );
		$this->assertTrue( $tags->next_tag( 'input' ) );
		$this->assertSame( 'buddypress_hidden_profiles_nonce', $tags->get_attribute( 'name' ) );
		$this->assertSame( 1, wp_verify_nonce( $tags->get_attribute( 'value' ), 'buddypress_hidden_profiles_visibility' ) );
	}
}
