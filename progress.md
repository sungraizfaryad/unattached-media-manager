_Last updated: 2026-09-07._
_Mines and architecture in CLAUDE.md. Next release in `dev/ROADMAP.md`. Forum threads and
drafted replies in `dev/SUPPORT.md`._

## Where things stand

**1.3.1 is live** on WP.org (SVN r3685086) and GitHub (`main`, tag `1.3.1`). Two releases
shipped on 2026-09-07: 1.3.0 then 1.3.1.

## Done

- **1.3.0** — taxonomy term scanning, on by default with `scan_taxonomies` /
  `scan_taxonomies_known` reconciliation. New `UNMAM_Term_Parser_Interface` and
  `UNMAM_Reference_Extractor`. ACF widened to wysiwyg, textarea, text, url, link, oembed,
  icon_picker. Where Used and Replace Media handle terms. The three hardcoded scan-type lists
  now read `get_active_scan_types()`.
- **1.3.1** — downloadable files on WooCommerce product variations were never scanned, because
  a variable parent is not itself downloadable. Also fixed images on hidden or disabled
  variations (`get_available_variations()` returns only purchasable ones).

## Decisions

- Terms scanning ships **on by default**, against the roadmap's opt-in shape. Opt-in recreates
  the 1.2.0 post-type bug for anyone who never opens Settings.
- **No front-end code, for now.** The badge / usage observer / access-log ideas were designed
  and deferred: the plugin currently has zero public-facing footprint and that is a category
  change in risk for ~200 installs. Gal's file-quarantine request was deferred for the same
  reason plus the desync bugs Media Cleaner still has. See `dev/ROADMAP.md`.
- Two known over-reports accepted, both because the alternative under-reports: the hostless
  filename fallback in `url_to_attachment_id()`, and bare numeric meta values on arbitrary key
  names.

## Next

1. **Send the drafted replies** in `dev/SUPPORT.md` — Gal (two threads), Ade (never told his
   1.2.0 requests shipped, or that theme/CSS media is still undetected), kreativelabs (unverified).
2. Watch for reports about unused counts changing after 1.3.0.
3. **1.4.0: filesystem scanning**, or the verification feature if field reports justify it.

## Key files

- `includes/class-unmam-scanner.php` — scan chain, `scan_terms_batch()`, `index_term()`.
- `includes/class-unmam-reference-extractor.php` — shared media-in-text extraction.
- `includes/parsers/class-unmam-woocommerce-parser.php` — `collect_downloads()`, variations.
- `dev/SUPPORT.md`, `dev/ROADMAP.md`, `dev/TESTING.md`, `dev/specs/`.
