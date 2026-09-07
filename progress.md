_Last updated: 2026-09-06._
_Quick status. Mines and architecture in CLAUDE.md. Remaining release design in `dev/ROADMAP.md`._

## Where things stand

1.2.0 is live. @galbaras re-tested it against an independent SEO Macroscope crawl and found
every file on the unused list genuinely unused, with his `wp_termmeta.meta_value` workaround
still in place. That confirms the accuracy work but not the terms gap, which is what 1.3.0 closes.

1.3.0 is **built but not tested**, on `feature/1.3.0-terms`. Do not deploy it.

## Done

- **1.3.0 (code complete, unverified).** New `terms` scan step, on by default, gated on
  `scan_taxonomies` with `scan_taxonomies_known` reconciliation. New
  `UNMAM_Term_Parser_Interface` implemented by the meta and ACF parsers. New
  `UNMAM_Reference_Extractor` as the single copy of the media-in-text regex. ACF widened to
  wysiwyg, textarea, text, url, link, oembed, icon_picker on posts and terms. Where Used and
  Replace Media handle terms. The three hardcoded scan-type lists now read
  `get_active_scan_types()`.
- **1.2.0** (LIVE). Nine correctness fixes, three trash UX changes. See git history.

## Decisions

- Terms scanning ships **on by default**, against the roadmap's opt-in shape. Opt-in recreates
  the 1.2.0 post-type bug for anyone who never opens Settings.
- The meta parser's URL branch now falls through when it fails to resolve. Review found that
  `looks_like_media_url()` swallowed every WYSIWYG blob and returned early, so the new
  extractor fallback was dead code for the exact case it was written for.
- Two known over-reports are accepted, both because the alternative under-reports: the
  hostless filename fallback in `url_to_attachment_id()`, and bare numeric meta values on
  arbitrary key names. Both documented in CLAUDE.md section 3.

## Next

1. **Test 1.3.0 against FLP.** Nothing has been run yet. `dev/TESTING.md` has the plan;
   fixture should go from 3 of 7 to 6 of 7. ACF is not installed on FLP and must be copied in.
2. Tell @galbaras when he can drop the `wp_termmeta` workaround. Tell @adeqx the theme/CSS gap
   is still open. Ask @kreativelabs whether 1.2.0 fixed Bricks.
3. **1.4.0: filesystem scanning.** `dev/ROADMAP.md`.

## Key files

- `includes/class-unmam-scanner.php` — scan chain, `scan_terms_batch()`, `index_term()`.
- `includes/class-unmam-reference-extractor.php` — shared media-in-text extraction.
- `includes/parsers/class-unmam-meta-parser.php` — term meta, and the branch-order fix.
- `unattached-media-manager.php` — taxonomy candidates, settings reconciliation, term hooks.
- `dev/specs/2026-09-06-terms-scanning-design.md` — the approved 1.3.0 design.
