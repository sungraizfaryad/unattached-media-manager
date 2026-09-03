_Last updated: 2026-09-03._
_Quick status only. Full detail in CLAUDE.md and cloud memory (`project_unmam_*`)._

## Done
- 1.1.1 (BUILT + browser-tested on FLP, NOT yet committed/shipped): safety release from three forum threads.
  (1) Data-loss fix: on sites with `EMPTY_TRASH_DAYS = 0`, `wp_trash_post()` falls through to a force
  delete, so "Move to Trash" deleted the file while returning true. `UNMAM_Database::trash_attachment()`
  now refuses via new `is_trash_available()`; all Trash buttons hidden; red explainer notice.
  (2) Trash view notice: files stay on disk, images keep rendering, and core auto-purges after
  EMPTY_TRASH_DAYS days. (3) readme: removed the "watch for missing images" advice (impossible by
  design), replaced with access logs / staging-copy verification. Corrected `safe_delete()` docblock.
- 1.1.0 (LIVE on WP.org + GitHub, tag 1.1.0): Unused-tab file URLs, Copy URL, CSV export, per-file Exclude.
- 1.0.9: opt-in custom database table scanning. 1.0.8: per-post-type scan controls + settings-save fix.

## Decisions
- Guard lives in `trash_attachment()` (single choke point for UI, bulk, job queue, CLI, REST), with the
  UI gating as a second layer. Verified: AJAX with a valid nonce is still refused.
- Trash view keeps Restore / Delete Permanently when trash is disabled, so pre-existing trashed items
  are still recoverable.
- Mixed `aioms_*` / `mui_*` / `unmam_*` identifiers stay. Do not rename.

## Next steps
- 1.1.1: commit + tag in canonical repo, deploy via `~/Local Sites/plugins/deploy.sh`, push to GitHub.
- 1.2.0 (scanner coverage, ~2 days), all three from forum threads: post types by `show_ui` not `public`
  (Bricks templates); terms scan pass + ACF term fields + widen ACF field types beyond image/gallery/file
  (Gal Baras, product_cat WYSIWYG); options parser `LIMIT 1000` + name-pattern restriction; filesystem
  grep parser for theme/plugin PHP, CSS, JS.
- 1.3.0 (deferred, 3-4 days): rendered-HTML + CSS crawl verification. Needs a loopback preflight.
- Bug found 2026-09-03, unfixed: CLI stats compute unused as `total - distinct_referenced`, which counts
  orphaned reference rows as referenced. On FLP that read 261 vs the correct 271. Orphan rows are left
  behind when media is deleted outside the plugin. Fix the stats math and hook `delete_attachment`.
- Cosmetic, unfixed: admin page H1 still reads "All-in-One Media Solution" (old plugin name).

## Key files
- `includes/class-unmam-database.php` — trash/restore/delete, unused queries, `is_trash_available()`.
- `includes/admin/class-unmam-admin.php` — tabs, notices, button gating (`render_unused_content()`).
- `readme.txt` — changelog + stable tag (most edited).
- NOTE: the old test install under `media-usage-inspector` no longer exists. 1.1.1 was edited directly in
  the canonical repo and rsynced to `~/Local Sites/flp/` for browser testing.
