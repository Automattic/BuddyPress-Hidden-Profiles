# BuddyPress Hidden Profiles

**Contributors:** garyj  
**Tags:** BuddyPress, BuddyBoss, profiles, privacy, admin  
**Requires at least:** 6.6  
**Tested up to:** 6.8  
**Stable tag:** 1.0.0  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Allows site admins to mark BuddyPress user profiles as hidden, excluding them from directories, searches, and making their profile pages return a 404 for non-admins.

## Description

This plugin provides a simple way to hide specific BuddyPress user profiles from non-administrative users. When a profile is marked as hidden:

* The profile page returns a 404 error for non-admins
* The user is excluded from member directories
* The user is excluded from search results
* The user is excluded from AJAX-loaded member lists

Hidden profiles remain visible to:
* The profile owner themselves
* Site administrators
* Users with the `manage_options` capability

## Features

* Simple checkbox interface in the WordPress user profile screen
* Complete profile hiding from non-admins
* Efficient caching of hidden user IDs
* WP-CLI support for bulk operations
* Maintains visibility for admins and profile owners

## Installation

1. Upload the `buddypress-hidden-profiles` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure hidden profiles through the WordPress user profile screen or set the user meta value.

## Usage

### WordPress Admin Interface

1. Go to Users → All Users
2. Click on the user you want to hide
3. Scroll down to the "Profile Visibility" section
4. Check the "Hidden Profile" checkbox
5. Click "Update User"

### WP-CLI

You can also manage hidden profiles using WP-CLI:

```bash
# Hide a profile
wp user meta update <user_id> profile_visibility hidden

# Unhide a profile
wp user meta delete <user_id> profile_visibility

# Clear the hidden users cache
wp cache delete bp_hidden_user_ids
```

## Requirements

* WordPress 6.6 or higher
* PHP 8.2 or higher
* BuddyPress or BuddyBoss Platform

## Troubleshooting

If a hidden profile is still visible:

1. Clear the WordPress object cache
2. Verify the user has the correct meta value: `profile_visibility = hidden`
3. Check that the viewing user is not an admin or the profile owner
4. Ensure the cache is cleared after making changes

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a complete list of changes.

## License

This plugin is licensed under the GPLv2 or later. See [LICENSE](LICENSE) for details.
