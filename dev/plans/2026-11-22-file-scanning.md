# Theme and Plugin File Scanning (1.4.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Images referenced only from theme, plugin or must-use plugin files stop being listed as unused, without letting one bad file stall a scan; Replace Media stops moving references it could not change; the usage AJAX endpoint checks permissions.

**Architecture:** A new `files` scan step, last in the chain, walks one theme or plugin at a time. A small walker class lists eligible files in a stable byte order after a cursor. The reference extractor is split into find and resolve so the step can stop between database lookups and resume inside a file. Rows are `source_type` `file`, shown through `describe_source()`, never attached, never edited by Replace Media.

**Tech Stack:** WordPress plugin, PHP 7.4+, WP 5.8+. No automated suite; each check is a `wp eval-file` script on the FLP Local site printing `ok` / `FAIL` and a failure count, same as 1.3.3.

**Spec:** `dev/specs/2026-11-22-file-scanning-design.md`. Read it in full before Task 1; this plan does not repeat its reasoning.

## Global Constraints

- Repo `~/Local Sites/plugins/unattached-media-manager/`, branch `feature/1.4.0-file-scanning`. All paths below are relative to it.
- Sungraiz must OK file edits and commits (Local Sites CLAUDE.md). Ask once at the start whether "commit as you go" is fine for the whole plan.
- Git identity `sungraizfaryad <sungraiz@gmail.com>`. Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Supports PHP 7.4 and WP 5.8: no `str_starts_with()`, no enums, no `readonly`, no named arguments.
- Code reads as human-written: short comments only where they earn it, no ALL-CAPS emphasis, no docblock on trivial getters.
- Never rename existing option keys, tables or `mui_` CSS classes (CLAUDE.md, mixed prefixes are intentional).
- Every path or name comparison in the walker uses `strcmp()` and `===`. Never `<`, `>`, `==` or `<=>` on file names.
- File reads use `file_get_contents( $path, false, null, $offset, $length )` only. No `fopen`, `fread`, `fclose`, `WP_Filesystem`.
- Exact values: piece size 1,048,576 bytes including a 4,096-byte overlap; file size cap 10 MB (10,485,760 bytes); depth cap 25; batch limits 200 files, 8 MB (8,388,608 bytes) read, 5,000 entries visited, 20 seconds; a file is skipped on its third attempt; skipped list keeps 100 entries plus a total.
- Extensions: `css scss sass less php html htm js json twig`. Skipped folder names: `node_modules`, `vendor`, `bower_components`, anything starting with `.`.
- New options, all autoload off: `unmam_file_scan_state`, `unmam_files_scanned_at`, `unmam_files_changed_at`. New setting key `scan_files` (bool, default true).
- Contexts: `theme_file`, `plugin_file`, `mu_plugin_file`. Labels "Theme file", "Plugin file", "Must-use plugin file".
- Fixture items are named `unmamfix-` / `UNMAMFIX` and removed by teardown. Never touch FLP's real media, themes or plugins. Never activate a theme on FLP.

## Review Focus

1. **A site whose uploads live under `/wp-content/uploads/sites/2` (multisite subsite) or a custom `UPLOADS` path.** The uploads tail anchor must be the baseurl path relative to `content_url()` when it is a prefix, else the last segment. Pinned in Task 2 (`tail anchor` checks).
2. **A host where `should_pause()` is already true when a batch starts.** The step must still move one unit per batch. Pinned in Task 5 (`forced pause still advances`).
3. **A plugin auto-update rewriting the cursor file between batches.** Size or mtime change resets the candidate index to 0, never skips. Pinned in Task 5 (`cursor file edited`).
4. **A file name that is not valid UTF-8, or a path over 255 characters.** The image must still be credited. Pinned in Task 5 (`bad utf8 name`, `long path`).
5. **An author opening the media modal on their own image used in a theme file.** Sees "Theme or plugin file", no path, no `context_key` in the AJAX response. Pinned in Task 6.

## File map

| File | Change | Responsibility |
|---|---|---|
| `includes/class-unmam-attachment-manager.php` | modify | re-index after replace instead of blanket UPDATE; file-context case |
| `includes/class-unmam-reference-extractor.php` | modify | `find_candidates()`, `resolve_candidate()`; `extract_from_text()` rebuilt on them |
| `includes/class-unmam-file-walker.php` | create | `UNMAM_File_Walker`: sorted, resumable, contained listing of eligible files |
| `includes/class-unmam-file-scanner.php` | create | `UNMAM_File_Scanner`: components, pieces, the `files` step, state, rows, describe, notices data, clear |
| `includes/class-unmam-scanner.php` | modify | `files` in `get_active_scan_types()`, `run_batch()`, `full_scan()`, progress weighting |
| `includes/class-unmam-database.php` | modify | `describe_source()` file branch |
| `includes/admin/class-unmam-media-modal.php` | modify | capability check, strip file row details |
| `includes/admin/class-unmam-admin.php` | modify | tickbox, save key, clear on untick, two notices |
| `includes/cli/class-unmam-cli-commands.php` | modify | skipped warning after `scan`, lines in `status` |
| `unattached-media-manager.php` | modify | require the two classes, default + lazy migration, change hooks, version |
| `uninstall.php` | modify | three new options |
| `dev/fixture/replace-reindex.php` | create | Task 1 check, self-cleaning |
| `dev/fixture/files-unit.php` | create | extractor, walker and piece checks in a temp folder, self-cleaning |
| `dev/fixture/files-seed.php`, `files-check.php`, `files-teardown.php` | create | fixture theme, plugin, mu-plugin and images; integration checks |
| `readme.txt`, `README.md`, `CLAUDE.md`, `dev/TESTING.md`, `dev/ROADMAP.md`, `progress.md` | modify | release notes and docs |

---

### Task 0: Start FLP, snapshot

**Files:** none

- [ ] **Step 1: Confirm branch**

```bash
cd ~/Local\ Sites/plugins/unattached-media-manager
git status --short --branch   # expect ## feature/1.4.0-file-scanning, clean
git config user.email         # expect sungraiz@gmail.com
```

- [ ] **Step 2: Start FLP** with the `startSite` mutation exactly as in Task 0 Step 2 of `dev/plans/2026-11-22-design-content-scanning.md` (site id `8-CWukao6`). Then `dev/flpwp.sh option get stylesheet` prints the active theme.

