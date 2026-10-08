<?php
/**
 * Integration tests for hidden profile pages.
 *
 * @package BuddyPress-Hidden-Profiles
 */

declare( strict_types=1 );

namespace Automattic\BuddyPressHiddenProfiles\Tests\Integration;

/**
 * Covers the 404 for hidden members' profile pages.
 *
 * @covers \Automattic\BuddyPressHiddenProfiles\Visibility
 */
final class ProfilePageTest extends TestCase {

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
		$this->assertSame( $expect_404, $this->visibility->is_hidden_profile_request() );
	}

	public function test_visible_profile_page_is_not_a_404(): void {
		$this->hide( $this->member_id );

		$this->go_to_profile( $this->admin_id );

		$this->assertSame( $this->admin_id, bp_displayed_user_id() );
		$this->assertFalse( $this->visibility->is_hidden_profile_request() );
	}

	public function test_non_profile_pages_are_not_a_404(): void {
		$this->hide( $this->member_id );

		$this->go_to( home_url( '/' ) );

		$this->assertFalse( $this->visibility->is_hidden_profile_request() );
	}

	public function test_profile_check_runs_before_buddypress_request_handlers(): void {
		$this->visibility->register_hooks();

		$priority = has_action( 'bp_template_redirect', array( $this->visibility, 'maybe_hide_profile' ) );

		$this->assertIsInt( $priority );
		$this->assertLessThan( has_action( 'bp_template_redirect', 'bp_redirect_canonical' ), $priority, 'Should run before the canonical redirect.' );
		$this->assertLessThan( has_action( 'bp_template_redirect', 'bp_actions' ), $priority, 'Should run before feeds and other actions.' );
		$this->assertLessThan( has_action( 'bp_template_redirect', 'bp_screens' ), $priority, 'Should run before screens load templates.' );
	}
}
