# Unattached Media Manager — working notes

## 1. What this plugin is

- **WP.org name:** Unattached Media Manager
- **Folder / slug / text-domain:** `unattached-media-manager`
- **PHP class prefix:** `UNMAM_` (constants `UNMAM_*`). Older identifiers `mui_*` / `mui-*` and `aioms_*` still exist on purpose — see mines.
- **GitHub:** https://github.com/sungraizfaryad/unattached-media-manager
- **WP.org:** https://wordpress.org/plugins/unattached-media-manager/
- **Current release:** 1.2.0 (shipped 2026-09-05).

WordPress only marks media as "attached" when it was uploaded through the post editor. Anything added via ACF, Gutenberg blocks, page builders, widgets, theme options, shortcodes, SEO plugins, WooCommerce, or custom tables shows as "Unattached", which makes the native Unattached filter unreliable. This plugin scans the whole site for where media is actually used, attaches used files to their parent posts so the native filter works again, and surfaces genuinely unused media for safe cleanup (trash, restore, delete, with full history and revert).

**The asymmetry that governs every decision here:** over-reporting usage is an annoyance, under-reporting usage gets someone's live files deleted. When a change could go either way, choose the direction that keeps more media marked as used.

## 2. Repo layout

- **Canonical repo, the only one that matters:** `~/Local Sites/plugins/unattached-media-manager/`. All commits, tags and the WP.org deploy happen here. Releases are tagged on `main`.
- **There is no longer a separate test install.** Older notes described one under `~/Local Sites/media-usage-inspector/`; it no longer exists. Edit the canonical repo directly and `rsync` into a test site to try it.
- **Test site is FLP:** `~/Local Sites/flp/app/public/wp-content/plugins/unattached-media-manager/`. See `dev/TESTING.md`.
- **Deploy:** `~/Local Sites/plugins/deploy.sh` exports canonical git HEAD to plugins.svn.wordpress.org. Full guide in `~/Local Sites/plugins/DEPLOY.md`. `.wordpress-org/` assets live in the canonical repo only.
- **`dev/`** holds the test harness and roadmap. It is in `.svnignore` and never ships.

Sync to the test site (mirrors what deploy ships):

```bash
rsync -a --delete \
  --exclude '.git' --exclude '.DS_Store' --exclude '.wordpress-org' --exclude 'dev' \
  --exclude 'CLAUDE.md' --exclude 'progress.md' --exclude 'README.md' \
  --exclude '.svnignore' --exclude '.gitignore' --exclude '_unmam_*.php' \
  ~/Local\ Sites/plugins/unattached-media-manager/ \
  ~/Local\ Sites/flp/app/public/wp-content/plugins/unattached-media-manager/
```

## 3. Don't trip these mines

**Settings**

- **Mixed prefixes are intentional.** Renamed All-in-One Media Solution (`aioms_*`) to Media Usage Inspector (`mui_*`) to Unattached Media Manager (`unmam_*`). Option keys, CSS classes, postmeta keys and DB table names deliberately kept old prefixes so existing installs keep working. Do NOT rename them. The live tables are `wp_unmam_media_references` and `wp_unmam_scan_progress`; the marked-safe postmeta key is `_mui_marked_safe`.
- **Form field name must match the save handler.** 1.0.7 silently dropped every setting because the form used `name="mui_..."` while `save_settings()` read `$_POST['unmam_...']`.
- **`save_settings()` rebuilds the whole option from a literal array** and calls `update_option()`, it does not merge. Any settings key not listed in that array is destroyed on every save. `scan_post_types_known` is in there for exactly this reason; anything new must be too.
- **`scan_post_types_known` semantics.** It records which post types have already been *offered* to the admin, which is what separates "never seen this type" from "the admin turned it off". On first run, when the key is absent, it must be seeded with the **current candidate set**, never with the admin's selection. Seeding it from the selection reads every deliberately unticked type as brand new and switches them all back on.

**The scan pipeline**

- **`UNMAM_Scanner::get_active_scan_types()` is the single source of truth** for the step chain. It feeds the WP-CLI loop, the background processor's chain advance and `calculate_overall_progress()`. A new scan type must go through it, and must be appended conditionally so installs that do not opt in keep the pipeline they have. Hardcoding a step count hangs the scan in an infinite batch loop.
- **A multi-batch step must return `running` until genuinely done.** Both the CLI loop and `process_batch()` only advance when a step reports `completed`.
- **A resumable step must rewind when re-entered.** `scan_options()` rewinds its cursor when the stored status is already `completed`. Without that, a rescan not started with a reset sweeps nothing at all, an option edited to point at a different image is never re-read, the old reference lingers so that file still looks used, and the newly referenced file looks unused. This shipped broken briefly during 1.2.0 development and was caught in review.
- **`index_options()` must loop to completion.** It is the "this changed, recheck it now" entry point used on ACF options saves. Its first batch clears the previous option references, so stopping after one batch drops references held further down `wp_options`.
- **Two lists of scan types are still hardcoded** and drift: `wp unmam status` (already missing `custom_tables`) and `UNMAM_Scanner::get_scan_status()`. Update both when adding a type.

**Reference correctness**

