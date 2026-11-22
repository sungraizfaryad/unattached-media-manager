# Theme and plugin file scanning (1.4.0)

Approved 2026-11-22. Raised by @adeqx (could not trust the unused list) and @galbaras (block
theme sidebar image listed as unused). Starts from the reviewed outline in `dev/ROADMAP.md`,
with one change of approach: the scan walks one theme or plugin at a time with a file-name
cursor instead of storing a manifest of every file. The manifest's sharded options, their
uninstall cleanup and its staleness during a plugin auto-update all go away.

Revised the same day after a three-way review (missed media, fit with the code, hosting and
security). Every point below was checked against the code before it went in.

## Problem

The plugin only reads the database. An image referenced only from a theme or plugin file is
listed as unused and can be deleted while the site still shows it. The real cases:

- CSS `background-image` rules in theme and plugin stylesheets, written as absolute URLs,
  root-relative URLs or paths relative to the stylesheet (`../../uploads/...`).
- Block theme templates and parts that were never edited in the Site Editor. 1.3.3 reads the
  edited copies in the database; the untouched ones only exist as `.html` files on disk. If
  Gal's sidebar part was never edited, 1.3.3 did not fix his case and this does.
- Upload URLs and attachment IDs hardcoded in a custom theme. FLP's own theme hardcodes its
  logo URL in `functions.php`.

**Temper the expectation.** Most themes build image URLs in code, through
`get_template_directory_uri()`, `wp_get_attachment_image( $id )` with a variable,
`$uploads['baseurl'] . '/2024/01/x.jpg'`, ACF and so on. None of that is visible to a text
scan. The changelog and readme say so plainly.

## Decisions (with Sungraiz, 2026-11-22)

1. On by default, for fresh installs and for sites upgrading in place.
2. Every installed theme and plugin is scanned, active or not. Where Used says which are inactive.
3. Literal attachment IDs passed to core's attachment functions in PHP count as finds.
4. Page builder CSS generated into `uploads` stays out. It is built from data already scanned,
   and stale copies would keep deleted pages' images marked used.
5. All settings and data files are read, JSON included, accepting the noise. On FLP the
   theme's `data/` and `tools/backups/` JSON holds up to 93 distinct upload URLs.
6. One theme or plugin at a time, with a cursor (approach B).
7. Replace Media stops reassigning rows it could not change.
8. Files the scan could not read are reported where the admin decides: on the plugin's admin
   page and at the end of `wp unmam scan`.
9. A notice warns when themes or plugins changed after the last file pass, and when there has
   never been one. Still no automatic rescan.
10. Paths relative to the file and `uploads/...` tails are matched, not just full URLs.
11. The usage AJAX endpoint gets a capability check, and theme and plugin names and paths are
    shown only to users who can manage plugins.

## What gets scanned

**Components.** Built once at the start of a pass, sorted by key with `strcmp()`, and stored
with the pass state so a plugin installed mid-pass cannot shift positions:

| Component             | Key                   | Root                                            |
|-----------------------|-----------------------|-------------------------------------------------|
| Each installed theme  | `theme:{stylesheet}`  | `wp_get_themes( array( 'errors' => null ) )`    |
| Each plugin folder    | `plugin:{folder}`     | every folder in `WP_PLUGIN_DIR`                 |
| Each top-level file   | `plugin:{file.php}`   | every `.php` file at the root of `WP_PLUGIN_DIR`|
| Must-use plugins      | `mu`                  | `WPMU_PLUGIN_DIR`, whole folder                 |

- `'errors' => null` keeps broken themes, such as a child whose parent was deleted. The
  default drops them.
- Plugins come from listing `WP_PLUGIN_DIR`, not from `get_plugins()`, which skips a folder
  whose main file has no valid header. Names come from `get_plugins()` where it knows the
  folder, otherwise the folder name.
- Each component also records its URL root (`get_stylesheet_directory_uri()`,
  `plugins_url()`, `WPMU_PLUGIN_URL`) for resolving relative paths.
- This plugin's own folder is left out (`dirname( UNMAM_PLUGIN_BASENAME )`). Its readme and
  parsers mention sample upload URLs.
- Never scanned: `wp-admin`, `wp-includes`, the uploads folder, drop-ins at the root of
  `wp-content`, `wp-content/languages`.