- [ ] **Step 3: Sync the plugin to FLP** with the rsync block in CLAUDE.md section 2. Repeat this after every code step below before running a check.

- [ ] **Step 4: Snapshot**

```bash
dev/flpwp.sh eval 'global $wpdb; $t = UNMAM_Database::get_table_name("references");
file_put_contents("/tmp/unmam140-refs.txt", implode("\n", $wpdb->get_col("SELECT CONCAT(attachment_id,\"|\",source_id,\"|\",source_type,\"|\",context_type,\"|\",IFNULL(context_key,\"\")) FROM $t ORDER BY id")));
file_put_contents("/tmp/unmam140-options.txt", implode("\n", $wpdb->get_col("SELECT option_name FROM {$wpdb->options}")));
file_put_contents("/tmp/unmam140-settings.txt", serialize(get_option("unmam_settings")));
file_put_contents("/tmp/unmam140-unused.txt", implode("\n", UNMAM_Database::get_all_unused_attachment_ids()));
echo "max post id: " . $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}") . "\n";'
ls ~/Local\ Sites/flp/app/public/wp-content/themes ~/Local\ Sites/flp/app/public/wp-content/plugins > /tmp/unmam140-dirs.txt
```

Expected: four files in `/tmp`, a max post id printed. Keep them until Task 9.

---

### Task 1: Replace Media re-indexes what it changed

Reproduce before fixing (spec, Replace Media).

**Files:**
- Create: `dev/fixture/replace-reindex.php`
- Modify: `includes/class-unmam-attachment-manager.php` (`replace_references()`, around lines 140-188)

**Interfaces:**
- Consumes: `UNMAM_Scanner::instance()->index_post( $post_id )`, `->index_term( $term_id )`, `->index_options()` (all existing).
- Produces: no new public API. Behaviour: after a non-dry run, rows of changed sources are rebuilt; all other rows keep their `attachment_id`.

- [ ] **Step 1: Write the check.** Self-cleaning script. It creates images A and B (`unmamfix-ri-a.png`, `unmamfix-ri-b.png`, same helper shape as `unmam_ds_image()` in `design-seed.php`), a published post whose content is a `wp:image` block for A (`<!-- wp:image {"id":A} --><figure class="wp-block-image"><img src="{A url}" class="wp-image-A"/></figure><!-- /wp:image -->`), indexes it, and inserts one extra row by hand: `attachment_id` A, `source_type` `custom_table`, `context_type` `custom_table`, `source_id` 999999, `context_key` `unmamfix.col`. Then `replace_references( A, B, false )`. Assertions:

```php
$ok( count( $rows_for_post_on( $a ) ) >= 2, 'precondition: post has 2+ rows for A' );   // before replace
// after replace:
$ok( false !== strpos( get_post( $pid )->post_content, 'wp-image-' . $b ), 'content now uses B' );
$ok( 0 === count( $rows_for_post_on( $a ) ), 'no post rows left on A' );
$ok( count( $rows_for_post_on( $b ) ) >= 2, 'post rows rebuilt on B' );
$ok( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE attachment_id = $a AND source_type = 'custom_table'" ), 'unchangeable row stays on A' );
$ok( 1 === UNMAM_Database::get_reference_count( $a ), 'A still used' );
```

Cleanup deletes the post, both attachments (`wp_delete_attachment( $id, true )`) and every row with `source_id` 999999 or either attachment id. Prints `failures: N`.

- [ ] **Step 2: Run it against the current code**

Run: `dev/flpwp.sh eval-file dev/fixture/replace-reindex.php`
Expected: `FAIL unchangeable row stays on A` and `FAIL A still used`. This is the reproduction. If they pass, stop and tell Sungraiz.

- [ ] **Step 3: Fix `replace_references()`**

Remove the `$wpdb->update()` that rewrites `attachment_id`. While looping, collect from each successful result: post ids (`source_type` `post`), term ids (`source_type` `term`), and whether any `context_type` was `option`. After the loop, when not a dry run, call `index_post()` for each post id, `index_term()` for each term id, `index_options()` once if an option changed. Keep the `unmam_references_replaced` action where it is.

- [ ] **Step 4: Run the check**

Run: `dev/flpwp.sh eval-file dev/fixture/replace-reindex.php`
Expected: all `ok`, `failures: 0`. Also re-run `dev/flpwp.sh eval-file dev/fixture/replace-slash.php`: still `failures: 0`.

- [ ] **Step 5: Commit** `includes/class-unmam-attachment-manager.php` and `dev/fixture/replace-reindex.php`: "Replace Media re-indexes what it changed instead of moving every row".

---

### Task 2: Extractor find and resolve

**Files:**
- Modify: `includes/class-unmam-reference-extractor.php`
- Create: `dev/fixture/files-unit.php` (section `extractor`)

**Interfaces:**
- Produces:
  - `UNMAM_Reference_Extractor::find_candidates( string $text, array $options = array() ): array` returning a list of `array( 'type' => 'id'|'url'|'exact_url', 'value' => int|string )`, first-seen order, de-duplicated on type plus value, empty for anything over `MAX_TEXT_LENGTH`. Options, all default false: `relative` (root- and protocol-relative upload URLs), `tails` (uploads tails), `calls` (literal IDs in core attachment calls).
  - `UNMAM_Reference_Extractor::resolve_candidate( array $candidate ): int` returning an attachment id or 0. `id` through `UNMAM_Database::is_attachment_id()`, `url` through `url_to_attachment_id( $value )`, `exact_url` through `url_to_attachment_id( $value, false )`.
  - `extract_from_text( $text, $allow_relative = false )` keeps its signature and its exact results.

- [ ] **Step 1: Write the `extractor` section of `files-unit.php`.** The script makes its own throwaway attachments (`unmamfix-u-1.png` .. `-3.png`), runs every section, deletes them, prints `failures: N`. Sections are added by Tasks 2 to 4; each is a block guarded by a `$section()` helper so a later task appends without touching earlier ones. Checks, with `$u1` the full URL of image 1 and `$p1` its path below the uploads baseurl (`2026/11/unmamfix-u-1.png`):

