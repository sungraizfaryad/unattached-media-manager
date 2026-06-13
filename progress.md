_Last updated: 2026-06-07._
_Quick status only. Full detail in CLAUDE.md and cloud memory (`project_unmam_*`)._

## Done
- 1.0.9 (live on WP.org + GitHub): scan opt-in custom database tables (`table.column`), schema-validated, read-only; protects referenced media without auto-attaching.
- 1.0.8: per-post-type scan controls (auto-discovers public CPTs); fixed silent settings-save no-op (mui_/unmam_ field mismatch); lazy migration for scan_post_types.
- 1.0.7: Unused-media filters (filename / mime / date range); WP 7.0 compat; skip `_mfrh_history` + `_original_filename` meta keys; fixed stale `UNMAM_VERSION` constant.
- 1.0.3 to 1.0.6: WP.org SVN asset structure + readme metadata only, no code change.
- 1.0.0: initial release (scanner, parsers, attach/unused/history, REST, CLI).

## Decisions
- Mixed `aioms_*` / `mui_*` / `unmam_*` identifiers kept intentionally for back-compat. Do not rename to match current plugin name.
- Custom-table refs use `source_type 'custom_table'` and are never auto-attached, only unused-protected.
- Scan step list comes from `UNMAM_Scanner::get_active_scan_types()` (single source of truth). Never hardcode the step count.
- Canonical repo at `~/Local Sites/plugins/unattached-media-manager/` is the release source, not this test install. Deploy via `~/Local Sites/plugins/deploy.sh`.
- `uninstall.php` deliberately does not revert `post_parent` changes.

## Next steps
- Awaiting next instruction. No TODO/FIXME markers in source; no pending feature branch.
- Possible future (requested by user @galbaras, not committed): dropdown table/column picker for custom tables; built-in scan map for popular plugins (e.g. Tribulant Newsletters).
- Separate, unshipped: local-only fix to the OTHER plugin `remove-taxonomy-url` (`add_settings_error` fatal); ship as its own RTU release if desired.

## Key files
- `readme.txt` — changelog + stable tag (most edited).
- `unattached-media-manager.php` — bootstrap, constants, settings defaults, migration.
- `includes/admin/class-unmam-admin.php` — tabs + settings save handler.
- `includes/class-unmam-scanner.php` — scan pipeline + active-types source of truth.
- `includes/class-unmam-database.php` — custom tables + reference/unused queries.
- `includes/parsers/class-unmam-custom-table-parser.php` — 1.0.9 custom-table scanning.
