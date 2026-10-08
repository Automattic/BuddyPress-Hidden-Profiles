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
	// Kept for code that read these here before they moved to Hidden_Users.
	const META_KEY          = Hidden_Users::META_KEY;
	const META_HIDDEN_VALUE = Hidden_Users::META_HIDDEN_VALUE;
	const CACHE_KEY         = Hidden_Users::CACHE_KEY;
	const CACHE_GROUP       = Hidden_Users::CACHE_GROUP;

	/**
	 * Run the plugin.
	 *
	 * @return void
	 */
	public function run() {
		$hidden_users = new Hidden_Users();
		$hidden_users->register_hooks();

		( new Visibility( $hidden_users ) )->register_hooks();
		( new Profile_Setting() )->register_hooks();
	}
}