**Files.**

- Extensions, case-insensitive: `css scss sass less php html htm js json twig`.
- Skipped folders: `node_modules`, `vendor`, `bower_components`, and any name starting with a dot.
- Reading uses `file_get_contents( $path, false, null, $offset, $length )` after
  `is_readable()` and `filesize()`. Listing uses `scandir()`. Plugin Check excludes
  `file_get_contents` from its filesystem rule; `fopen`, `fread` and `fclose` would each be a
  new error, and `WP_Filesystem` has no offset read and can lack credentials in cron. Any `@`
  suppression stays on a single line with a justified `phpcs:ignore`.
- Pieces: the first is up to 1,048,576 bytes (`UNMAM_Reference_Extractor::MAX_TEXT_LENGTH`).
  Each later piece starts 4,096 bytes before the previous one ended, and its total length,
  overlap included, is still at most 1,048,576. The extractor silently returns nothing for
  anything longer, so this limit is exact.
- Each piece gets `preg_replace( '#\\\\+/#', '/', $piece )`, which collapses any run of
  backslashes before a slash. That covers JSON with escaped slashes and JSON stored inside
  JSON (`\\\/`), common in data and backup files.
- A file over 10 MB is skipped and reported. So is an unreadable file or folder, a folder past
  the depth cap, and a file that stopped the scan three times (see the batch budget).

**Walk and cursor.**

- A small recursive walk of our own. Each folder is listed with `scandir()`, names cast to
  `(string)`, and sorted with `usort( $entries, 'strcmp' )`.
- **Every comparison uses `strcmp()` and `===`, never `<`, `==` or `<=>`.** PHP compares two
  numeric strings as numbers, so `'9' < '10'` is true while `strcmp()` sorts `10` first; a
  loose comparison would skip folder `9` whole. `'01' == '1'` and `'1e3' == '1000'` are also
  true. Names must also never round-trip through array keys, where numeric names become ints.
- The relative path is built only by joining `scandir()` names with `/`, never derived from
  `realpath()` output, which uses backslashes on Windows.
- The cursor is: component index, relative path of the current file, the candidate index
  inside that file (below), and that file's size and mtime. Resuming compares the path segment
  by segment: entries before the cursor's segment at that depth are skipped whole, the
  matching folder is entered, everything after is taken. Files added or removed mid-pass do
  not shift what comes next. A deleted cursor file still works; the walk takes the next entry.
- If the cursor file's size or mtime changed since the last batch, its candidate index goes
  back to 0. Re-reading it only produces duplicate rows, which `insert_reference()` merges.
- Symlinks: the component root is resolved with `realpath()` first, since Local and dev setups
  link plugin folders in from elsewhere. Roots and link targets go through
  `wp_normalize_path()`. Inside a component, a link is followed only if its target starts with
  `trailingslashit( $root )` (so `foo-pro` is not inside `foo`) and that folder has not been
  visited on this walk. Depth is capped at 25.

**Batch budget.** A batch stops at whichever comes first: 200 files, 8 MB read, 5,000 folder
entries visited, 20 seconds, or `UNMAM_Resource_Monitor::should_pause()`. These are checked
between files and between candidate lookups, but only after at least one unit of work, so a
host where `should_pause()` is already true at batch start (a 10-second limit, or memory past
85%) still moves forward. The limits go through `unmam_file_scan_batch_limits`, which the
tests use to force tiny batches.

**A file that keeps killing the batch.** At the start of each batch the state records an
attempt on the cursor file; a batch that ends normally clears it. If a third attempt would
begin on the same cursor, the file is skipped and reported as "stopped the scan". This covers
a fatal or a host kill that the time checks cannot catch.

## What counts as a find

The extractor is split into **find** and **resolve** so the file scan can stop between
lookups. One unresolved URL costs up to four queries in `url_to_attachment_id()`, including an
unindexed `guid` match and a `LIKE` on `_wp_attached_file`, and a demo-data file with
thousands of distinct URLs could otherwise run past the time limit inside one file and stall
the step for ever.

- New `UNMAM_Reference_Extractor::find_candidates( $text, $options )` returns an ordered,
  de-duplicated list of `array( 'type', 'value' )`. `extract_from_text()` becomes
  find-then-resolve and returns exactly what it does today, so every existing caller is
  unchanged. There is still one copy of each regex.
