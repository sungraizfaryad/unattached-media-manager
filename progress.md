_Last updated: 2026-11-22._
_Mines and architecture in CLAUDE.md. Next release in `dev/ROADMAP.md`. Forum threads in `dev/SUPPORT.md`._

## Where things stand

**1.3.3 is live** (WP.org SVN r3816797, GitHub `main`, tag `1.3.3`, released 2026-11-22), 300+ installs.

## Done

- 1.3.3: Additional CSS, edited templates/parts and Site Editor styles scanned; Replace Media keeps
  backslashes, unserializes without classes, no longer hits `wp-image-123` when replacing 12.
- 1.3.2 trash search, 1.3.0 terms scanning, 1.3.1 WooCommerce variation downloads (see changelog).

## Decisions

- Gal's "Beyond the media library" (orphan files on disk) is NOT the 1.4.0 filesystem scan.
- 1.4.0 also fixes Replace Media moving rows it could not change, and the usage AJAX capability gap.

## Next

1. Forum replies (he drafts): Gemini23 x2 (trash deadline urgent), Gal orphan files, kreativelabs, Ade.
   Gal's sidebar is fixed by 1.3.3 only if he edited it in the Site Editor; on disk it needs 1.4.0.
2. 1.4.0 file scanning: spec and plan approved 2026-11-22 on `feature/1.4.0-file-scanning`, not built.
   Build with subagent-driven execution in a fresh chat (handoff note has the paste line).
3. `CHANGELOG.md` ships but stops at 1.1.1; readme.txt is the real changelog. Backfill or drop it.

## Key files

- `dev/specs/2026-11-22-file-scanning-design.md`, `dev/plans/2026-11-22-file-scanning.md` (1.4.0).
- 1.3.3: `dev/specs/` and `dev/plans/` dated 2026-11-22 design-content, `dev/fixture/design-*.php`, `dev/TESTING.md`.
