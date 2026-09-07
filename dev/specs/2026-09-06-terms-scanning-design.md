# 1.3.0 Terms scanning, design

Written 2026-09-06 after reading the 1.2.0 source against `dev/ROADMAP.md`. This is the
approved shape; the roadmap entry is the background.

## Why

Nothing in the plugin reads term meta. ACF fields on a taxonomy term (image, gallery,
WYSIWYG) are invisible, so media used only there is reported unused. @galbaras confirmed the
diagnosis by adding `wp_termmeta.meta_value` as a custom table and watching the problem
vanish. 1.2.0 was verified on his site with that workaround still in place. 1.3.0 makes the
workaround unnecessary.

Governing rule, unchanged: under-reporting usage deletes live files; over-reporting is an
annoyance. When in doubt, keep more media marked as used. But every new match must still be
a real match: IDs validated with `UNMAM_Database::is_attachment_id()`, URLs resolved through
`url_to_attachment_id()`, no bare numbers treated as IDs outside known keys.

## Decisions made during design

- **Terms scanning is on by default**, for fresh installs and on upgrade. Opt-in (the
  roadmap's shape) recreates the class of bug 1.2.0 fixed for post types: a silent gap for
  anyone who never opens Settings. The pipeline gains one fast step; the reconciliation
  pattern already exists.
- **ACF is not installed on FLP.** The generic term-meta path is tested there with the
  existing fixture. The ACF path is tested by copying ACF Pro from another Local site into
  FLP for the test run and removing it after.
- **The meta parser gets a text-extraction fallback** for string values that fail every
  existing check. Without it the fixture's WYSIWYG term case cannot pass on a site without
  ACF, and postmeta holding HTML on posts has the same blind spot today. The fallback is
  gated on the value already looking like HTML or an uploads path.
- **Spec lives in `dev/specs/`**, not `docs/`. `dev` is in `.svnignore`; `docs` would ship.

## What was checked in source and differs from the roadmap

- `get_field( $name, 'term_' . $id, false )` is the current ACF form (since 5.5). The
  `{taxonomy}_{id}` form is still accepted as an alias, so no legacy branch is needed.
  Pre-5.5 values lived in `wp_options` and the options sweep already covers those.
- `acf/save_post` passes `'term_123'` for a term save. Today `on_acf_save()` schedules
  `index_post( 'term_123' )`, which `get_post()` turns into a silent no-op.
- ACF Group and Repeater nesting works as-is: `get_field( 'group_sub', $id )` resolves via
  the `_group_sub` reference key. The roadmap's worry does not reproduce.
- `extract_attachment_refs()` is private on the custom-table parser. It moves to a shared
  class now because three callers need it after this release.
- The admin progress UI (`admin.js`) names no scan types. Only the three PHP lists need
  retiring.
- `UNMAM_Database::delete_references_by_source_and_context()` exists and its docblock
  already anticipates the terms step. A per-source variant is needed for `index_term()`.

## 1. Settings

New keys in `unmam_settings`:

- `scan_taxonomies`: string[] of taxonomy slugs.
- `scan_taxonomies_known`: string[] of slugs already offered to the admin.

Candidates come from a new `unmam_get_scannable_taxonomy_candidates()` in the main plugin
file: union of `get_taxonomies( [ 'public' => true ] )` and `get_taxonomies( [ 'show_ui' =>
true ] )`, minus `post_format` and `link_category`, through filter
`unmam_scannable_taxonomies`.

Reconciliation in `Unattached_Media_Manager::get_setting()`, same algorithm as
`scan_post_types`:

- Both keys absent (first read after upgrade, or fresh install): `scan_taxonomies` =
  candidates, `scan_taxonomies_known` = candidates. Persist.
- `known` present: anything in candidates but not in `known` is newly registered, gets added
  to `scan_taxonomies` and to `known`. Anything the admin unticked stays unticked.

Settings UI in `render_settings_content()`: a "Taxonomies" block after Post Types, split
built-in/custom like post types, same red warning about unticking. Field name
`unmam_scan_taxonomy[]`.

`save_settings()`: intersect submitted slugs with candidates, then add **both**
`scan_taxonomies` and `scan_taxonomies_known` to the literal settings array. Any key not in
that array is destroyed on save.

No "unscanned taxonomies" notice. Reconciliation auto-enables new taxonomies, so there is
nothing to warn about.

## 2. Pipeline

`UNMAM_Scanner::get_active_scan_types()` returns `posts, options, widgets, terms,
custom_tables`, with `terms` appended only when `scan_taxonomies` is a non-empty array and
`custom_tables` appended as today. The background processor advances by name, so a scan
already running during the upgrade is unaffected.

`run_batch()` gains `case 'terms'` calling `scan_terms_batch( $batch_size )`.

`scan_terms_batch()`:

- Progress row `terms`, cursor in `last_processed_id`.
- If the stored status is `completed`, rewind the cursor to 0. Same rule as
  `scan_options()`; a rescan without `--reset` must re-read everything.
- On cursor 0: `delete_references_by_source_and_context( 'term', 'term_meta' )` and the same
  for `term_acf`. Never a bare `source_type = 'term'` delete; the WooCommerce parser writes
  category thumbnails as `term`/`woocommerce` rows during the options step. Set
  `total_items` to the count of `term_taxonomy` rows in the selected taxonomies,
  `processed_items` 0, `started_at`.
- Query: `term_id, taxonomy` from `wp_terms` joined to `wp_term_taxonomy`, `term_id >
  cursor`, `taxonomy IN (allow-list)`, ordered by `term_id, taxonomy`, limited to the batch
  size. Empty page means `completed`.
- Each row: `index_term( $term_id, $taxonomy )`. Honour the resource monitor's pause check.
  Return `running` with the new cursor and processed count.
- Known edge: a pre-WP-4.2 shared `term_id` in two selected taxonomies can straddle a batch
  boundary and lose the second row. Accepted; split terms have been the default since 2015.

`index_term( $term_id, $taxonomy = null )`, public:

- Resolve `$taxonomy` via `get_term( $term_id )` when null (the term-meta hooks only give
  the object id).
- Bail unless the taxonomy is in `scan_taxonomies`. This is the batch-versus-live agreement
  rule from `index_post()`.
- Clear this term's own rows with new
  `UNMAM_Database::delete_references_by_source_in_contexts( $term_id, 'term', [ 'term_meta',
  'term_acf' ] )`.
- Run `parse_term( $term )` on every parser that is an instance of
  `UNMAM_Term_Parser_Interface`. Insert each reference.
- **Never auto-attach.** A term is not a post.
- Fire `unmam_term_indexed( $term_id, $references )`.

`get_scan_status()` loops `get_active_scan_types()` instead of naming steps. Terms count as
one unit in `calculate_overall_progress()`, like options, so the overall percentage math for
existing installs does not change shape.

## 3. Parsers

### `UNMAM_Term_Parser_Interface`

New file `includes/parsers/class-unmam-term-parser-interface.php`, one method
`parse_term( $term )` taking a `WP_Term` and returning reference arrays. Loaded in
`load_dependencies()`. The existing `UNMAM_Parser_Interface` is untouched; third-party
parsers registered through `unmam_parsers` keep working.

### `UNMAM_Reference_Extractor`

New file `includes/class-unmam-reference-extractor.php`, one public static
`extract_from_text( $text )` returning `attachment_id => matched` (a URL string or the int
ID). Body moved from `UNMAM_Custom_Table_Parser::extract_attachment_refs()`, which now
delegates. `wp-image-{ID}` hits go through `is_attachment_id()`. Media URLs go through
`url_to_attachment_id()`.

### `UNMAM_Meta_Parser`

Implements both interfaces. `parse_meta_value()` and `create_reference()` take a source
descriptor (`id`, `type`, `context_type`, label format) instead of a post id.

- `parse_post()`: unchanged behaviour, source `post` / `postmeta`.
- `parse_term()`: `get_term_meta( $term_id )`, same skip patterns, source `term` /
  `term_meta`, label "Term Meta: key".
- New last-resort branch in `parse_meta_value()`: a string that is not numeric, not a CSV of
  IDs, not a direct media URL, not serialized, not JSON, **and** contains `wp-image-` or
  `/wp-content/uploads/` is passed to `UNMAM_Reference_Extractor::extract_from_text()`.
  Strings over 1MB are skipped. Applies to postmeta as well as term meta.

Reference rows for terms use `context_type` `term_meta`, never `postmeta`. Reusing
`postmeta` would make `replace_single_reference()` read and write post meta with a term id.

### `UNMAM_ACF_Parser`

Implements both interfaces. The `$post_id` parameter threaded through `parse_fields()`,
`parse_media_field()`, `parse_container_field()`, `parse_repeater_field()`,
`parse_flexible_content_field()` and `parse_group_field()` becomes `$acf_id` (int for posts,
`'term_N'` for terms) plus a source descriptor. `get_field( $name, $acf_id, false )` handles
both. The repeater row-count fallback uses `acf_get_metadata( $acf_id, $name )` when that
function exists, else `get_post_meta()` for integer ids only.

- `parse_post()`: unchanged, source `post` / `acf`.
- `parse_term()`: ACF inactive returns empty (the meta parser already reads raw termmeta).
  Otherwise `acf_get_field_groups( [ 'taxonomy' => $term->taxonomy ] )`, then
  `parse_fields( $fields, 'term_' . $term_id, source term / term_acf )`.

Field-type widening, posts and terms alike. New `$text_field_types = wysiwyg, textarea,
text, url, link, oembed, icon_picker` routed to a new `parse_text_field()`:

- `link`: value is an array, use `['url']`.
- `icon_picker` (ACF 6.3+): array with `type` and `value`; `media_library` gives an ID
  (validated), `url` gives a URL, `dashicons` is skipped.
- `url`, `oembed`: single string, resolve with `url_to_attachment_id()`. External URLs
  resolve to 0 and are dropped.
- `wysiwyg`, `textarea`, `text`: `UNMAM_Reference_Extractor::extract_from_text()`.

Labels follow the existing "ACF Type: Field label" form.

### Known duplicates, accepted

- WooCommerce `thumbnail_id` on a product category now yields a `woocommerce` row from the
  options step and a `term_meta` row from the terms step. Two rows, one file, both true.
- An ACF image field on a term yields `term_acf` and `term_meta` rows, exactly as posts
  yield `acf` and `postmeta` today.

## 4. Live hooks

In `Unattached_Media_Manager::init_hooks()`:

- `created_term` and `edited_term` (`$term_id, $tt_id, $taxonomy`): schedule
  `unmam_index_single_term` in 5 seconds with `[ $term_id, $taxonomy ]`, like posts.
- `added_term_meta`, `updated_term_meta`, `deleted_term_meta` (`$meta_id(s), $object_id,
  ...`): schedule the same event with `[ $object_id ]`; `index_term()` resolves the taxonomy.
- `delete_term` (`$term, $tt_id, $taxonomy, ...`): `delete_references_by_source( $term,
  'term' )`, all contexts. The term is gone, so the WooCommerce row goes too.
- `acf/save_post`: when `$post_id` starts with `term_`, schedule `index_term` for the
  numeric part instead of `index_post`.

New handler `index_single_term( $term_id, $taxonomy = null )` on the main class.

`uninstall.php` needs no new option names; the new keys live inside `unmam_settings`.

## 5. Where Used and Replace Media

New `UNMAM_Database::describe_source( $ref )` returning `[ 'title', 'edit_link',
'view_link' ]` for `source_type` `post` (current behaviour) and `term` (term name plus the
taxonomy's singular label, `get_edit_term_link( $term_id, $taxonomy )`,
`get_term_link()`), or null for anything else.

The five renderers use it:

- `class-unmam-media-modal.php` `add_usage_field()` and `ajax_get_usage()`
- `class-unmam-rest-controller.php` `get_attachment_usage()`
- `class-unmam-cli-commands.php` `usage()`
- `assets/js/media-modal.js`: the branch condition becomes "has `source_title`" rather than
  `source_type === 'post'`, with `edit_link` optional.

`UNMAM_Attachment_Manager::replace_single_reference()` gains `case 'term_meta': case
'term_acf':` using `get_term_meta()` / `update_term_meta()` on `source_id` and
`context_key`, mirroring the postmeta case. Dry run respected.

Auto-attach paths (`attach_all_used_media()`, `get_used_but_unattached()`, statistics)
already filter on `source_type = 'post'`. No change.

## 6. Hardcoded scan-type lists retired

- REST `/scan/batch` `enum` → `UNMAM_Scanner::get_active_scan_types()`.
- `wp unmam status` → loops `get_active_scan_types()`.
- `UNMAM_Scanner::get_scan_status()` → loops `get_active_scan_types()`.

## 7. Release

Bump `Version:` header, readme `Stable tag:` and `UNMAM_VERSION` to 1.3.0 together. Readme
changelog states plainly: taxonomy terms are now scanned, on by default; the unused count
may drop on upgrade; the `wp_termmeta.meta_value` custom-table workaround can be removed.

## 8. Testing

Branch `feature/1.3.0-terms`. Sync to FLP with the rsync in CLAUDE.md section 2.

Fixture (`dev/TESTING.md` flow): expect 6 of 7 detected. Cases `termmeta`, `termwysiwyg`,
`termthumb` pass; `themecss` remains for 1.4.0. `unmam_tax` must be picked up by
reconciliation without touching Settings, which also tests section 1.

Release checks, each against the dangerous direction:

- Upgrade path: write `unmam_settings` in the 1.2.0 shape, read once, confirm both taxonomy
  keys seeded with all candidates. Untick one, save, read three times, confirm it stays
  unticked. Register a new taxonomy (harness), read, confirm it is added.
- Settings round trip: every key survives `save_settings()`.
- Chain: `full_scan()` then `run_batch()` over `get_active_scan_types()` with a batch
  ceiling until every step reports `completed`.
- Rescan without `--reset` after changing a term meta value: old reference gone, new one
  present.
- Live hooks: `wp term meta update`, run the scheduled event, confirm rows; `wp term delete`,
  confirm rows gone.
- Gal parity: scan once with `wp_termmeta.meta_value` as a custom table, once with the native
  terms step. Every attachment the workaround credited from termmeta must be credited by the
  native step.
- ACF: copy ACF Pro from another Local site into FLP, activate for the run (ask first).
  Field group on `unmam_tax` with image, gallery, wysiwyg, link, url, icon_picker, a repeater
  with an image subfield, a group with an image subfield. Fill on the fixture term, scan,
  confirm `term_acf` rows. Field group on posts with a wysiwyg field holding an upload,
  confirm an `acf` row. Deactivate and remove ACF after.
- Where Used: admin-ajax with a generated cookie for an attachment referenced only by a term,
  confirm `source_title` and `edit_link`; `wp unmam usage` shows the term name.
- Replace Media dry run on a term reference reports the meta update without writing.
- Plugin Check against 1.2.0; the four known `NotPrepared` errors are expected, no new ones.
- `EMPTY_TRASH_DAYS=0` still refuses to trash (unchanged code, cheap to confirm).

## 9. Docs

CLAUDE.md section 3 gains: the term interface, the `term_meta` / `term_acf` context types
and why they must not be `postmeta` / `acf`, the extractor as the single copy, the
`delete_references_by_source_in_contexts()` rule for `index_term()`. Section 4 lists the two
new files. `progress.md`, `dev/ROADMAP.md` (move 1.3.0 to done, update the forum table),
`dev/TESTING.md` (expected 6 of 7, the ACF step) and readme changelog all updated.

## Out of scope

Filesystem scanning (1.4.0). Menus (`nav_menu` is neither public nor `show_ui`, so it is not
a candidate; ACF menu fields are rare). Comment meta and user meta.
