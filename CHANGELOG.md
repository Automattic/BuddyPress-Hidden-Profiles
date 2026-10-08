# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-10-08

**Security release: please update.** Hidden profiles could still be found through group member lists, invite lists, @mention suggestions and the REST API.

**Upgrade note:** the hidden users cache now lives in the `buddypress_hidden_profiles` cache group. It clears itself whenever a user's `profile_visibility` meta changes, so most sites need do nothing. If your code clears it after changing who the `buddypress_hidden_profiles_additional_hidden_ids` filter returns, change `wp_cache_delete( 'bp_hidden_user_ids' )` to `wp_cache_delete( 'bp_hidden_user_ids', 'buddypress_hidden_profiles' )`, or the old call silently does nothing.

### Fixed
* Clear the hidden users cache whenever a user's visibility meta changes, including from WP-CLI by @GaryJones in https://github.com/Automattic/buddypress-hidden-profiles/pull/12
* Share the hidden users list across every site in a multisite network by @GaryJones in https://github.com/Automattic/buddypress-hidden-profiles/pull/12
* Tolerate the `buddypress_hidden_profiles_additional_hidden_ids` filter returning something other than an array by @GaryJones in https://github.com/Automattic/buddypress-hidden-profiles/pull/12
* Associate the Hidden Profile label with its checkbox, so screen readers announce it by @GaryJones in https://github.com/Automattic/buddypress-hidden-profiles/pull/12

### Changed
* Move the hidden users cache into the `buddypress_hidden_profiles` cache group by @GaryJones in https://github.com/Automattic/buddypress-hidden-profiles/pull/12
* Check admin capabilities with `current_user_can()` by @GaryJones in https://github.com/Automattic/buddypress-hidden-profiles/pull/5

### Security
* Hide profiles from every member list, including widgets, friends lists, group member lists, group invite lists, @mention suggestions and the REST API members list, not just the AJAX directory, and return a 404 when the REST API is asked for a hidden member by ID. Reported via HackerOne by [antaloaalonso](https://hackerone.com/antaloaalonso), fixed by @GaryJones in https://github.com/Automattic/buddypress-hidden-profiles/pull/10
* Return a 404 for hidden profiles before BuddyPress handles the request, so pages such as activity feeds no longer load by @GaryJones in https://github.com/Automattic/buddypress-hidden-profiles/pull/11
* Return a 404 from the REST API for a hidden member's avatar, cover image and profile field data by @GaryJones in https://github.com/Automattic/buddypress-hidden-profiles/pull/12
* Hide users added by the `buddypress_hidden_profiles_additional_hidden_ids` filter on their profile page too, not just in member lists by @GaryJones in https://github.com/Automattic/buddypress-hidden-profiles/pull/12

### Maintenance
* Bring repository tooling up to the plugin standards, with integration tests and a wp-env development environment by @GaryJones in https://github.com/Automattic/buddypress-hidden-profiles/pull/4
* Add the translation template by @GaryJones in https://github.com/Automattic/buddypress-hidden-profiles/pull/6
* Use the lowercase repository URL by @GaryJones in https://github.com/Automattic/buddypress-hidden-profiles/pull/9

### Documentation
* Describe what hiding covers and its known limitations by @GaryJones in https://github.com/Automattic/buddypress-hidden-profiles/pull/10
* Declare compatibility with WordPress 7.1 by @GaryJones in https://github.com/Automattic/buddypress-hidden-profiles/pull/7
* Add a security policy pointing to HackerOne by @GaryJones in https://github.com/Automattic/buddypress-hidden-profiles/pull/8

## [1.1.0] - 2025-05-23

### Added
* Add new filters to support additional criteria for hiding profiles by @GaryJones in https://github.com/Automattic/buddypress-hidden-profiles/pull/1

## 1.0.0 - 2025-05-16

Initial release of BuddyPress Hidden Profiles.

[1.2.0]: https://github.com/automattic/buddypress-hidden-profiles/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/automattic/buddypress-hidden-profiles/compare/1.0.0...1.1.0
