# Roadmap

Written after shipping 1.2.0 on 2026-09-05. Both remaining pieces have been designed and
adversarially reviewed already; this is the surviving conclusion, not a fresh guess.

---

## 1.3.0 — Terms scanning

**Why:** nothing in the plugin reads term meta. ACF fields on a taxonomy term, including a
WYSIWYG editor field, are invisible, so media used only there is reported unused. Reported
by @galbaras on the WordPress.org forum, who confirmed the diagnosis by adding
`wp_termmeta.meta_value` through the 1.0.9 custom-tables feature and watching the problem
disappear. He is waiting on this and should be told when he can drop that workaround.

### Shape

A new scan type, gated on a new `scan_taxonomies` setting so installs that never opt in keep
the exact pipeline they have today. `wp_terms.term_id` is a monotonic bigint, so the cursor
pattern in `scan_posts_batch()` transfers directly.

- `get_active_scan_types()` appends `terms` only when `scan_taxonomies` is non-empty. Mirror
  how `custom_tables` is appended. Never append unconditionally.
- `run_batch()` gains a `terms` case.
- New `scan_terms_batch()` and `index_term()`.
- New `UNMAM_Term_Parser_Interface` with `parse_term( $term )`, alongside the existing
  interface rather than changing it. `UNMAM_Meta_Parser` and `UNMAM_ACF_Parser` implement it.
- ACF terms: `acf_get_field_groups( array( 'taxonomy' => $term->taxonomy ) )`, values read
  with `get_field( $name, 'term_' . $term_id, false )`. Confirm the legacy
  `{taxonomy}_{term_id}` format is not still in use on older ACF before dropping it.
- References use `source_type` `term` and `context_type` `term_meta` / `term_acf`.
- Terms must never auto-attach. A term is not a post. Same rule as `custom_table`.
- Settings: a taxonomy checklist mirroring the post types one. **It must be added to the
  literal array in `save_settings()`**, see the mines in CLAUDE.md.
- Live hooks for parity with posts: `edited_term` and `created_term` to `index_term()`,
  `delete_term` to a reference cleanup.

### Three things that will bite

1. **Cleanup collision.** `index_term()` must not delete by `source_type = 'term'` alone.
   The WooCommerce parser already writes category thumbnails as term rows during the options
   step, and a bare source-type delete wipes them. Use
   `UNMAM_Database::delete_references_by_source_and_context()`, which exists for this reason.
2. **Replace Media.** `UNMAM_Attachment_Manager::replace_single_reference()` branches on
   `context_type` only, then calls `get_post_meta()` / `update_post_meta()` on `source_id`.
   Give it a term branch, or an explicit bail, before term rows can exist. Otherwise it reads
   and writes the wrong table silently.
3. **Where Used.** 1.2.0 stopped it rendering a wrong post title for non-post rows, but it
   now renders nothing useful for a term. It needs the term name plus `get_edit_term_link()`.
   There are four PHP copies of this rendering (`class-unmam-media-modal.php` twice, the REST
   controller, the CLI `usage` command) and one in `assets/js/media-modal.js`.

### Bundle with it

Widening the ACF media field types is half a day and only pays off once terms exist, because
on posts the generic meta parser already catches WYSIWYG content as a fallback. On terms
there is no fallback.

Add `wysiwyg`, `textarea`, `text`, `url`, `link`, `oembed` and ACF 6.1+'s `icon_picker`.
These are text-shaped, so they need a third routing bucket that extracts URLs and
`wp-image-{ID}` classes, not the existing ID-shaped `parse_media_field()`. Reuse the
extraction in `UNMAM_Custom_Table_Parser::extract_attachment_refs()` rather than writing a
third copy of that regex. Watch for double counting against the generic meta parser.

The review flagged that ACF Group-nested subfields may already be mishandled for existing
image fields. Worth checking while in there.

**Estimate:** about a day and a half, plus half a day for the ACF widening.

---

## 1.4.0 — Filesystem scanning

**Why:** the plugin cannot see media referenced from files, only from the database. CSS
`background-image` rules are the common real case. Raised by @adeqx, who had several GB of
history to audit and could not trust the unused list.

### Temper the expectation

An earlier estimate that this recovers ~70% of what a full page crawl would find is too
optimistic, and the review was right to push back. Most theme code builds URLs dynamically
through `get_template_directory_uri()` or `wp_get_attachment_image()`, and a grep for
literal upload URLs will not see any of that. The genuine win is stylesheets. Say that
plainly in the changelog rather than overselling it.

### Shape

- A new opt-in scan type, appended last in the chain so the fast steps finish first.
- Two phases, because directory listing order is not stable across requests and a recursive
  walk cannot be resumed by offset: build a file manifest once, store it, then page through
  it with the cursor.
- Scan roots: `wp-content/themes`, `wp-content/plugins`, `wp-content/mu-plugins`. Never
  `wp-admin` or `wp-includes`. Scanning `.css` inside `uploads` should be a separate opt-in,
  default off.
- Skip `node_modules`, `vendor`, `.git`, minified bundles and anything over a size cap.
- **Symlink loop protection is required** and was missing from the original plan.
- Extract the shared regex into `UNMAM_Reference_Extractor` and have the custom-table parser
  delegate to it, so there is one copy.
- `source_type` for these rows must not be `post`, and they must never auto-attach.
- `uninstall.php` must clean the sharded manifest options; the existing exact-name list and
  the transient wildcard will not catch them.
- The REST `/scan/batch` endpoint has a hardcoded scan-type enum that needs the new type
  added. The original plan missed this.

**Estimate:** about two days.

---

## Smaller things, good filler alongside a release

- Four pre-existing `WordPress.DB.PreparedSQL.NotPrepared` Plugin Check errors in
  `get_unused_attachments_detailed()`. Present since well before 1.2.0.
- The admin page heading still reads "All-in-One Media Solution", the plugin's original name.
  User visible, unlike the internal prefixes, which are deliberate.
- **Three hardcoded scan-type lists, all already stale.** `wp unmam status`
  (`class-unmam-cli-commands.php:616`), the REST `/scan/batch` endpoint's `enum`
  (`class-unmam-rest-controller.php:108`) and `UNMAM_Scanner::get_scan_status()` all name
  steps individually, and the first two are already missing `custom_tables` today. That means
  a REST caller cannot run the custom-tables step at all. Point them at
  `get_active_scan_types()`; otherwise every new scan type needs three separate edits and
  will be forgotten in at least one.
- `unmam_bulk_delete_unused` is registered but nothing in the admin JS calls it.

---

## Open forum threads

| Who | Issue | Status |
|---|---|---|
| @adeqx | Trashed media still rendering; restore was painful | Answered. Restore All, toolbar order and page size shipped in 1.2.0. **Not yet told that theme/CSS references are still unhandled**, which may be his actual cause. Tell him before he re-tests. |
| @galbaras | ACF fields on WooCommerce product categories | Diagnosis confirmed by him. Workaround in place. Waiting on 1.3.0. Tell him when the `wp_termmeta` entry can be removed. |
| @kreativelabs | Bricks Builder templates not scanned | Diagnosed as the post-type snapshot plus `public`-only filtering, both fixed in 1.2.0. **Unverified**, he never confirmed. Ask whether 1.2.0 fixes it. |
