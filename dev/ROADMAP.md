# Roadmap

Written after shipping 1.2.0 on 2026-09-05. Both remaining pieces have been designed and
adversarially reviewed already; this is the surviving conclusion, not a fresh guess.

---

## 1.3.0 — Terms scanning (BUILT, NOT TESTED)

Implemented on `feature/1.3.0-terms`. The design that was actually built is
`dev/specs/2026-09-06-terms-scanning-design.md`, which supersedes the plan that used to sit
here. Two things changed from that plan during implementation:

- **Terms scanning ships on by default**, not opt-in. Opt-in recreates the class of bug 1.2.0
  fixed for post types: a silent gap for anyone who never opens Settings.
- **The meta parser gained a text-extraction fallback**, so a WYSIWYG value in term meta is
  found even with ACF absent. Review then found the fallback was unreachable, because
  `looks_like_media_url()` claimed the whole markup blob and returned early. Fixed by only
  returning early when the URL branch actually produced a reference.

All three "things that will bite" were handled and verified by review: the cleanup collision
with the WooCommerce term rows, Replace Media's context-type-only branching, and the five
Where Used renderers. See CLAUDE.md section 3.

**Nothing has been tested against FLP yet.** That is the next task. `dev/TESTING.md` has the
plan, including copying ACF Pro in, since FLP does not have it.

### Deferred out of 1.3.0, deliberately

- **External URLs, partially fixed.** `url_to_attachment_id()`'s filename fallback has no host
  check, so a link to someone else's `banner.jpg` credits ours. 1.3.0 gates it for the ACF
  `url` / `link` / `oembed` / `icon_picker` fields via `url_points_at_this_site()`, which
  still allows same-host, host-relative and any-host-with-an-uploads-path URLs so CDNs and
  moved domains keep resolving.
  **It is bypassed and testing proved it.** ACF stores a `link` as a serialized array with a
  `url` key, so `UNMAM_Meta_Parser::parse_complex_value()` credits the same external URL again
  through the `term_meta` row. Closing it properly means gating the fallback for all post meta
  on every install. That is the under-reporting direction, it changes what counts as used for
  existing sites, and 1.3.0 already moves that number a lot, so it would muddy attribution if
  anyone reports a problem. Own release, own changelog line, own test pass.
- The generic meta parser treats a bare numeric meta value as an attachment ID on any key
  name, not just known ones, whenever it resolves to a real attachment. This contradicts the
  "numeric IDs stay gated on known key names" rule that the options parser follows, and term
  meta now reaches it too. Tightening it would drop genuine references from every plugin that
  uses its own key names, so it needs its own release and its own testing, not a bundled fix.

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

- Four pre-existing `WordPress.DB.PreparedSQL.NotPrepared` Plugin Check errors, present since
  well before 1.2.0: three in `get_unused_attachments_detailed()`
  (`class-unmam-database.php` 555, 557, 571) and one in
  `class-unmam-custom-table-parser.php:157`. All four are dynamically built SQL with the
  values bound separately, which is why they were left alone.
- The admin page heading still reads "All-in-One Media Solution", the plugin's original name.
  User visible, unlike the internal prefixes, which are deliberate.
- `unmam_bulk_delete_unused` is registered but nothing in the admin JS calls it.
- The REST `/scan/batch` `type` arg declares an `enum` but no `validate_callback`, and
  WordPress only enforces `enum` when one is present. So an unknown type is accepted and
  falls through `run_batch()`'s `default:` case to a no-op that reports `running` forever.
  Pre-existing, harmless (the endpoint is admin-only), cosmetic to fix: add
  `'validate_callback' => 'rest_validate_request_arg'`.

---

## Open forum threads

| Who | Issue | Status |
|---|---|---|
| @adeqx | Trashed media still rendering; restore was painful | Answered. Restore All, toolbar order and page size shipped in 1.2.0. **Not yet told that theme/CSS references are still unhandled**, which may be his actual cause. Tell him before he re-tests. |
| @galbaras | WooCommerce variation downloads reported unused | Confirmed and fixed in 1.3.1. He supplied a reproduction snippet. Also fixed images on hidden/disabled variations, found while verifying. |
| @galbaras | ACF fields on WooCommerce product categories | Diagnosis confirmed by him. He re-tested 1.2.0 against an SEO Macroscope crawl and found the unused list accurate, but still with the `wp_termmeta` workaround in place. 1.3.0 is built, not yet tested. Tell him when the entry can be removed. |
| @kreativelabs | Bricks Builder templates not scanned | Diagnosed as the post-type snapshot plus `public`-only filtering, both fixed in 1.2.0. **Unverified**, he never confirmed. Ask whether 1.2.0 fixes it. |