```php
// parity with today, recorded by running the current extract_from_text() first (Step 2)
$ok( $before === UNMAM_Reference_Extractor::extract_from_text( $mixed ), 'parity, absolute' );
$ok( $before_rel === UNMAM_Reference_Extractor::extract_from_text( $mixed, true ), 'parity, relative' );
// tails
$c = UNMAM_Reference_Extractor::find_candidates( "a{background:url(../../../uploads/$p1)}", array( 'tails' => true ) );
$ok( array( array( 'type' => 'exact_url', 'value' => $baseurl . '/' . $p1 ) ) === $c, 'tail from ../ path' );
$c = UNMAM_Reference_Extractor::find_candidates( "content_url( '/uploads/$p1' )", array( 'tails' => true ) );
$ok( 1 === count( $c ) && $id1 === UNMAM_Reference_Extractor::resolve_candidate( $c[0] ), 'tail from content_url()' );
$c = UNMAM_Reference_Extractor::find_candidates( $u1, array( 'relative' => true, 'tails' => true ) );
$ok( 1 === count( $c ) && 'url' === $c[0]['type'], 'full URL not matched again as a tail' );
$ok( array() === UNMAM_Reference_Extractor::find_candidates( "myuploads/$p1", array( 'tails' => true ) ), 'tail needs a boundary' );
// calls
$c = UNMAM_Reference_Extractor::find_candidates( "wp_get_attachment_image( $id1 ); wp_get_attachment_url( '$id2' ); wp_get_attachment_link( 0 );", array( 'calls' => true ) );
$ok( array( $id1, $id2 ) === array_values( array_filter( array_map( array( 'UNMAM_Reference_Extractor', 'resolve_candidate' ), $c ) ) ), 'literal ids in calls' );
$ok( 0 === UNMAM_Reference_Extractor::resolve_candidate( array( 'type' => 'id', 'value' => 999999 ) ), 'non-attachment id rejected' );
// tail anchor; $with_baseurl() adds an upload_dir filter returning that baseurl, runs the callback, removes it
$ok( 'uploads' === UNMAM_Reference_Extractor::tail_anchor(), 'tail anchor, default' );
$with_baseurl( home_url( '/wp-content/uploads/sites/2' ), function () use ( $ok ) {
	$ok( 'uploads/sites/2' === UNMAM_Reference_Extractor::tail_anchor(), 'tail anchor, multisite path' );
} );
$with_baseurl( home_url( '/files' ), function () use ( $ok ) {
	$ok( 'files' === UNMAM_Reference_Extractor::tail_anchor(), 'tail anchor, uploads outside wp-content' );
} );
$with_baseurl( 'https://cdn.example.com', function () use ( $ok, $p1 ) {
	$ok( '' === UNMAM_Reference_Extractor::tail_anchor(), 'tail anchor, uploads at a domain root' );
	$ok( array() === UNMAM_Reference_Extractor::find_candidates( "../uploads/$p1", array( 'tails' => true ) ), 'no tails without an anchor' );
} );
// size guard
$ok( array() === UNMAM_Reference_Extractor::find_candidates( str_repeat( ' ', 1048577 ) . $u1 ), 'over MAX_TEXT_LENGTH returns nothing' );
```

`$mixed` holds a `wp-image-{id1}` class, `$u1`, a root-relative copy of image 2's URL and a protocol-relative copy of image 3's.

- [ ] **Step 2: Record parity values.** Before changing the extractor, run a one-off `dev/flpwp.sh eval` that prints `var_export()` of `extract_from_text( $mixed )` and `extract_from_text( $mixed, true )` with the same fixture images, and paste the shape into the check as `$before` / `$before_rel` built from the fresh ids (keys are ids, values are the matched URL or the int id). Run the script: parity passes, the rest FAIL with undefined method.

- [ ] **Step 3: Implement.** In the extractor:
  - `find_candidates()` runs, in this order: `wp-image-(\d+)` → `id`; the existing absolute URL regex → `url`; when `relative`, the existing `relative_upload_urls()` matches made absolute → `url`; when `tails`, the tail regex → `exact_url`; when `calls`, the calls regex → `id`. Before the tail pass, every span already matched by the absolute and relative passes is blanked to spaces so it cannot match again.
  - `public static function tail_anchor(): string` returns `''` when the uploads baseurl has no path; otherwise the baseurl path with `wp_parse_url( content_url(), PHP_URL_PATH )` removed from its start when that is a prefix, else the last segment of the baseurl path. Always with a leading `/` stripped. `/wp-content/uploads` gives `uploads`; `/wp-content/uploads/sites/2` gives `uploads/sites/2`.
  - Tail regex: `'#(?<![\w-])' . preg_quote( $anchor, '#' ) . '/([^\s"\'<>()]+?\.(?:' . self::MEDIA_EXTENSIONS . '))#i'`; value is `untrailingslashit( baseurl ) . '/' . $m[1]`.
  - Calls regex: `'#\b(?:wp_get_attachment_image|wp_get_attachment_image_src|wp_get_attachment_image_url|wp_get_attachment_url|wp_get_attachment_link|wp_get_attachment_metadata|wp_get_attachment_caption|wp_get_attachment_image_srcset|wp_get_attachment_image_sizes|wp_get_attachment_thumb_url|wp_get_original_image_url|wp_get_original_image_path|get_attached_file|image_downsize)\s*\(\s*[\'"]?(\d+)[\'"]?\s*[,)]#'`.
  - `extract_from_text()` becomes find then resolve, keeping today's precedence: `wp-image` ids first, an absolute URL overwrites an id entry for the same attachment, a relative URL never overwrites. That is what the parity checks pin.

- [ ] **Step 4: Run**

Run: `dev/flpwp.sh eval-file dev/fixture/files-unit.php`
Expected: all `ok`, `failures: 0`. Then re-run `design-seed.php`, `design-check.php`, `design-teardown.php` (TESTING.md order): `failures: 0`, proving the design parser path is unchanged.

- [ ] **Step 5: Commit** the extractor and `files-unit.php`: "Split the reference extractor into find and resolve; add uploads tails and literal attachment ids".

---

### Task 3: The walker

**Files:**
- Create: `includes/class-unmam-file-walker.php`
- Modify: `unattached-media-manager.php` (`load_dependencies()`, after the extractor line)
- Modify: `dev/fixture/files-unit.php` (section `walker`)

