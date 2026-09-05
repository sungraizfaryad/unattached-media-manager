_Last updated: 2026-09-05._
_Quick status only. Full detail in CLAUDE.md and cloud memory (`project_unmam_*`)._

## Done
- 1.2.0 (LIVE on WP.org + GitHub, SVN r3682339, tag a42e108).
  Accuracy of the Unused list, both directions.
  Reported unused but in use: stale `scan_post_types` snapshot (types added after the plugin
  were never scanned); candidates filtered on `public` only (missed builder templates,
  `wp_block`, `wp_navigation`); `index_post()` vs batch scan disagreeing; WooCommerce and SEO
  `parse_options()` never called; options sweep capped at 1000 rows and 5 name patterns
  (128 -> 2268 options scanned on FLP); nested option URLs only found under 6 key names.
  Reported used but not in use: `url_to_attachment_id()` filename fallback matched any value
  ENDING in the name (`A.png` matched `termmeta.png`); Meta Box treating every numeric field
  as an attachment ID; `wp-image-{ID}` / `data-id` trusted without checking.
  Trash UX from @adeqx: Restore All, toolbar reordered (Restore was between two destructive
  buttons), page size selector.
  Also: `wp unmam stats` was broken, i18n placeholder errors, 1.1.1 upgrade notice too long.
- 1.1.1 (LIVE on WP.org + GitHub): EMPTY_TRASH_DAYS=0 data-loss guard, trash-view notices,
  corrected deletion guidance.
- 1.1.0 (LIVE): Unused-tab file URLs, Copy URL, CSV export, per-file Exclude.

## Decisions
- Terms scanning and the filesystem parser are deferred to 1.3.0. They are new scan types,
  not tweaks, and 1.2.0 was already large.
- Post-type reconciliation stores `scan_post_types_known`. On first upgrade it is seeded with
  the CURRENT candidate set, never the admin's selection, or every type they unticked would
  be silently re-enabled. `save_settings()` must keep carrying this key.
- `scan_options()` rewinds when the stored step status is `completed`; that is what makes a
  rescan without `--reset` actually re-read options. `index_options()` loops to completion
  because its first batch clears the previous references.
- Numeric IDs stay gated on known key names; only URLs are matched on any key. Matching bare
  numbers anywhere is what made Meta Box invent references.

## Next steps
- 1.2.0 shipped 2026-09-05. Watch the forum for fallout; it changes what counts as "used" on
  every install, so expect unused counts to move on upgrade.
- @adeqx: tell him the theme-file / CSS gap is still open, so he does not re-test and find the
  list still wrong. @galbaras: his ACF-on-terms case is 1.3.0, keep the wp_termmeta custom-table
  entry until then. @kreativelabs (Bricks): still an unverified diagnosis awaiting his reply.
- 1.3.0: terms scan pass + ACF term fields + widen ACF field types; filesystem parser for
  theme/plugin PHP, CSS and JS.
- Known, unfixed: 4 pre-existing `WordPress.DB.PreparedSQL.NotPrepared` Plugin Check errors in
  `get_unused_attachments_detailed()`; admin page H1 still reads "All-in-One Media Solution".

## Key files
- `includes/class-unmam-scanner.php` — scan chain, `get_active_scan_types()`, `scan_options()`.
- `includes/class-unmam-database.php` — `url_to_attachment_id()`, `is_attachment_id()`, queries.
- `unattached-media-manager.php` — post-type candidates + reconciliation.
- `includes/admin/class-unmam-admin.php` — settings, trash toolbar, notices.
- Test harness lives in the session scratchpad (mu-plugin + seed/check/teardown), not the repo.