- For each file the scanner reads all pieces, collects candidates across them (first-seen
  order, so the list is the same on every read), then resolves them one by one with the budget
  checks in between. The candidate index in the cursor points into this list.

Candidates, by file type:

- All files: `wp-image-{ID}` classes, absolute media URLs, and root- and protocol-relative
  upload URLs, exactly as `extract_from_text( $text, true )` finds them.
- All files, new: an **uploads tail**, the uploads folder name followed by a path to a media
  file (`uploads/2024/01/bg.jpg`, from `../../uploads/...`, `content_url( '/uploads/...' )`,
  `WP_CONTENT_URL . '/uploads/...'`). The tail is rebuilt against the uploads `baseurl` and
  resolved with the filename fallback **off**, so only an exact library file counts. The
  folder name is the last segment of the uploads `baseurl` path; uploads served from a domain
  root skip this. A lookbehind keeps it off the tail of a full URL already matched.
- CSS-like files (`css scss sass less html htm`), new: a `url(...)` value or `src`/`href`
  attribute starting with `./` or `../` is resolved against the file's URL (component URL root
  plus its folder), dot segments collapsed. It counts only if the result is under the uploads
  `baseurl`, and is resolved with the fallback off. The browser resolves these against the
  stylesheet's URL, not the disk path, so this works for linked plugin folders too.
- `.php` files, new: a literal integer, quoted or not, as the first argument of
  `wp_get_attachment_image`, `wp_get_attachment_image_src`, `wp_get_attachment_image_url`,
  `wp_get_attachment_url`, `wp_get_attachment_link`, `wp_get_attachment_metadata`,
  `wp_get_attachment_caption`, `wp_get_attachment_image_srcset`,
  `wp_get_attachment_image_sizes`, `wp_get_attachment_thumb_url`, `wp_get_original_image_url`,
  `wp_get_original_image_path`, `get_attached_file` or `image_downsize`. Every number goes
  through `is_attachment_id()`. FLP has one hit, `wp_get_attachment_link( 0`, which fails it.

No memo of resolved URLs. `url_to_attachment_id()` answers differently depending on its
second argument, a memo keyed by URL would turn a miss into a cached miss for the next caller,
and FLP only meets about 304 distinct media URLs across 7,455 files and 83 MB.

Finds are written per attachment per file; `insert_reference()` merges repeats.

## Rows

- `source_type` `file`, `source_id` 0.
- `context_type` `theme_file`, `plugin_file` or `mu_plugin_file`.
- `context_key` is the component key and the relative path joined by `|`, for example
  `theme:genesis-block-theme|style.css`. Stylesheet slugs can contain `/`, hence the pipe.
- These are the first rows whose key and value come from disk bytes, and `wpdb::insert()`
  refuses a row when any field contains invalid UTF-8 or is too long. So both
  `context_key` and `reference_value` go through `wp_check_invalid_utf8( $s, true )` first.
  Over 255 characters, the key keeps the component key and the end of the path and drops the
  middle (`theme:x|…/end/of/path.css`), measured with `mb_strlen()` and cut with
  `mb_substr()`. `reference_value` is `text`. If the insert still fails, it is retried once
  with `{component key}|#` plus an md5 of the path, so the image is credited either way.
- `context_label` "Theme file", "Plugin file" or "Must-use plugin file".
- `reference_type` `url` or `id`, `reference_value` the matched URL or ID.
- Never attached. Every attach query and the used-but-unattached count require
  `source_type = 'post'`, and the file step never calls `auto_attach_media()`.

## The scan step

- New scan type `files`, appended last in `get_active_scan_types()` when `scan_files` is true.
- `run_batch()` gets `case 'files'`, which calls `UNMAM_File_Scanner::scan_batch()` in a new
  `includes/class-unmam-file-scanner.php`, required in `load_dependencies()` (no autoloader).
  The scanner class only dispatches; it is already 1,087 lines.
- Pass state lives in one option, `unmam_file_scan_state`, autoload off: components, cursor,
  attempt count, counts, and the skipped list with a reason per file.
- **A pass starts in `full_scan()`, not on a `completed` status.** `full_scan()` always calls
  `reset_scan_progress( 'files' )` and deletes the state, reset or not. Both the background
  processor (`start_scan()`) and `wp unmam scan` go through it, so a rescan without reset
  still re-reads every file.