**Interfaces:**
- Produces: `UNMAM_File_Walker::walk( string $root, string $after, int $max_files, int $max_entries ): array` returning `array( 'files' => string[], 'done' => bool, 'entries' => int, 'skipped' => array[] )`.
  - `$root` is an absolute path to a folder or a single file. `$after` is a relative path, `''` for the start; only files sorting strictly after it are returned.
  - `files` are relative paths built from `scandir()` names joined with `/`, in walk order.
  - `done` is true when the walk reached the end of the component.
  - `skipped` entries are `array( 'path' => string, 'reason' => 'too_big'|'unreadable'|'too_deep' )`, only for items after `$after`.
  - Constants: `MAX_FILE_BYTES = 10485760`, `MAX_DEPTH = 25`, `EXTENSIONS = array( 'css', 'scss', 'sass', 'less', 'php', 'html', 'htm', 'js', 'json', 'twig' )`, `SKIP_DIRS = array( 'node_modules', 'vendor', 'bower_components' )`.

- [ ] **Step 1: Write the `walker` section.** It builds a tree under `trailingslashit( get_temp_dir() ) . 'unmamfix-walk-' . getmypid()` and removes it at the end:

```
root/
  10/b.css   9/a.css   a/x.css   a-b/y.css   a.css   big.css (10,485,761 bytes)   doc.md
  node_modules/n.css   vendor/v.css   .git/g.css
  loop -> .            (symlink to root)
  out -> ../unmamfix-walk-{pid}-pro   (sibling folder holding p.css)
```

```php
$all = UNMAM_File_Walker::walk( $root, '', 1000, 100000 );
$ok( array( '10/b.css', '9/a.css', 'a/x.css', 'a-b/y.css', 'a.css' ) === $all['files'], 'byte order, skips, no loop, no escape' );
$ok( $all['done'], 'done' );
$ok( array( array( 'path' => 'big.css', 'reason' => 'too_big' ) ) === $all['skipped'], 'big file reported' );
// one at a time from every cursor position gives the same list exactly once
$seen = array(); $after = '';
do { $r = UNMAM_File_Walker::walk( $root, $after, 1, 100000 ); foreach ( $r['files'] as $f ) { $seen[] = $f; $after = $f; } } while ( ! $r['done'] );
$ok( $all['files'] === $seen, 'resume one file at a time' );
$ok( array( 'a/x.css', 'a-b/y.css', 'a.css' ) === UNMAM_File_Walker::walk( $root, '9/a.css', 1000, 100000 )['files'], 'resume after numeric folder' );
$ok( array( 'a-b/y.css', 'a.css' ) === UNMAM_File_Walker::walk( $root, 'a/x.css', 1000, 100000 )['files'], 'resume out of a/ into a-b/' );
$ok( array( 'a.css' ) === UNMAM_File_Walker::walk( $root, 'a-b/y.css', 1000, 100000 )['files'], 'resume out of a-b/' );
$ok( array( 'a/x.css', 'a-b/y.css', 'a.css' ) === UNMAM_File_Walker::walk( $root, 'a/missing.css', 1000, 100000 )['files'], 'deleted cursor file' );
$ok( array( 'a.css' ) === UNMAM_File_Walker::walk( $root . '/a.css', '', 10, 10 )['files'], 'single-file root' );
$ok( ! UNMAM_File_Walker::walk( $root, '', 1000, 3 )['done'], 'entry budget stops the walk' );
```

Why that order: `usort( $names, 'strcmp' )` on the root gives `.git`, `10`, `9`, `a`, `a-b`, `a.css`, `big.css`, `doc.md`, `loop`, `node_modules`, `out`, `vendor` (`1` is byte 49, `9` is 57; `-` is 45, `.` is 46), and a folder's files come when it is entered. A loose `'9' < '10'` comparison would put `9` first and fail the first check.

- [ ] **Step 2: Run** `dev/flpwp.sh eval-file dev/fixture/files-unit.php`. Expected: walker checks FAIL (class missing), extractor checks still `ok`.

- [ ] **Step 3: Implement `UNMAM_File_Walker`.** Recursive, `scandir()`, names cast to string, `usort( $names, 'strcmp' )`, skip `.` and `..`. Root goes through `realpath()` and `wp_normalize_path()`; a link inside is followed only when its normalized `realpath()` starts with `trailingslashit( $real_root )` and was not visited on this call. Resume: split `$after` on `/`; at each depth skip names where `strcmp( $name, $segment ) < 0`, descend into the one where `$name === $segment` carrying the rest, take all names after. A file exactly equal to `$after` is not returned. Unreadable folder or file: `unreadable`. Depth past `MAX_DEPTH`: `too_deep`. `filesize() > MAX_FILE_BYTES`: `too_big`. Every name looked at counts toward `entries`; stop with `done` false once `files` reaches `$max_files` or `entries` reaches `$max_entries`.

- [ ] **Step 4: Run** the unit script. Expected: `failures: 0`.

- [ ] **Step 5: Commit** walker, `load_dependencies()` line and the unit section: "Add a resumable, byte-ordered file walker".

---

### Task 4: Reading a file into candidates

**Files:**
- Create: `includes/class-unmam-file-scanner.php` (first methods only)
- Modify: `unattached-media-manager.php` (`load_dependencies()`, after the walker)
- Modify: `dev/fixture/files-unit.php` (section `pieces`)

**Interfaces:**
- Consumes: `find_candidates()` (Task 2).
- Produces:
  - `UNMAM_File_Scanner::PIECE_BYTES = 1048576`, `OVERLAP_BYTES = 4096`.
  - `UNMAM_File_Scanner::file_candidates( string $abs_path ): array`, the de-duplicated candidate list for the whole file, first-seen order across pieces. Options: `relative`, `tails` always; `calls` only for `.php`. Each piece is passed through `preg_replace( '#\\\\+/#', '/', $piece )` first. Returns `array()` for an unreadable file.

- [ ] **Step 1: Write the `pieces` section.** Temp files:

```php
// $u1 at byte 1,048,576 + 200 (second piece only), $u2 straddling byte 1,048,576, padding is spaces
$ok( in_array( array( 'type' => 'url', 'value' => $u1 ), UNMAM_File_Scanner::file_candidates( $big ), true ), 'URL past the first MB' );
$ok( in_array( array( 'type' => 'url', 'value' => $u2 ), UNMAM_File_Scanner::file_candidates( $big ), true ), 'URL across the boundary' );
$ok( 1 === count( array_filter( UNMAM_File_Scanner::file_candidates( $big ), function ( $c ) use ( $u2 ) { return $c['value'] === $u2; } ) ), 'boundary URL counted once' );
$ok( in_array( array( 'type' => 'url', 'value' => $u1 ), UNMAM_File_Scanner::file_candidates( $json1 ), true ), 'https:\/\/ escaped' );
$ok( in_array( array( 'type' => 'url', 'value' => $u1 ), UNMAM_File_Scanner::file_candidates( $json3 ), true ), 'https:\\\/ double escaped' );
$ok( ! array_filter( UNMAM_File_Scanner::file_candidates( $css_with_call ), function ( $c ) { return 'id' === $c['type']; } ), 'calls only in php' );
$ok( array() === UNMAM_File_Scanner::file_candidates( $root . '/does-not-exist.css' ), 'missing file' );
```

