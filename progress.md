_Last updated: 2026-11-22._
_Mines and architecture in CLAUDE.md. Next release in `dev/ROADMAP.md`. Forum threads in `dev/SUPPORT.md`._

## Where things stand

**1.3.3 is live** (WP.org SVN r3816797, GitHub `main`, tag `1.3.3`, released 2026-11-22), 300+ installs.

## Done

- 1.3.3: Additional CSS, edited templates/parts and Site Editor styles scanned; Replace Media keeps
  backslashes, unserializes without classes, no longer hits `wp-image-123` when replacing 12.
- 1.3.3 tests on FLP: design fixture direct + batch + untick/retick, upgrade check, settings page,
  replace-slash, old fixture 6 of 7 (only `themecss`), Plugin Check identical to 1.3.2 (279 rows).
- 1.3.2 trash search, 1.3.0 terms scanning, 1.3.1 WooCommerce variation downloads (see changelog).

## Decisions

- Design posts are excluded from the dashboard used-but-unattached count too, not just the four
  attach queries the plan named, so the dashboard and the attach list agree.
- Design clause is held in a variable and interpolated; concatenating it added 10 Plugin Check errors.
- Gal's "Beyond the media library" (orphan files on disk) is NOT the 1.4.0 filesystem scan.

## Next

1. Forum replies (he drafts): Gemini23 x2 (trash deadline urgent), Gal orphan files, kreativelabs, Ade.
   Gal's sidebar-template case is fixed in 1.3.3.
2. 1.4.0 file scanning. REST enum worry is moot; reuse `extract_from_text( $text, true )`.
3. `CHANGELOG.md` ships but stops at 1.1.1; readme.txt is the real changelog. Backfill or drop it.

## Key files

- `dev/specs/2026-11-22-design-content-scanning-design.md`, `dev/plans/2026-11-22-design-content-scanning.md`.
- `dev/fixture/design-*.php`, `dev/fixture/replace-slash.php`, `dev/TESTING.md`.
