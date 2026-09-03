# Changelog

All notable changes to Unattached Media Manager will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] - 2026-09-03

### Fixed
- **Data loss on sites with `EMPTY_TRASH_DAYS` set to 0.** With that constant at 0 the
  WordPress trash is disabled, so `wp_trash_post()` falls through to a force delete.
  "Move to Trash" removed the file from the server while reporting that it had been
  trashed. Trashing is now refused on those sites, from a single guard that covers the
  admin UI, bulk actions, the job queue, WP-CLI and the REST API.
- Corrected the `safe_delete()` docblock, which claimed to move items to the trash first.
  That only happens when `MEDIA_TRASH` is defined, which it is not on most sites.
- Corrected developer documentation that referenced identifiers which do not exist:
  the WP-CLI command is `wp unmam`, the parser filter is `unmam_parsers`, and the
  interface is `UNMAM_Parser_Interface`.

### Added
- Every Trash button is hidden when the trash is unavailable, with an explanation of why.
  Non-destructive actions (CSV export, Exclude, Copy URL) remain available.
- The Trash view now states that trashed files stay on the server and that images already
  placed in content keep displaying, so trashing a file that is still in use will never
  show up as a broken image.
- The Trash view warns that WordPress empties the trash automatically after
  `EMPTY_TRASH_DAYS`, permanently deleting the files, with no one clicking Empty Trash.

### Changed
- Rewrote the deletion guidance. Earlier versions suggested watching the site for missing
  images after trashing, which cannot happen by design and gave a false sense of safety.
  Replaced with methods that work: server access logs and a staging copy.
- Tested up to WordPress 7.1.

## [1.1.0] - 2026-06-27

### Added
- "Copy URL" button on each row of the Unused Media tab, with a fallback for sites served
  over plain HTTP.
- "Export URLs (CSV)" button, plus `wp unmam unused --format=csv`, for comparing the unused
  list against a site crawl. The full Export Report gained a URL column.
- Exclude files from the Unused list, with an "Excluded" view and an Include action to undo.
  This reuses the existing "Marked Safe" flag, so files marked safe in the Media Library are
  excluded here too.

### Changed
- The "View" action opens the actual file in a new tab rather than the attachment editor.
  The title link still goes to the editor.

### Fixed
- WP-CLI help examples referenced `wp mui`. The command is `wp unmam`.

## [1.0.9] - 2026-06-04

### Added
- "Custom Database Tables (Advanced)" setting. Some plugins store content, and the media
  URLs inside it, in their own tables rather than in posts or options. Newsletter plugins
  are a common example. Those `table.column` locations can now be scanned.

### Security
- Every table and column entry is validated against the live database schema before any
  query runs. Only existing tables within the site's prefix and text columns are accepted,
  and all queries are read only. Invalid entries are reported rather than silently dropped.

### Notes
- Media found in a custom table is protected from deletion but is not attached to a post,
  because there is no post to attach it to. It simply stops appearing in the Unused list.
- The scan pipeline now derives its step list from a single source of truth, so existing
  installs that do not use this feature behave exactly as before.

## [1.0.8] - 2026-05-25

### Added
- "Post Types to Scan" on the Settings page. The scanner auto-discovers every public post
  type registered by your theme or plugins and lets you choose which to include.
- Existing 1.0.7 installs receive the new setting pre-populated on first read after
  upgrade. No rescan or reactivation needed.
- `unmam_scan_post_types` filter for overriding the allow-list in code.

### Fixed
- Save Settings was a no-op in 1.0.7. Form field names did not match the save handler, so
  every toggle change was silently discarded.

### Security
- Tampered form submissions can no longer register arbitrary post type slugs. The save
  handler intersects against currently registered public types.

## [1.0.7] - 2026-05-22

### Added
- Filter Unused Media by filename, mime type and upload date range.

### Changed
- Confirmed compatibility with WordPress 7.0.
- Skip the `_mfrh_history` and `_original_filename` meta keys, which are Media File Renamer
  internals rather than real media references.
- Confirmed Rank Math schema video thumbnail meta is matched by the generic meta walker.

### Fixed
- The internal `UNMAM_VERSION` constant had drifted behind the plugin header.

## [1.0.3] to [1.0.6] - 2026-02-21 to 2026-03-10

### Changed
- Maintenance releases covering WordPress.org SVN asset structure (banner, icon,
  screenshots) and readme metadata. No functional code changes.

## [1.0.0]

Initial development release. The first version published to WordPress.org was 1.0.2, on
2026-02-21, so no release date is recorded for 1.0.0.

### Added
- Initial release.
- **Scanning**: media scanning across posts, ACF, blocks, widgets and options; two
  processing modes (browser-driven and WP-Cron); pause and resume; adaptive resource
  management with Low, Auto and High modes.
- **Media management**: one-click fix for unattached media; trash and restore; permanent
  delete; bulk operations; empty trash.
- **Tracking and safety**: full change history with audit trail; one-click revert on any
  attachment change; background job queue for bulk operations; universal stop button.
- **Developer tools**: REST API endpoints, WP-CLI commands, extensible parser system.
- **Interface**: Media Library integration with usage counts, CSV export, real-time
  progress, sticky status bar for background operations.
