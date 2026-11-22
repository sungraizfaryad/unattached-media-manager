_Last updated: 2026-11-22._
_Mines and architecture in CLAUDE.md. Next release in `dev/ROADMAP.md`. Forum threads in `dev/SUPPORT.md`._

## Where things stand

**1.3.1 is live** (WP.org SVN r3685086, GitHub `main`, tag `1.3.1`), 300+ installs.
**1.3.2 is merged to local `main`, not tagged, not pushed, not deployed.** Plugin Check shows
nothing new against 1.3.1 (`dev/TESTING.md` has how to run it).

## Done

- **1.3.2 trash search.** Unused view's filter bar now also on Trash (one shared
  `UNMAM_Database::list_filter_clause()`). Restore All and Empty Trash hidden while filtered.
  Auto-delete column (`_wp_trash_meta_time` + `EMPTY_TRASH_DAYS`). In Trash card links to Trash.
  View switch drops filter params. Spec: `dev/specs/2026-11-22-trash-search-design.md`.
- FLP test: Unused filters identical before/after across 11 combos; 30 throwaway trashed files
  covered search, type, date, paging, per-page, empty result, overdue and no-meta dates; Restore
  Selected via AJAX restored only the ticked two. FLP diffed clean against baseline after.
- 1.3.0 terms scanning, 1.3.1 WooCommerce variation downloads (see changelog).

## Decisions

- Whole-trash buttons hidden when filtered, not relabelled: same rule as Trash All on Unused.
- Gal's "Beyond the media library" (orphan files on disk) is NOT the 1.4.0 filesystem scan, and
  needs the quarantine work first. No front-end code still stands.

## Next

1. On Sungraiz's go: tag `1.3.2`, push `main` + tag to GitHub, deploy (`~/Local Sites/plugins/deploy.sh`).
2. Forum replies (he drafts): Gemini23 x2 (trash deadline is urgent), Gal orphan files, kreativelabs nudge, Ade theme caveat.
3. 1.4.0: theme/plugin file scanning per `dev/ROADMAP.md`.

## Key files

- `includes/class-unmam-database.php` — `list_filter_clause()`, `get_trashed_attachments()`.
- `includes/admin/class-unmam-admin.php` — Unused/Excluded/Trash rendering, `$view_url`.
- `dev/SUPPORT.md`, `dev/ROADMAP.md`, `dev/TESTING.md`, `dev/specs/`.
