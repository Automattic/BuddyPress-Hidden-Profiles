# BuddyPress Hidden Profiles

Lets site admins hide BuddyPress member profiles from non-admins: hidden members are left out of directories and searches, and their profile pages return a 404.

## Project Knowledge

| Property | Value |
|----------|-------|
| **Main file** | `buddypress-hidden-profiles.php` |
| **Text domain** | `buddypress-hidden-profiles` |
| **Namespace** | `Automattic\BuddyPressHiddenProfiles` |
| **Composer package** | `automattic/buddypress-hidden-profiles` |
| **Filter prefix** | `buddypress_hidden_profiles_` |
| **Requires PHP** | 8.2+ |
| **Requires WP** | 6.6+ |
| **Requires** | BuddyPress or BuddyBoss Platform |
| **Distribution** | GitHub releases only; not on WordPress.org |

All logic lives in one class, `BuddyPress_Hidden_Profiles` in `src/`, instantiated on `bp_loaded`.

Do not rename these without a migration, because they are stored data or public API:

- **`profile_visibility` user meta, value `hidden`**: what marks a profile as hidden. The README tells admins to set it with WP-CLI.
- **`bp_hidden_user_ids` cache key, in the global `buddypress_hidden_profiles` group**: the README tells admins to delete it by hand.
- **The two filters**, `buddypress_hidden_profiles_is_hidden` and `buddypress_hidden_profiles_additional_hidden_ids`, which other code uses to extend the plugin.

## Commands

```bash
composer cs                  # Check coding standards (PHPCS)
composer cs-fix              # Fix what PHPCS can
composer lint                # PHP syntax lint
composer test                # Integration tests (requires wp-env)
composer test:integration-ms # Integration tests, multisite
npx wp-env start             # WordPress with BuddyPress active
```

## Conventions

Follow the standards in `~/code/plugin-standards/`. Key points:

- **Commits**: Use the `/commit` skill. Commits must be signed.
- **PRs**: Use the `/pr` skill and target `develop`. On every PR, set:
  - a milestone (the next version if one exists, otherwise `Next`)
  - a label from the standard set in `LABELS.md` (`type: ...`)
  - the assignee `GaryJones`
- **Linear**: Work is tracked in the `VIPPLUG` team, with the `Plugin` label `BuddyPress Hidden Profiles`. Link a PR to its issue with `Fixes VIPPLUG-123` in the description.
- **Tests**: Integration tests only, in `tests/Integration/`, extending `Yoast\WPTestUtils\WPIntegration\TestCase`. There is no unit suite, because the plugin is thin glue around WordPress and BuddyPress.
- **i18n**: User-facing strings use the `buddypress-hidden-profiles` text domain.

## Common Pitfalls

- **The wp-env BuddyPress URL**: wp-env names a plugin's directory after its ZIP file, and the test bootstrap requires `plugins/buddypress/bp-loader.php`. Keep the URL ending in `buddypress.zip`.
- **Clearing the cache**: anything that changes who is hidden must clear `bp_hidden_user_ids`, or directories keep showing stale results for up to a day.
- **Release ZIP contents**: the release workflow builds the ZIP with `rsync --exclude-from=.distignore`. Add new development-only files to both `.distignore` and `.gitattributes`.
