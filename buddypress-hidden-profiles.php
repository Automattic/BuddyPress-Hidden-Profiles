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
 * Version:           1.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            WordPress VIP
 * Text Domain:       buddypress-hidden-profiles
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace Automattic\BuddyPressHiddenProfiles;

defined( 'ABSPATH' ) || exit;

/*
 * While there's a UI checkbox to hide a profile, this plugin also uses a meta key to hide profiles from non-admins.
 * This is because the UI may not allow access to edit the profile.
 * Use WP-CLI instead:
 *     wp user meta update 1 profile_visibility hidden
 *     wp cache delete bp_hidden_user_ids
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/src/class-buddypress-hidden-profiles.php';

\add_action(
	'bp_loaded',
	function () {
		$manager = new BuddyPress_Hidden_Profiles();
		$manager->run();
	} 
);