Also assert the piece arithmetic directly: a public static `piece_ranges( int $size ): array` returning `array( array( $offset, $length ), ... )`, checked for sizes 10, 1048576, 1048577 and 3,000,000: every length is at most 1,048,576, each later offset is the previous `offset + length - 4096`, and the last range ends at `$size`.

- [ ] **Step 2: Run.** Expected: `pieces` FAIL, earlier sections `ok`.

- [ ] **Step 3: Implement** `piece_ranges()` and `file_candidates()` in a new `UNMAM_File_Scanner` class (no constructor; static methods throughout). Read with `file_get_contents( $path, false, null, $offset, $length )` after `is_readable()`.

- [ ] **Step 4: Run.** Expected: `failures: 0`.

- [ ] **Step 5: Commit**: "Read theme and plugin files in overlapping pieces".

---

### Task 5: The `files` scan step

**Files:**
- Modify: `includes/class-unmam-file-scanner.php`
- Modify: `includes/class-unmam-scanner.php` (`run_batch()` line 144, `get_active_scan_types()` line 882, `full_scan()` line 903, `calculate_overall_progress()` line 1047)
- Modify: `unattached-media-manager.php` (activation defaults near line 153, lazy migration in `get_setting()` next to the `scan_custom_tables` one)
- Modify: `includes/admin/class-unmam-admin.php` (tickbox after `unmam_scan_options` around line 734; `save_settings()` literal array around line 1012)
- Create: `dev/fixture/files-seed.php`, `files-check.php`, `files-teardown.php`

**Interfaces:**
- Consumes: walker (Task 3), `file_candidates()` and `resolve_candidate()` (Tasks 2, 4), `UNMAM_Database::insert_reference()`, `delete_references_by_source_and_context()`, `update_scan_progress()`, `reset_scan_progress()`.
- Produces:
  - `UNMAM_File_Scanner::CONTEXTS = array( 'theme' => 'theme_file', 'plugin' => 'plugin_file', 'mu' => 'mu_plugin_file' )`, `STATE_OPTION = 'unmam_file_scan_state'`.
  - `UNMAM_File_Scanner::scan_batch(): array` returning `array( 'processed' => int, 'status' => 'running'|'completed', 'message' => string )`, the shape `run_batch()` returns.
  - `UNMAM_File_Scanner::components(): array` of `array( 'key' => string, 'type' => 'theme'|'plugin'|'mu', 'root' => string )`, sorted with `strcmp()` on key, own plugin folder excluded.
  - `UNMAM_File_Scanner::clear(): void` deletes the rows for the three contexts and the state option.
  - `UNMAM_File_Scanner::skipped(): array` returning `array( 'total' => int, 'items' => array[] )` from the state, items as `array( 'key', 'path', 'reason' )`, reasons `too_big|unreadable|too_deep|stopped`.
  - `UNMAM_File_Scanner::context_key( string $component_key, string $relative_path ): string`.
  - Setting `scan_files`; scan type `files`; option `unmam_files_scanned_at` (UTC `time()`) written on completion.
  - Filter `unmam_file_scan_batch_limits`, default `array( 'files' => 200, 'bytes' => 8388608, 'entries' => 5000, 'seconds' => 20 )`.

State option shape (autoload off):

```php
array(
	'components' => array( /* components() */ ),
	'index'      => 0,       // current component
	'path'       => '',      // file in progress when candidate > 0, else last finished file
	'candidate'  => 0,       // next candidate index inside 'path'
	'size'       => 0, 'mtime' => 0,   // of 'path' when candidate > 0
	'attempt_at' => '', 'attempts' => 0,
	'files'      => 0, 'refs' => 0,
	'skipped'    => array(), 'skipped_total' => 0,
)
```

- [ ] **Step 1: Write the fixture.**
  - `files-seed.php` refuses to run twice (option `unmamfix_files`), creates one image per case, then writes `wp-content/themes/unmam-fixture-theme/` (a block theme: `style.css` with `Theme Name: Unmam Fixture Theme`, `templates/index.html`, `parts/sidebar.html`, `theme.json`, `patterns/p.php`, `functions.php`, `css/rel.css`, `data/one.json`, `data/three.json`, `data/many.json` with 2,000 distinct URLs of which 20 are seeded images, `css/big.css` over 1 MB, `node_modules/n.css`, `vendor/v.css`, `.git/g.css`, `notes.md`, `huge.css` over 10 MB, `9/a.css`, `9/b.css`, `10/a.css`, `10/b.css`, a `loop` link to `.`, a file whose name contains byte `\xE9`, and a nested path making the relative path over 255 characters), `wp-content/plugins/unmam-fixture-plugin/unmam-fixture-plugin.php` (`Plugin Name: Unmam Fixture Plugin` plus a hardcoded URL) and `wp-content/mu-plugins/unmamfix-mu.php`. Each case's image appears in exactly one file, matching spec Testing items 1 to 13. Stores image ids, the case each belongs to and every path written in `unmamfix_files`.
  - `files-teardown.php` removes every path it wrote, the images, and the option.
  - `files-check.php` switches `auto_attach` on for the run (restored after), runs a full pass through `full_scan( false )` and a `run_batch()` loop over `get_active_scan_types()` with a 5,000-batch ceiling (a hang is a FAIL), then asserts per case. Optional args: `tiny` forces limits to `files` 3, `entries` 50, `seconds` 1 through the filter; `pause` makes `should_pause()` true for every batch by hooking the filter to `seconds` 0.

