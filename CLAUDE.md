# Unattached Media Manager — working notes

## 1. What this plugin is

- **WP.org name:** Unattached Media Manager
- **Folder / slug / text-domain:** `unattached-media-manager`
- **PHP class prefix:** `UNMAM_` (constants `UNMAM_*`). Older identifiers `mui_*` / `mui-*` and `aioms_*` still exist on purpose — see mines.
- **GitHub:** https://github.com/sungraizfaryad/unattached-media-manager
- **WP.org:** https://wordpress.org/plugins/unattached-media-manager/

WordPress only marks media as "attached" when it was uploaded through the post editor. Anything added via ACF, Gutenberg blocks, page builders, widgets, theme options, shortcodes, SEO plugins, WooCommerce, or custom tables shows as "Unattached", which makes the native Unattached filter unreliable. This plugin scans the whole site for where media is actually used, attaches used files to their parent posts so the native filter works again, and surfaces genuinely unused media for safe cleanup (trash, restore, delete, with full history and revert).

## 2. Repo layout

There are two copies on this Mac. Know which you are in.

- **This folder is the TEST INSTALL:** `~/Local Sites/media-usage-inspector/app/public/wp-content/plugins/unattached-media-manager/`. It has a `.git`, but it is stale (only "Initial Commit") and usually carries uncommitted edits. Do NOT commit or push from here, and do not trust its git log.
- **Canonical release repo:** `~/Local Sites/plugins/unattached-media-manager/` (separate `.git`, full release history, tags 1.0.2 to 1.0.9). All commits, tags, and the WP.org deploy happen there.
- **Workflow:** edit + browser-test here, then `cp` the changed files into the canonical repo, commit, tag, deploy. The deploy guide is `~/Local Sites/plugins/DEPLOY.md`.
- **Build zip:** there is no local zip step. `~/Local Sites/plugins/deploy.sh` (Gary Jones SVN deploy) exports the canonical git HEAD straight to plugins.svn.wordpress.org. `.wordpress-org/` assets live in the canonical repo only.

## 3. Don't trip these mines

- **Mixed prefixes are intentional.** The plugin was renamed All-in-One Media Solution (`aioms_*`) to Media Usage Inspector (`mui_*`) to Unattached Media Manager (`unmam_*`). Internal option keys, CSS classes, postmeta keys, and some identifiers were deliberately left on old prefixes so existing installs keep working. Do NOT "clean up" `mui-*` / `mui_*` / `aioms_*` to match the current name unless explicitly asked. Migration in the main file upgrades `aioms_settings` to `mui_settings` to `unmam_settings`.
- **Form field name must match the save handler.** 1.0.7 silently dropped all settings because the form used `name="mui_..."` while `save_settings()` read `$_POST['unmam_...']`. Fixed in 1.0.8. Any new settings field must use the `unmam_` name the handler reads.
- **Scan step count is not hardcoded any more.** Completion used to be `=== 3`. Adding or removing a scan step must go through `UNMAM_Scanner::get_active_scan_types()` (the single source of truth that feeds the type chain, the CLI loop, and `calculate_overall_progress()`). Hardcoding a count will hang the scan in an infinite batch loop.
- **`UNMAM_VERSION` constant drifts.** It sat at `1.0.2` through the 1.0.6 releases while the header said otherwise. On every release bump the header `Version:`, readme `Stable tag:`, AND the `UNMAM_VERSION` constant together.
- **Custom-table scanning is SQL-identifier sensitive.** Table/column names cannot be bound with `prepare()` placeholders, so `UNMAM_Custom_Table_Parser` validates every identifier against the live schema (SHOW TABLES whitelist + prefix guard + `[A-Za-z0-9_]` filter + text-column-only) before interpolation, and re-validates at scan time. Never relax that to string concatenation. Custom-table refs use `source_type 'custom_table'` and are deliberately never auto-attached (a table row is not a post); they only protect media from the unused list.
- **`uninstall.php` intentionally does NOT revert `post_parent` changes.** Removing the plugin leaves attachments attached. That is by design, not a bug.
- **deploy.sh prints a harmless `svn: E125001 ... tags/<ver>/trunk does not exist`.** The tag is already created by then. Verify with `svn ls .../tags/`. Documented in DEPLOY.md.

## 4. How to develop here (Local by Flywheel)