- A files batch with no progress row or no state starts the pass: it clears the rows with
  `delete_references_by_source_and_context()` for each of the three contexts and builds the
  component list. A files batch that finds the row `completed` returns `completed` and touches
  nothing.
- This is a deliberate exception to the "a resumable step must rewind when re-entered" mine.
  The browser batch endpoint takes no lock while the minute cron keeps running, so a late
  duplicate batch can arrive after the last one wrote `completed`. Rewinding there would wipe
  every file row, read one batch and leave the scan marked complete. CLAUDE.md gets a line on
  this.
- The progress row uses `total_items` for the component count and `processed_items` for the
  index. `calculate_overall_progress()` weights `files` by those items the way it weights
  `posts`, so the bar moves per theme or plugin. Next to a large posts count that is a small
  share of the bar; the scan screen already names the current step ("Files").
- If `scan_files` is off when the step runs (unticked mid-scan, with `files` already the
  processor's current step), it clears the file rows and state and returns `completed`.
- On completion it stores the time in `unmam_files_scanned_at` (UTC timestamp) and returns a
  message with counts. The message is not shown anywhere today, so the skipped list is
  reported through the notice and the CLI below, not through it.
- A component deleted between passes keeps its rows until the next pass. That over-reports,
  which is safe.

## Notices

Both use the existing `render_unscanned_post_types_notice()` pattern on the plugin's admin
page, and only appear while `scan_files` is on.

- **Files not read.** When the state's skipped list is not empty: "{N} theme or plugin files
  could not be read, so images used only in them may be listed as unused." followed by up to
  ten paths and reasons. Names and paths only for users who can `activate_plugins`.
- **Files changed.** `unmam_files_changed_at` (UTC timestamp) is set on
  `upgrader_process_complete` when `$hook_extra['type']` is `theme` or `plugin`, and on
  `deleted_plugin` and `deleted_theme`. The notice shows when there has never been a completed
  file pass ("Theme and plugin files have not been scanned yet") or when the change is newer
  than `unmam_files_scanned_at` ("Themes or plugins changed since the last scan. Run a scan
  before deleting anything."). Files changed by FTP or the file editor are not caught.
- `wp unmam scan` prints the skipped list as a warning after the loop. `wp unmam status` prints
  both conditions.

## Settings

- New boolean `scan_files`.
- Activation defaults: `true`.
- Lazy migration in `get_setting()`: key missing means an upgrade, set `true`. A saved `false`
  stays `false`.
- `save_settings()` rebuilds the option from a literal array, so `scan_files` must be added
  there: `isset( $_POST['unmam_scan_files'] )`.
- Saving with it switched off deletes the file rows and the state straight away, so unticking
  takes effect without a reset scan.
- One tickbox on the Settings tab, "Theme and plugin files", with help text: "Finds images
  used in theme and plugin stylesheets, templates and code. Images a theme builds in code are
  not found."

## Where Used and permissions

- `UNMAM_Database::describe_source()` gets a `file` branch, placed before the
  `source_id <= 0` bail. All four callers (media modal twice, REST, WP-CLI `usage`) go
  through it.
- The component is read from `context_key` and looked up live, so active status is current:
  theme name from `wp_get_theme()`, plugin name from a shared helper that does
  `require_once ABSPATH . 'wp-admin/includes/plugin.php'` when `get_plugins()` is not defined
  and caches the list for the request. `is_plugin_active()` goes through the same guard.
  The plugin supports 5.8, and REST requests on older versions do not load that file.
- Title: `{name}: {relative path}`, with " (inactive)" after the name when the theme is
  neither the active theme nor its parent, or the plugin is not active on this site or the
  network. A component no longer installed shows " (not installed)". "Must-use plugins" for `mu`.
- No edit link and no view link. Many hosts disable the file editor, and editing live code
  from a link can break a site. `media-modal.js` already renders a title without a link.
- **Who sees what.** The full title only when `current_user_can( 'activate_plugins' )` or in
  WP-CLI. Anyone else gets "Theme or plugin file" and no path, because the full list is an
  inventory of installed and inactive plugins, which is what an attacker looks for. On
  multisite `activate_plugins` already needs a super admin unless the plugins menu is enabled
  for site admins.
- **Pre-existing gap, fixed here.** `UNMAM_Media_Modal::ajax_get_usage()` checks only the
  nonce, and that nonce is printed on `post-new.php` for any user who can open it,
  contributors included. So any logged-in writer can ask for the usage of any attachment. It
  gets `current_user_can( 'upload_files' )` and `current_user_can( 'edit_post', $attachment_id )`,
  matching core's attachment edit check. For file rows the raw `context_key` and
  `reference_value` are dropped from the response unless the user can `activate_plugins`.
  The REST route already requires `manage_options`.

## Replace Media

**File rows.** `replace_single_reference()` gets an explicit case for the three file
contexts. It never writes a file. It returns `success` false with the error "Not changed,
edit this file by hand: {component}: {path}", so `wp unmam replace` lists every file the admin
has to fix.

**Reassignment bug (pre-existing).** After the loop, `replace_references()` runs one
`UPDATE ... SET attachment_id = new WHERE attachment_id = old` whenever anything succeeded.
That moves every row of the old image to the new one, including rows it could not change (the
`default:` branch, or a content branch where the old URL was not found). The old image then
shows as unused while something still uses it. File rows would hit this on every replace.

Moving only the rows that succeeded is not enough either. One post usually has several rows
for one image (`post_content` and `block`, or `postmeta` and `acf` for the same key). The first
row rewrites the whole value; the next re-reads the updated value, finds nothing to change and
reports failure, so it would stay on the old image although the post no longer uses it.

- Fix: drop the blanket `UPDATE`. After a real (not dry) run, re-index every source that was
  changed: `index_post()` for each post, `index_term()` for each term, and `index_options()`
  once if any option was changed. That rebuilds those rows from what the source now holds.
  Rows from sources that were not changed, files included, stay on the old image.
- `index_post()` auto-attaches when that setting is on, same as any save.
- The `unmam_references_replaced` action still fires.
- Dry run is unchanged.
- **Reproduce first.** On FLP, before touching the code: one image used in a post with a
  `wp:image` block (two rows) and in one row Replace Media cannot change; replace it; confirm
  every row moved. Snapshot and restore the rows. Only then fix.
- Not touched: the 1,000-reference page limit on the lookup.

## Other places

- REST `/scan/batch`, `wp unmam status` and the background processor's chain advance all read
  `get_active_scan_types()`. Nothing to add. A REST caller that runs `files` directly after it
  completed gets `completed`; a new pass starts from `/scan/start`.
- `uninstall.php`: add `unmam_file_scan_state`, `unmam_files_scanned_at` and
  `unmam_files_changed_at` to the options list. Rows go with the table drop, `scan_files` with
  `unmam_settings`.

## Not in this release

- Page builder CSS generated into `uploads`.
- Orphan files on disk with no library entry (Gal's separate request; needs a move-aside
  quarantine first).
- Rescans triggered by theme or plugin changes (a notice instead).
- URLs or IDs built in code at runtime, including `$uploads['baseurl'] . '/2024/...'`.
- Editing files from Replace Media, and edit links to the file editor.
- The same late-batch race in `scan_options()` and `scan_terms_batch()`, which rewind on
  `completed`. Their first batch is cheaper to redo, but note it in the ROADMAP.

## Testing (FLP, row snapshot, restore after)

FLP is off by default; start and stop it through Local's GraphQL API. Snapshot rows first
(see `dev/TESTING.md`). Never activate a theme on FLP without Sungraiz's OK; the fixture theme
and plugin stay inactive, which also exercises the inactive label. FLP is not multisite, so
the multisite capability rule is checked by reading core, not by a test.

Fixture `dev/fixture/files-seed.php`, `files-check.php`, `files-teardown.php`. Seed creates
the test attachments, a throwaway theme `unmam-fixture-theme`, a plugin `unmam-fixture-plugin`
and one mu-plugin file. Teardown removes all of it.

Must be found, each image used only there:

1. CSS absolute URL, root-relative URL and protocol-relative URL.
2. CSS `url(../../../uploads/{y}/{m}/x.jpg)` relative to the stylesheet, and PHP
   `content_url( '/uploads/{y}/{m}/x.jpg' )`.
3. A block theme `parts/sidebar.html` with an absolute URL (Gal's case, on disk, never edited).
4. A `theme.json` background image, a JSON file with `https:\/\/` and one with `https:\\\/\\\/`.
5. PHP `wp_get_attachment_image( ID )` and `wp_get_attachment_url( 'ID' )`.
6. `wp-image-{ID}` block markup in a pattern file.
7. A stylesheet over 1 MB with one URL past the first MB and one straddling the piece boundary.
8. The mu-plugin file.
9. A file named with invalid UTF-8 bytes, and one whose relative path is over 255 characters.

Must not count:

10. The same URLs inside `node_modules/`, `vendor/`, `.git/` and a `.md` file.
11. `wp_get_attachment_image( 999999 )`, not an attachment.
12. A file over 10 MB: skipped, shown in the notice and the CLI warning.
13. A symlink loop inside the fixture theme (completes, no hang) and a link to a folder outside
    the component, including a sibling named like `{component}-pro` (not followed).

Cursor and budget:

14. One direct pass, then the batch path through `full_scan()` and a `run_batch()` loop over
    `get_active_scan_types()` with a batch ceiling, limits forced to 3 files.
15. Folders `9` and `10`, and `a/` next to `a-b/` and `a.css`, with the cursor placed inside
    each: every file read exactly once.
16. A JSON file with 2,000 distinct URLs and the time limit forced to 1 second: the cursor
    resumes inside the file and the step completes.
17. Attempt count set to 2 on the cursor file by hand: the next batch skips it and reports
    "stopped the scan".
18. Mid-pass, add and delete a file that sorts before the cursor, and edit the cursor file:
    nothing after it is skipped.
19. After the pass completes, call `run_batch( 'files' )` again: `completed`, row count unchanged.
20. Pause and resume through `UNMAM_Background_Processor`.
21. Rescan without reset after editing a fixture file to point at a different image: old row
    gone, new row present.

Settings, notices and display:

22. Untick: file rows gone at once, step absent from the chain. Untick while the files step is
    running: it stops cleanly. Retick and scan: back.
23. Save round trip keeps the value. 1.3.3-shaped settings read under 1.4.0: on, and the
    "not scanned yet" notice shows. A saved `false` stays off across repeated reads.
24. Fire `upgrader_process_complete` for a plugin: "changed" notice. Full scan: gone.
25. Where Used in the media modal, REST and `wp unmam usage`: name, " (inactive)", path, no
    links. Delete the fixture plugin without rescanning: " (not installed)".
26. Permissions: a temporary contributor is refused by the usage AJAX; a temporary author
    sees "Theme or plugin file" and no `context_key` on their own image; an admin sees
    everything. Delete the temporary users afterwards.
27. Auto-attach on, full scan: no file-only image gets a parent, the Attach view lists none.
28. Replace Media: reproduce the reassignment bug first, then fix. Afterwards the post is
    updated and its rows point at the new image, the unchangeable row and the file row stay on
    the old image, the file is listed by path, and the old image is still used.

Real site:

29. Save the Unused list, run a full scan, diff, and check every image that left the list by
    hand. Expected: the logo hardcoded in the theme's `functions.php` and images from the
    theme's data and backup JSON.
30. Time the files step on its own.

Plugin Check through `dev/plugin-check.php`: zero new errors against 1.3.3. Uninstall leaves
none of the three new options.

## Release

1.4.0. Bump the header `Version:`, readme `Stable tag:` and `UNMAM_VERSION` together.

- readme.txt: rewrite the "might be used in ways not detected" list and the FAQ entry that
  says images hardcoded in PHP theme files are not detected. README.md's matching line too.
- readme.txt Upgrade Notice: "Run a full scan after updating." like every release that needed one.
- CLAUDE.md: file rows, the rewind exception, Replace Media re-indexing, the permission rule.
- ROADMAP: point the 1.4.0 section here; note the options and terms race.

Changelog, plain: "Images used only in theme or plugin files are now found: stylesheets, block
theme template files, theme settings files, and image addresses or IDs written straight into
theme code. Images a theme builds in code, which most themes do, still can't be seen, so check
anything that looks like a theme image before deleting it. Replace Media no longer marks an
image as unused in places it couldn't change. The Where Used lookup in the media screens now
checks the user's permissions."

Estimate: about three days.