```php
foreach ( $fx['found'] as $case => $img ) {
	$ok( UNMAM_Database::get_reference_count( $img ) > 0, "found: $case" );
}
foreach ( $fx['ignored'] as $case => $img ) {
	$ok( 0 === UNMAM_Database::get_reference_count( $img ), "ignored: $case" );
}
$ok( 'theme:unmam-fixture-theme|parts/sidebar.html' === $key_for( $fx['found']['sidebar'] ), 'context_key format' );
$ok( 'theme_file' === $ctx_for( $fx['found']['sidebar'] ) && 'mu_plugin_file' === $ctx_for( $fx['found']['mu'] ), 'contexts' );
$ok( 255 >= mb_strlen( $key_for( $fx['found']['longpath'] ) ), 'long path: key fits' );
$ok( UNMAM_Database::get_reference_count( $fx['found']['badutf8'] ) > 0, 'bad utf8 name still credited' );
$ok( in_array( 'huge.css', wp_list_pluck( UNMAM_File_Scanner::skipped()['items'], 'path' ), true ), 'over 10 MB reported' );
$ok( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN ($fx_ids) AND post_parent > 0" ), 'never attached' );
$ok( in_array( 'files', UNMAM_Scanner::get_active_scan_types(), true ), 'files step active' );
$ok( 'files' === end( UNMAM_Scanner::get_active_scan_types() ), 'files step last' );
$before = $count_file_rows();
$ok( 'completed' === UNMAM_Scanner::instance()->run_batch( 'files' )['status'] && $before === $count_file_rows(), 'late batch after completion changes nothing' );
```

  Also in `files-check.php`, the cursor cases, each run as its own small pass with `tiny` limits:
  - `forced pause still advances`: with `pause`, the pass still completes inside the ceiling.
  - `cursor file edited`: stop a pass after the first batch that leaves `candidate > 0` in `data/many.json` (2,000 distinct URLs, seeded), append a line to that file, finish the pass: every image in `many.json` is found.
  - `cursor neighbours changed`: with `files` limited to 1, stop once the cursor is `10/a.css`, then delete `10/a.css` and create `10/0.css` (both sort at or before the cursor), finish: the images in `10/b.css`, `9/a.css` and `9/b.css` are all found.
  - `third attempt skips`: set `attempt_at` to the current position and `attempts` to 2 in the state by hand, run one batch: the cursor file is in `skipped()` with reason `stopped` and the pass moves on.
  - `rescan without reset`: edit `css/rel.css` to point at a different seeded image, `full_scan( false )` and loop again: old image's file row gone, new one present.

- [ ] **Step 2: Run** seed then check. Expected: FAIL throughout (step does not exist).

- [ ] **Step 3: Settings.** Activation defaults `'scan_files' => true`. In `get_setting()`, after the `scan_custom_tables` migration: missing key sets `true` and saves. In `save_settings()`'s literal array: `'scan_files' => isset( $_POST['unmam_scan_files'] ),`; after `update_option()`, call `UNMAM_File_Scanner::clear()` when it is false. Tickbox in the settings markup after `unmam_scan_options`, same markup, label "Theme and plugin files", description exactly: "Finds images used in theme and plugin stylesheets, templates and code. Images a theme builds in code are not found."

- [ ] **Step 4: Pipeline.** `get_active_scan_types()` appends `'files'` last when `! empty( $settings['scan_files'] )`. `run_batch()` gets `case 'files': $result = UNMAM_File_Scanner::scan_batch(); break;`. `full_scan()` always calls `UNMAM_Database::reset_scan_progress( 'files' )` and `delete_option( UNMAM_File_Scanner::STATE_OPTION )`, before its `$reset` branch. `calculate_overall_progress()` treats `files` like `posts` (weighted by `total_items` / `processed_items`).

- [ ] **Step 5: `scan_batch()`.** In order:
  1. Setting off: `clear()`, mark progress `completed`, return `completed`.
  2. Progress row `completed`: return `completed`, touch nothing (spec: the rewind exception).
  3. No progress row or no state: delete rows per context with `delete_references_by_source_and_context( 'file', $ctx )`, build `components()`, save state, write progress `running` with `total_items` = component count.
  4. Attempts: position string is `index|path|candidate`. Equal to `attempt_at` means `attempts + 1`, else `attempts = 1`. Save state now. At 3, skip the unit the batch would start with (the cursor file when `candidate > 0`, else the first file `walk( $root, $path, 1, ... )` returns), record `stopped`, reset attempts.
  5. Loop under the budget (checked after the first unit, before each file and between candidate lookups): finish the cursor file from `candidate` if `candidate > 0` (restart at 0 when its size or mtime changed), then take files from `walk()` after `path`, then move to the next component. For each file: `file_candidates()`, resolve from `candidate` on, insert each hit, save state after the file and after every 50 lookups. Walker `skipped` entries go into the state list (100 kept, total counted).
  6. When `index` passes the last component: progress `completed`, `update_option( 'unmam_files_scanned_at', time(), false )`, return `completed` with a message of counts. Otherwise progress `processed_items` = `index`, return `running`.

  Row insert: `source_type` `file`, `source_id` 0, context from `CONTEXTS`, `context_key` from `context_key()`, `context_label` as in Global Constraints, `reference_type` `id` or `url`, `reference_value` the candidate value. `context_key()` runs both parts through `wp_check_invalid_utf8( $s, true )`, joins with `|`, and over 255 characters (`mb_strlen()`) keeps the component key, `|…`, and the end of the path cut with `mb_substr()`. If `insert_reference()` returns false, retry once with `component_key . '|#' . md5( $relative_path )`. `reference_value` also goes through `wp_check_invalid_utf8()`.

  `components()`: `wp_get_themes( array( 'errors' => null ) )` keyed `theme:{stylesheet}` rooted at `get_stylesheet_directory()`; every non-dot folder and every `.php` file at the root of `WP_PLUGIN_DIR`, except `dirname( UNMAM_PLUGIN_BASENAME )`, keyed `plugin:{name}`; `mu` rooted at `WPMU_PLUGIN_DIR` when it is a folder.

- [ ] **Step 6: Run** seed, check, check `tiny`, check `pause`, teardown.
Expected: `failures: 0` each time. Also `wp unmam scan --reset` on FLP completes and `wp unmam status` lists `files`.

- [ ] **Step 7: Settings checks** (append to `files-check.php` as a `settings` arg mode): untick through a simulated `save_settings()` POST, file rows are gone and `files` is not in the chain; with `files` as the processor's current step and the setting off, one `run_batch( 'files' )` returns `completed`; retick and scan, rows back; a 1.3.3-shaped `unmam_settings` (no `scan_files`) reads back `true`; a saved `false` stays `false` over three reads. Restore `/tmp/unmam140-settings.txt` at the end. Expected: `failures: 0`.

