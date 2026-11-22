# Theme and plugin file scanning (1.4.0)

Approved 2026-11-22. Raised by @adeqx (could not trust the unused list) and @galbaras (block
theme sidebar image listed as unused). Starts from the reviewed outline in `dev/ROADMAP.md`,
with one change of approach: the scan walks one theme or plugin at a time with a file-name
cursor instead of storing a manifest of every file. The manifest's sharded options, their
uninstall cleanup and its staleness during a plugin auto-update all go away.

## Problem

The plugin only reads the database. An image referenced only from a theme or plugin file is
listed as unused and can be deleted while the site still shows it. The real cases:

- CSS `background-image` rules in theme and plugin stylesheets.
- Block theme templates and parts that were never edited in the Site Editor. 1.3.3 reads the
  edited copies in the database; the untouched ones only exist as `.html` files on disk. If
  Gal's sidebar part was never edited, 1.3.3 did not fix his case and this does.
- Upload URLs and attachment IDs hardcoded in a custom theme. FLP's own theme hardcodes its
  logo URL in `functions.php`.

**Temper the expectation.** Most themes build image URLs in code, through
`get_template_directory_uri()`, `wp_get_attachment_image( $id )` with a variable, ACF and so
on. None of that is visible to a text scan. The changelog says so plainly.

## Decisions (with Sungraiz, 2026-11-22)

1. On by default, for fresh installs and for sites upgrading in place.
2. Every installed theme and plugin is scanned, active or not. Where Used says which are inactive.
3. Literal attachment IDs passed to core's attachment functions in PHP count as finds.
4. Page builder CSS generated into `uploads` stays out. It is built from data already scanned,
   and stale copies would keep deleted pages' images marked used.
5. All settings and data files are read, JSON included, accepting the noise. On FLP the
   theme's `data/` and `tools/backups/` JSON holds up to 93 distinct upload URLs.
6. One theme or plugin at a time, cursor by file name (approach B).
7. The Replace Media reassignment bug below is fixed in this release.

## What gets scanned

**Components.** Built once at the start of a pass, sorted by key, and stored with the pass
state so a plugin installed mid-pass cannot shift positions:

| Component            | Key                    | Root                                       |
|----------------------|------------------------|--------------------------------------------|
| Each installed theme | `theme:{stylesheet}`   | `wp_get_themes()`, all registered roots    |
| Each installed plugin| `plugin:{folder}`      | `WP_PLUGIN_DIR/{folder}`                   |
| Single-file plugin   | `plugin:{file.php}`    | that one file                              |
| Must-use plugins     | `mu`                   | `WPMU_PLUGIN_DIR`, whole folder            |

- `get_plugins()` needs `wp-admin/includes/plugin.php`, which cron and loopback requests do
  not load. Require it.
- This plugin's own folder is left out (`dirname( UNMAM_PLUGIN_BASENAME )`). Its readme and
  parsers mention sample upload URLs.
- Never scanned: `wp-admin`, `wp-includes`, the uploads folder, drop-ins at the root of
  `wp-content`, `wp-content/languages`.

**Files.**

- Extensions, case-insensitive: `css scss sass less php html htm js json twig`.
- Skipped folders: `node_modules`, `vendor`, `bower_components`, and any folder whose name
  starts with a dot.
- Files are read in pieces of at most `UNMAM_Reference_Extractor::MAX_TEXT_LENGTH` (1 MB), each
  piece overlapping the previous by 4 KB so a URL across a boundary is not lost.
- A file over 10 MB is skipped and recorded. So is an unreadable file or folder. The step's
  final message gives the count and the first five paths, the way the options step reports
  its skipped total. FLP has one: a 14 MB admin stylesheet in a plugin's `dist` folder.
- Before extraction each piece gets `\/` replaced with `/`, as the design parser does, for
  JSON written with escaped slashes.

**Walk.**

