<?php
/**
 * Integration tests for groups.
 *
 * @package BuddyPress-Hidden-Profiles
 */

declare( strict_types=1 );

namespace Automattic\BuddyPressHiddenProfiles\Tests\Integration;

use BP_Groups_Member;
use BP_Nouveau_Group_Invite_Query;

/**
 * Covers group member lists, group @mentions and invite lists.
 *
 * @covers \Automattic\BuddyPressHiddenProfiles\Visibility
 */
final class GroupsTest extends TestCase {

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
}