- [ ] **Step 8: Commit**: "Scan theme and plugin files as the last scan step".

---

### Task 6: Where Used, permissions and Replace Media for file rows

**Files:**
- Modify: `includes/class-unmam-file-scanner.php` (`describe()`, plugin list helper)
- Modify: `includes/class-unmam-database.php` (`describe_source()`, line 431)
- Modify: `includes/admin/class-unmam-media-modal.php` (`ajax_get_usage()`, line 233)
- Modify: `includes/class-unmam-attachment-manager.php` (`replace_single_reference()`, line 202)
- Modify: `dev/fixture/files-check.php` (`display` arg mode)

**Interfaces:**
- Consumes: `CONTEXTS`, `context_key()` format (Task 5).
- Produces:
  - `UNMAM_File_Scanner::describe( string $context_key ): array` returning `array( 'title' => string, 'edit_link' => null, 'view_link' => null )`.
  - `UNMAM_File_Scanner::installed_plugins(): array`, `get_plugins()` keyed by folder (or file for single-file plugins) with `require_once ABSPATH . 'wp-admin/includes/plugin.php'` when needed, cached in a static for the request.
  - `UNMAM_File_Scanner::can_see_details(): bool`, `( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( 'activate_plugins' )`.

- [ ] **Step 1: Write the `display` checks.** With the fixture seeded and scanned:

```php
wp_set_current_user( $admin_id );
$ok( 'Unmam Fixture Theme (inactive): parts/sidebar.html' === $title_for( $fx['found']['sidebar'] ), 'admin title' );
$ok( 0 === strpos( $title_for( $fx['found']['mu'] ), 'Must-use plugins: ' ), 'mu title' );
$ok( null === $source_for( $fx['found']['sidebar'] )['edit_link'], 'no edit link' );
wp_set_current_user( $author_id );   // temporary author who owns the 'own' image
$ok( 'Theme or plugin file' === $title_for( $fx['found']['own'] ), 'author sees generic title' );
$ok( ! isset( $ajax_usage_as( $author_id, $fx['found']['own'] )['references'][0]['context_key'] ), 'author gets no context_key' );
$ok( false === $ajax_usage_as( $contributor_id, $fx['found']['own'] ), 'contributor refused' );
$ok( false === $ajax_usage_as( $author_id, $fx['found']['sidebar'] ), 'author refused on an image they cannot edit' );
// delete the fixture plugin folder only, no rescan
$ok( false !== strpos( $title_for( $fx['found']['plugin'] ), '(not installed)' ), 'removed plugin' );
$replace = UNMAM_Attachment_Manager::instance()->replace_references( $fx['found']['sidebar'], $fx['spare'], true );
$ok( false !== strpos( $replace['errors'][0]['error'], 'edit this file by hand: Unmam Fixture Theme (inactive): parts/sidebar.html' ), 'replace lists the file' );
```

`$ajax_usage_as()` sets the user, fills `$_POST` (`nonce` from `wp_create_nonce( 'unmam_media_modal_nonce' )`, `attachment_id`), forces `wp_doing_ajax` true and swaps the handler through `wp_die_ajax_handler` for one that throws, catches it after `ajax_get_usage()`, and returns the decoded `data` or false on an error response. The display mode creates a temporary author and a temporary contributor, sets the `functions` case image's `post_author` to the author and calls it `own`, and puts both users and the author back at the end. Also run `dev/flpwp.sh unmam usage {sidebar image id}` and expect the full title.

- [ ] **Step 2: Run** `files-check.php display`. Expected: FAIL.

- [ ] **Step 3: Implement.**
  - `describe()`: split `context_key` on the first `|`. `theme:{slug}` uses `wp_get_theme( $slug )`, missing when `! exists()`; inactive unless `$slug` is `get_stylesheet()` or `get_template()`. `plugin:{name}` uses `installed_plugins()`, inactive unless `is_plugin_active()` or `is_plugin_active_for_network()` is true for its main file. `mu` is "Must-use plugins". Title `sprintf( __( '%1$s: %2$s', ... ), $name_with_status, $path )`, where status adds ` (inactive)` or ` (not installed)` through translatable strings. Without `can_see_details()`, the title is `__( 'Theme or plugin file', ... )`.
  - `describe_source()`: as its first check, `if ( 'file' === $source_type ) return UNMAM_File_Scanner::describe( isset( $ref['context_key'] ) ? $ref['context_key'] : '' );`.
  - `ajax_get_usage()`: after the nonce, `wp_send_json_error( 'Permission denied' )` unless `current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $attachment_id )`. For rows with `source_type` `file`, unset `context_key` and `reference_value` unless `can_see_details()`.
  - `replace_single_reference()`: `case 'theme_file': case 'plugin_file': case 'mu_plugin_file':` sets `$result['error']` to `sprintf( __( 'Not changed, edit this file by hand: %s', ... ), UNMAM_File_Scanner::describe( $ref['context_key'] )['title'] )` and writes nothing.

- [ ] **Step 4: Run** `files-check.php display` and `replace-reindex.php`. Expected: `failures: 0` both.

- [ ] **Step 5: Commit**: "Show file references in Where Used, gate the usage lookup by capability".

---

### Task 7: Notices and CLI output

**Files:**
- Modify: `includes/class-unmam-file-scanner.php` (`mark_changed()`, `notice_state()`)
- Modify: `unattached-media-manager.php` (hooks next to the term hooks, around line 310)
- Modify: `includes/admin/class-unmam-admin.php` (`render_file_scan_notices()`, called from `render_admin_page()` after `render_unscanned_post_types_notice()`)
- Modify: `includes/cli/class-unmam-cli-commands.php` (`scan()` after the loop, `status()`)
- Modify: `dev/fixture/files-check.php` (`notices` arg mode)

**Interfaces:**
- Produces:
  - `UNMAM_File_Scanner::mark_changed(): void` sets `unmam_files_changed_at` to `time()`, autoload off.
  - `UNMAM_File_Scanner::notice_state(): array` returning `array( 'never_scanned' => bool, 'changed' => bool, 'skipped' => array )`, all false or empty when `scan_files` is off.
  - Hooks: `upgrader_process_complete` (2 args; only when `$hook_extra['type']` is `theme` or `plugin`), `deleted_plugin`, `deleted_theme`, all calling `mark_changed()`.