- A small recursive walk of our own. Each folder is listed with `scandir()` and sorted with
  `sort( $entries, SORT_STRING )`, so the order is the same on every request.
- The cursor is the last finished file's path relative to the component root. Resuming
  compares segment by segment: entries that sort before the cursor's segment at that depth are
  skipped whole, the matching folder is entered, everything after is taken. Files added or
  removed mid-pass therefore never shift what comes next, unlike an offset. A deleted cursor
  file still works, the walk just takes the next entry after it.
- Each batch re-walks only the path down to the cursor, so resuming costs one listing per
  folder depth, not a listing of the whole component.
- Symlinks: the component root is resolved with `realpath()` first (Local and dev setups link
  plugin folders in from elsewhere, which is fine). Inside a component, a link is followed only
  if its target resolves inside the component's real root and that folder has not already been
  visited on this walk. Depth is capped at 25 as a backstop.
- A file is the unit of work. A batch never stops mid-file, so the cursor always names a
  finished file.

**Batch budget.** Stop after 200 files, or 8 MB read, or when
`UNMAM_Resource_Monitor::should_pause()` says time is short, whichever comes first. The limits
go through a filter, `unmam_file_scan_batch_limits`, which the tests use to force tiny batches.

## What counts as a find

For every piece of every file:

- `UNMAM_Reference_Extractor::extract_from_text( $piece, true )`: `wp-image-{ID}` classes,
  absolute media URLs, and root- and protocol-relative upload URLs. Validation is unchanged:
  IDs through `is_attachment_id()`, URLs through `url_to_attachment_id()`.
- New, `.php` files only: `UNMAM_Reference_Extractor::extract_attachment_calls( $text )`. A
  literal integer, quoted or not, as the first argument of `wp_get_attachment_image`,
  `wp_get_attachment_image_src`, `wp_get_attachment_image_url`, `wp_get_attachment_url`,
  `wp_get_attachment_link`, `wp_get_attachment_metadata`, `wp_get_attachment_caption`,
  `wp_get_attachment_image_srcset`, `wp_get_attachment_image_sizes`,
  `wp_get_attachment_thumb_url`, `wp_get_original_image_url`, `wp_get_original_image_path`,
  `get_attached_file` or `image_downsize`. Every number goes through `is_attachment_id()`.
  It lives in the extractor so there is still one copy of media-in-text matching. FLP has one
  hit, `wp_get_attachment_link( 0`, which validation rejects.
- No pre-filter. On FLP the scan reads 7,455 files and 83 MB and meets about 304 distinct media
  URLs to resolve. Resolved URLs are memoised for the request. The cost gets measured in testing.
- Finds are collected per file across its pieces and written once per attachment per file.

## Rows

- `source_type` `file`, `source_id` 0.
- `context_type` `theme_file`, `plugin_file` or `mu_plugin_file`.
- `context_key` is the component key and the relative path joined by `|`, for example
  `theme:genesis-block-theme|style.css`. Stylesheet slugs can contain `/`, hence the pipe.
  Over 255 characters, the middle of the path is dropped (`theme:x|…/end/of/path.css`) so the
  component key and file name survive. Truncated keys can collide; that only merges duplicate
  rows for the same attachment.
- `context_label` "Theme file", "Plugin file" or "Must-use plugin file".
- `reference_type` `url` or `id`, `reference_value` the matched URL or ID.
- Never attached. Every attach query and the used-but-unattached count require
  `source_type = 'post'`, and the file step never calls `auto_attach_media()`.

## The scan step

- New scan type `files`, appended last in `get_active_scan_types()` when `scan_files` is true.
- `run_batch()` gets `case 'files'`, which calls `UNMAM_File_Scanner::scan_batch()` in a new
  `includes/class-unmam-file-scanner.php`. The scanner class only dispatches; it is already
  1,087 lines.
- Pass state lives in one option, `unmam_file_scan_state`, autoload off: the component list,
  the current component index, the cursor path, files read, and the skipped list.
