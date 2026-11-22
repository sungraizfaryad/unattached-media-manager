_Last updated: 2026-11-22._
_Mines and architecture in CLAUDE.md. Next release in `dev/ROADMAP.md`. Forum threads in `dev/SUPPORT.md`._

## Where things stand

**1.3.2 is live** (WP.org SVN r3816583, GitHub `main`, tag `1.3.2`, released 2026-11-22), 300+ installs.
**1.3.3 is planned, not built**, on branch `feature/1.3.3-design-content` (spec + plan committed).

## Done

- 1.3.2 trash search: shared `list_filter_clause()`, whole-trash buttons hidden while filtered,
  Auto-delete column. Spec: `dev/specs/2026-11-22-trash-search-design.md`.
- 1.3.0 terms scanning, 1.3.1 WooCommerce variation downloads (see changelog).

## Decisions

- 1.3.3 before 1.4.0: Additional CSS, edited templates/parts and Site Editor styles were never
  scanned (not public, no show_ui). On by default as tick boxes; deleted themes skipped; never
  attached. Bundles two Replace Media fixes: backslash stripping, wp-image-12 hitting wp-image-123.
- Gal's "Beyond the media library" (orphan files on disk) is NOT the 1.4.0 filesystem scan.

## Next

1. **Build 1.3.3** from `dev/plans/2026-11-22-design-content-scanning.md`. No code yet.
2. Forum replies (he drafts): Gemini23 x2 (trash deadline urgent), Gal orphan files, kreativelabs, Ade.
3. 1.4.0 file scanning after 1.3.3. REST enum worry is moot; reuse `extract_from_text( $text, true )`.
4. `CHANGELOG.md` ships but stops at 1.1.1; readme.txt is the real changelog. Backfill or drop it.

## Key files

- `dev/specs/2026-11-22-design-content-scanning-design.md`, `dev/plans/2026-11-22-design-content-scanning.md`.
- `dev/SUPPORT.md`, `dev/ROADMAP.md`, `dev/TESTING.md`.