- **Never treat a bare number as an attachment ID.** Validate with `UNMAM_Database::is_attachment_id()`. The Meta Box parser did not, and indexed prices, user IDs and order statuses as media. Half the Meta Box references on the test site were junk.
- **IDs lifted out of markup are untrusted.** `wp-image-{ID}` classes and `data-id` attributes survive content being copied between sites. Validate, then fall through to resolving the `src` URL rather than giving up.
- **Filename matching must be anchored to a path boundary.** `url_to_attachment_id()`'s fallback once matched any `_wp_attached_file` *ending* in the filename, so `A.png` matched `termmeta.png` and credited the wrong attachment. It now matches the whole basename. Keep the CDN and changed-domain cases working.
- **Only URLs may be matched on an arbitrary key name.** Numeric IDs stay gated on known key names. A URL into uploads is unambiguous; a bare number is not.

**Non-post reference sources**

- `custom_table` rows, and any future `term` or file rows, are **never auto-attached**. A table row, a term and a file are not posts.
- **Five places assume a reference's source is a post**: `class-unmam-media-modal.php` twice, the REST controller, the WP-CLI `usage` command, and `assets/js/media-modal.js`. 1.2.0 guarded all five on `source_type`, so they no longer render a wrong post title, but each still needs a real branch to display anything useful for a new source type.
- **`UNMAM_Attachment_Manager::replace_single_reference()` branches on `context_type` only** and then calls `get_post_meta()` / `update_post_meta()` on `source_id`. Any new source type needs a branch or an explicit bail there, or Replace Media reads and writes the wrong table silently.
- **Cleanup must be scoped by source type *and* context type.** The WooCommerce parser writes category thumbnails as `term` rows during the options step, so a bare `source_type = 'term'` delete would wipe them. Use `UNMAM_Database::delete_references_by_source_and_context()`.

**Releases**

- **`UNMAM_VERSION` drifts.** Bump the header `Version:`, readme `Stable tag:` and the `UNMAM_VERSION` constant together.
- **Custom-table scanning is SQL-identifier sensitive.** Identifiers cannot be bound with `prepare()`, so `UNMAM_Custom_Table_Parser` validates every one against the live schema (SHOW TABLES whitelist, prefix guard, character filter, text columns only) before interpolation. Never relax that.
- **`uninstall.php` intentionally does NOT revert `post_parent` changes.** By design. It also needs any new option name added to its cleanup list.
- **deploy.sh prints a harmless `svn: E125001 ... tags/<ver>/trunk does not exist`.** The tag is already created by then. Verify with `svn ls .../tags/`.
- **WordPress trash is not a file operation.** Trashing changes `post_status` only; the file stays on disk and images keep rendering. Core also empties the trash on a schedule after `EMPTY_TRASH_DAYS`, which does delete the files. With that constant at 0 the trash is disabled entirely and `wp_trash_post()` falls through to a force delete, which is why `trash_attachment()` refuses in that case.

## 4. Architecture map

Core (`includes/`):
- `class-unmam-database.php` — the 3 custom tables, reference insert/dedupe, unused queries, statistics, `url_to_attachment_id()`, `is_attachment_id()`, trash/restore/delete.
- `class-unmam-scanner.php` — parser registry, scan pipeline, `get_active_scan_types()`, batch and completion logic.
- `class-unmam-background-processor.php` — cron, loopback and frontend AJAX dispatch, process lock, chain advance.
- `class-unmam-job-queue.php` — single-job queue for bulk trash/restore/delete/attach/revert/empty-trash. Empty item list means "everything" for attach, trash, restore and revert.
- `class-unmam-attachment-manager.php` — attach/detach, reference replacement, delete guards.
- `class-unmam-history.php` — change log with one-click revert.
- `class-unmam-resource-monitor.php` — adaptive batch sizing.

Admin (`includes/admin/`): `class-unmam-admin.php` (5 tabs plus the settings save handler and the unscanned-post-types notice), `class-unmam-bulk-actions.php`, `class-unmam-media-modal.php`.

API/CLI: `includes/api/class-unmam-rest-controller.php` (namespace `unmam/v1`, and its `/scan/batch` endpoint has its own hardcoded scan-type enum), `includes/cli/class-unmam-cli-commands.php` (`wp unmam ...`).

Parsers (`includes/parsers/`): content, block, acf, meta, options, widget, elementor, metabox, woocommerce, seo, custom-table. All implement `UNMAM_Parser_Interface` (`parse_post`, `get_name`) except the custom-table parser, which is not per-post. A parser may additionally define `parse_options()` for site-wide media; `scan_options()` calls it on every parser that has one. That dispatch did not exist before 1.2.0, so the WooCommerce and SEO implementations were dead code.

## 5. Testing

No automated tests. See `dev/TESTING.md` for the harness, the WP-CLI wrapper, how to drive the admin UI with curl when Playwright is unavailable, and the checks worth repeating every release.

## 6. When in doubt

- `progress.md` — current state, decisions, next steps.
- `dev/ROADMAP.md` — designed and reviewed scope for 1.3.0 and 1.4.0, plus the open forum threads.
- Cloud memory: `~/.claude/projects/-Users-sungraizfaryad-Local-Sites-media-usage-inspector/memory/`, filter `project_unmam_*` / `reference_unmam_*`.
- `wp-admin/` and `wp-includes/` are WordPress core. Never edit them, but do read them: several bugs here were only settled by reading core's own `post.php` and `functions.php`.