Site is `media-usage-inspector`. WP-CLI over the Local MySQL socket (run-id and PHP path are dynamic):

```
PHP="/Users/sungraizfaryad/Library/Application Support/Local/lightning-services/php-8.4.18+1/bin/darwin-arm64/bin/php"
WP="/opt/homebrew/Cellar/wp-cli/2.12.0/bin/wp"
RUN="/Users/sungraizfaryad/Library/Application Support/Local/run"
SITE_RID() { grep -rl "$1" "$RUN"/*/conf 2>/dev/null | sed -E "s#$RUN/([^/]+)/.*#\1#" | sort -u | head -1; }
SOCK="$RUN/$(SITE_RID media-usage-inspector)/mysql/mysqld.sock"
$PHP -d mysqli.default_socket="$SOCK" -d pdo_mysql.default_socket="$SOCK" \
    $WP --path="/Users/sungraizfaryad/Local Sites/media-usage-inspector/app/public" plugin list
```

Browser testing: site must be running in Local. Admin login at http://media-usage-inspector.local/wp-admin/ (user `admin`). For one-off DB/scan checks, drop a temporary `_unmam_*.php` loader in `app/public/` that `require`s `wp-load.php`, gate it on `current_user_can('manage_options')`, run it via the browser session, then delete it.

## 5. Architecture map

Core (`includes/`):
- `class-unmam-database.php` — owns the 3 custom tables (references, scan_progress, attachment_history); insert/dedupe references, unused/attach queries, statistics, schema.
- `class-unmam-scanner.php` — parser registry, the scan pipeline, `get_active_scan_types()` source of truth, batch + completion logic.
- `class-unmam-background-processor.php` — three dispatch paths (WP-cron, loopback, frontend AJAX), process lock, scan-type chain advance.
- `class-unmam-job-queue.php` — single-job queue for bulk trash/restore/delete/attach/revert/empty-trash.
- `class-unmam-attachment-manager.php` — attach/detach, reference replacement across content/meta/options, safe-delete guards.
- `class-unmam-history.php` — change log with one-click revert, 90-day cleanup.
- `class-unmam-resource-monitor.php` — adaptive batch sizing by memory/time (safe 50 / warning 70 / critical 85).

Admin (`includes/admin/`): `class-unmam-admin.php` (5 tabs: Dashboard, Unattached, Unused, History, Settings + save handler), `class-unmam-bulk-actions.php` (upload.php bulk actions), `class-unmam-media-modal.php` ("Where Used" panel + usage column).

API/CLI: `includes/api/class-unmam-rest-controller.php` (namespace `unmam/v1`), `includes/cli/class-unmam-cli-commands.php` (`wp unmam ...`).

Parsers (`includes/parsers/`, all implement `class-unmam-parser-interface.php`): content, block, acf, meta, options, widget, elementor, metabox, woocommerce, seo, custom-table.

## 6. Tests

No tests yet. There is no `tests/` directory and no `vendor/`. Verification is done by browser-driven scenarios against the Local site (seed via a temp loader, assert, tear down).

## 7. Build & ship

No local build zip. Release flow (run from the canonical repo `~/Local Sites/plugins/unattached-media-manager/`):

1. `cp` changed files from this test install into the canonical repo; `diff -rq` to confirm parity.
2. Bump header `Version:`, readme `Stable tag:`, and `UNMAM_VERSION` together; add changelog + upgrade notice.
3. `php -l` every changed file.
4. `git add -A && git commit && git tag <ver>`.
5. From `~/Local Sites/plugins/`: `rm -rf /tmp/unattached-media-manager && printf 'unattached-media-manager\n\n\n\n\n\n\ny\n' | ./deploy.sh` (full recipe + known issues in `~/Local Sites/plugins/DEPLOY.md`).
6. Verify `svn ls .../tags/<ver>/`, then `git push origin main && git push origin <ver>`.

## 8. When in doubt

- `progress.md` in this folder — current Done / Decisions / Next steps / Key files.
- Cloud memory: `~/.claude/projects/-Users-sungraizfaryad-Local-Sites-media-usage-inspector/memory/`, filter by `project_unmam_*` / `reference_unmam_*` (rename history, repo layout, SVN deploy guide, WP plugin standards).
- `wp-admin/` and `wp-includes/` are WordPress core. Never edit them.