- The progress row uses `total_items` for the component count and `processed_items` for the
  index. `calculate_overall_progress()` weights `files` by those items the way it weights
  `posts`, instead of as the single unit every other step counts as, so the bar moves per
  theme or plugin. Next to a large posts count that is a small share of the bar; the scan
  screen already names the current step ("Files"), so no admin JS change.
- If `scan_files` is off when the step runs (unticked mid-scan, with `files` already the
  processor's current step), it clears the file rows and state and returns `completed`.
- A pass starts fresh when there is no progress row (a reset deleted it), when its status is
  `completed` (the rewind rule), or when the state option is missing. Starting fresh clears
  the previous rows with `delete_references_by_source_and_context()` for each of the three
  contexts, rebuilds the component list and empties the skipped list.
- Returns `running` until the last component is done, then `completed` with a message such as
  "Scanned 7,455 theme and plugin files, found 12 references. 1 file over 10 MB was skipped:
  …".
- A component deleted between passes keeps its rows until the next pass. That over-reports,
  which is safe. No rescan is triggered by theme or plugin installs, updates, deletes or
  switches.

## Settings

- New boolean `scan_files`.
- Activation defaults: `true`.
- Lazy migration in `get_setting()`: key missing means an upgrade, set `true`. A saved `false`
  stays `false`.
- `save_settings()` rebuilds the option from a literal array, so `scan_files` must be added
  there: `isset( $_POST['unmam_scan_files'] )`.
- Saving with it switched off deletes the file rows and the state option straight away, so
  unticking takes effect without a reset scan.
- One tickbox on the Settings tab, "Theme and plugin files", with help text: "Finds images
  used in theme and plugin stylesheets, templates and code. Images a theme builds in code are
  not found."

## Where Used

- `UNMAM_Database::describe_source()` gets a `file` branch, placed before the
  `source_id <= 0` bail. All four callers (media modal twice, REST, WP-CLI `usage`) go
  through it.
- The component is read from `context_key` and looked up live, so active status is current:
  theme name from `wp_get_theme()`, plugin name from `get_plugins()` (cached for the request),
  "Must-use plugins" for `mu`.
- Title: `{name}: {relative path}`, with " (inactive)" after the name when the theme is
  neither the active theme nor its parent, or the plugin is not active on this site or the
  network. A component no longer installed shows " (not installed)".
- No edit link and no view link. Many hosts disable the file editor, and editing live code
  from a link can break a site.
- `assets/js/media-modal.js` already renders a title without a link when `edit_link` is
  empty. No change.

## Replace Media

**File rows.** `replace_single_reference()` gets an explicit case for the three file
contexts. It never writes a file. It returns `success` false with the error "Not changed,
edit this file by hand: {component}: {path}", so `wp unmam replace` lists every file the admin
has to fix.

**Reassignment bug (pre-existing).** After the loop, `replace_references()` runs one
`UPDATE ... SET attachment_id = new WHERE attachment_id = old` whenever anything succeeded.
That moves every row of the old image to the new one, including the rows it could not change
(any context that hits the `default:` branch, or a content branch where the old URL was not
found). The old image then shows as unused while something still uses it. File rows would
hit this on every replace.

- Fix: update only the rows that were replaced, by their `id` (the query is `SELECT *`).
  Failed rows stay on the old attachment.
- Dry run is unchanged.
- **Reproduce first.** On FLP, before touching the code: one image used in a post and in one
  row Replace Media cannot change; replace it; confirm the old image's rows all moved. Snapshot
  and restore the rows. Only then fix.
- Not touched: the 1,000-reference page limit on the lookup.

## Other places

- REST `/scan/batch`, `wp unmam status` and the background processor's chain advance all read
  `get_active_scan_types()`. Nothing to add.
- `uninstall.php`: add `unmam_file_scan_state` to the options list. The rows go with the table
  drop and `scan_files` goes with `unmam_settings`.

## Not in this release

- Page builder CSS generated into `uploads`.
- Orphan files on disk with no library entry (Gal's separate request; needs a move-aside
  quarantine first).
- Rescans triggered by theme or plugin changes.
- URLs or IDs built in code at runtime.
- Editing files from Replace Media, and edit links to the file editor.

## Testing (FLP, row snapshot, restore after)

FLP is off by default; start and stop it through Local's GraphQL API. Snapshot rows first
(see `dev/TESTING.md`). Never activate a theme on FLP without Sungraiz's OK; the fixture theme
and plugin stay inactive, which also exercises the inactive label.

Fixture `dev/fixture/files-seed.php`, `files-check.php`, `files-teardown.php`. Seed creates
the test attachments, a throwaway theme `unmam-fixture-theme`, a plugin `unmam-fixture-plugin`
and one mu-plugin file. Teardown removes all of it.

Must be found, each image used only there:

1. CSS absolute URL, root-relative URL and protocol-relative URL.
2. A block theme `parts/sidebar.html` with an absolute URL (Gal's case, on disk, never edited).
3. A `theme.json` background image, and a JSON file with `https:\/\/` escaped slashes.
4. PHP `wp_get_attachment_image( ID )` and `wp_get_attachment_url( 'ID' )`.
5. `wp-image-{ID}` block markup in a pattern file.
6. A stylesheet over 1 MB with one URL past the first MB and one straddling the piece boundary.
7. The mu-plugin file.

Must not count:

8. The same URLs inside `node_modules/`, `vendor/`, `.git/` and a `.md` file.
9. `wp_get_attachment_image( 999999 )`, not an attachment.
10. A file over 10 MB: skipped, named in the step message.
11. A symlink loop inside the fixture theme (completes, no hang) and a link to a folder outside
    the component (not followed).

Runs:

12. One direct pass, then the batch path through `full_scan()` and a `run_batch()` loop over
    `get_active_scan_types()` with a batch ceiling, limits forced to 3 files so the cursor
    crosses folders and components.
13. Pause and resume through `UNMAM_Background_Processor`.
14. Mid-pass, add and delete a file that sorts before the cursor: nothing after it is skipped.
15. Rescan without reset after editing a fixture file to point at a different image: old row
    gone, new row present.

Settings and display:

16. Untick: file rows gone at once, step absent from the chain. Untick while the files step is
    running: it stops cleanly. Retick and scan: back.
17. Save round trip keeps the value. 1.3.3-shaped settings read under 1.4.0: on. A saved
    `false` stays off across repeated reads.
18. Where Used in the media modal, REST and `wp unmam usage`: name, " (inactive)", path, no
    links. Delete the fixture plugin without rescanning: " (not installed)".
19. Auto-attach on, full scan: no file-only image gets a parent, the Attach view lists none.
20. Replace Media: reproduce the reassignment bug first, then fix. Afterwards the post is
    updated, the file is listed by path, the old image is still used, and only the replaced
    rows moved.

Real site:

21. Save the Unused list, run a full scan, diff, and check every image that left the list by
    hand. Expected: the logo hardcoded in the theme's `functions.php` and images from the
    theme's data and backup JSON.
22. Time the files step on its own.

Plugin Check through `dev/plugin-check.php`, compared against 1.3.3. Uninstall leaves no
`unmam_file_scan_state`.

## Release

1.4.0. Bump the header `Version:`, readme `Stable tag:` and `UNMAM_VERSION` together. Update
the ROADMAP 1.4.0 section to point here.

Changelog, plain: "Images used only in theme or plugin files are now found: stylesheets, block
theme template files, theme settings files, and image addresses or IDs written straight into
theme code. Images a theme builds in code, which most themes do, still can't be seen, so check
anything that looks like a theme image before deleting it. Replace Media no longer marks an
image as unused in places it couldn't change."
