<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package BuddyPress-Hidden-Profiles
 */

declare( strict_types=1 );

namespace Automattic\BuddyPressHiddenProfiles\Tests\Integration;

use Yoast\WPTestUtils\WPIntegration;

require_once dirname( __DIR__, 2 ) . '/vendor/yoast/wp-test-utils/src/WPIntegration/bootstrap-functions.php';

$_tests_dir = WPIntegration\get_path_to_wp_test_dir();

if ( empty( $_tests_dir ) ) {
	echo 'ERROR: Could not find WordPress test library directory.' . PHP_EOL;
	echo 'Make sure wp-env is running: npx wp-env start' . PHP_EOL;
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/*
 * Load BuddyPress first: the plugin hooks into bp_loaded, which BuddyPress
 * fires on plugins_loaded. wp-env installs it alongside this plugin.
 */
// Only the core components are on by default, but the plugin also hides members from these.
\tests_add_filter(
	'bp_active_components',
	fn( $components ) => array_merge(
		(array) $components,
		array(
			'activity' => 1,
			'friends'  => 1,
			'groups'   => 1,
			'xprofile' => 1,
		)
	)
);

\tests_add_filter(
	'muplugins_loaded',
	function (): void {
		require WP_PLUGIN_DIR . '/buddypress/bp-loader.php';
		require dirname( __DIR__, 2 ) . '/buddypress-hidden-profiles.php';
	}
);

/*
 * Bootstrap WordPress. This will also load the Composer autoload file, the PHPUnit Polyfills
 * and the custom autoloader for the TestCase and the mock object classes.
 */
WPIntegration\bootstrap_it();

/*
 * The WordPress test installer drops every table, BuddyPress's included, and
 * recreates only core's. Member queries need BuddyPress's last activity data.
 */
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once buddypress()->plugin_dir . 'bp-core/admin/bp-core-admin-schema.php';
bp_core_install();

// Normally set on activation. Friendship emails need it for their unsubscribe links.
bp_update_option( 'bp-emails-unsubscribe-salt', base64_encode( wp_generate_password( 64, true, true ) ) );