- [ ] **Step 1: Write the `notices` checks.**

```php
delete_option( 'unmam_files_scanned_at' );
$ok( UNMAM_File_Scanner::notice_state()['never_scanned'], 'never scanned' );
$run_full_pass();
$ok( ! UNMAM_File_Scanner::notice_state()['never_scanned'] && ! UNMAM_File_Scanner::notice_state()['changed'], 'clean after a pass' );
sleep( 1 );
do_action( 'upgrader_process_complete', null, array( 'type' => 'translation' ) );
$ok( ! UNMAM_File_Scanner::notice_state()['changed'], 'translation update ignored' );
do_action( 'upgrader_process_complete', null, array( 'type' => 'plugin', 'action' => 'update' ) );
$ok( UNMAM_File_Scanner::notice_state()['changed'], 'changed after a plugin update' );
$run_full_pass();
$ok( ! UNMAM_File_Scanner::notice_state()['changed'], 'cleared by the next pass' );
$ok( ! empty( UNMAM_File_Scanner::notice_state()['skipped']['items'] ), 'skipped list exposed' );
wp_set_current_user( $admin_id );
ob_start(); UNMAM_Admin::instance()->render_file_scan_notices( 'unused' ); $html = ob_get_clean();
$ok( false !== strpos( $html, 'could not be read' ) && false !== strpos( $html, 'huge.css' ), 'admin notice lists skipped file' );
```

`render_file_scan_notices( $current_tab )` is public for this; `UNMAM_Admin::instance()` exists. If the instance cannot be built in a CLI request, load `includes/admin/class-unmam-admin.php` first.

- [ ] **Step 2: Run.** Expected: FAIL.

- [ ] **Step 3: Implement.** Notice copy, exactly:
  - Never scanned: "Theme and plugin files have not been scanned yet. Run a full scan before deleting anything."
  - Changed: "Themes or plugins changed since the last scan. Run a scan before deleting anything."
  - Skipped: "%d theme or plugin files could not be read, so images used only in them may be listed as unused." followed by up to ten `{title}: {reason}` lines, `{title}` from `describe()` (so non-admins see the generic title) and reasons "over 10 MB", "unreadable", "folder too deep", "stopped the scan".
  - All three skip the settings tab, like the existing notice, and use `notice notice-warning`.
  - CLI `scan`: after the loop, `WP_CLI::warning()` with the skipped count and up to ten lines. CLI `status`: one line each for never scanned, changed, and skipped count.

- [ ] **Step 4: Run** `notices` mode and `dev/flpwp.sh unmam status`. Expected: `failures: 0`; status prints the three lines.

- [ ] **Step 5: Commit**: "Warn when theme or plugin files were not read or changed since the last scan".

---

### Task 8: Real site run, Plugin Check, uninstall

**Files:**
- Modify: `uninstall.php` (options list, line 44)

- [ ] **Step 1: Uninstall list.** Add `unmam_file_scan_state`, `unmam_files_scanned_at`, `unmam_files_changed_at`. Check by reading the multisite loop too (line 110 reuses `$options`).

- [ ] **Step 2: Full regression on FLP.** In order, every one `failures: 0`: `files-unit.php`; files seed, check, check `tiny`, check `pause`, check `settings`, check `display`, check `notices`, teardown; `replace-reindex.php`; `replace-slash.php`; design seed, check, check `noindex`, teardown; `design-upgrade-check.php`; the main fixture `seed.php`, `check.php`, `teardown.php` (TESTING.md).

- [ ] **Step 3: Real site.** Teardown done, so no fixture files remain. Run `wp unmam scan --reset` and time the files step on its own (`time` around a `run_batch( 'files' )` loop after the earlier steps). Diff `get_all_unused_attachment_ids()` against `/tmp/unmam140-unused.txt`. For every id that left the list, `wp unmam usage {id}` and confirm by eye that the file really references it. Expected: the logo from the theme's `functions.php`, images from its `data/` and `tools/backups/` JSON, nothing unexplained. Report the timing and the list to Sungraiz.

- [ ] **Step 4: Plugin Check** via `dev/plugin-check.php` as `dev/TESTING.md` describes, against the 1.3.3 tag. Expected: zero new errors (the four known `NotPrepared` stay).

- [ ] **Step 5: Restore.** Compare `/tmp/unmam140-options.txt` and `/tmp/unmam140-dirs.txt` with the current state: the only new option names are the three new ones; no `unmamfix` folder or option remains. Restore `unmam_settings` from `/tmp/unmam140-settings.txt` if it differs.

- [ ] **Step 6: Commit** `uninstall.php`: "Remove the file scan options on uninstall".

---

### Task 9: Docs and version

**Files:**
- Modify: `unattached-media-manager.php` (header `Version:`, `UNMAM_VERSION`), `readme.txt`, `README.md`, `CLAUDE.md`, `dev/TESTING.md`, `dev/ROADMAP.md`, `progress.md`

- [ ] **Step 1: Version 1.4.0** in the header, `UNMAM_VERSION` and readme `Stable tag:` together.

- [ ] **Step 2: readme.txt.** Changelog entry, the text from the spec's Release section. Upgrade Notice for 1.4.0: "Run a full scan after updating." Rewrite the "might be used in ways not detected" list (around line 229) and the FAQ entry on images hardcoded in PHP theme files (around line 286) to say files are now read but URLs built in code are not. README.md line 96 the same.

- [ ] **Step 3: CLAUDE.md.** Current release line. A "Files (1.4.0)" block under section 3 covering: file rows and their contexts; the `files` step rewinds in `full_scan()`, never on `completed`, and why; the walker's `strcmp()` rule; Replace Media re-indexes instead of moving rows; the usage AJAX capability check and `can_see_details()`. Architecture map: the walker and file scanner.

- [ ] **Step 4: TESTING.md** gets a "File scanning fixture (1.4.0)" block with the run order from Task 8 Step 2. **ROADMAP.md**: 1.4.0 points at the spec, marked built; add the options and terms late-batch race to "Smaller things". **progress.md**: Done, Decisions, Next.

- [ ] **Step 5: Commit**: "Release notes and docs for 1.4.0". No deploy; that needs Sungraiz's OK and follows `~/Local Sites/plugins/DEPLOY.md`.

- [ ] **Step 6: Stop FLP** with the `stopSite` mutation.
