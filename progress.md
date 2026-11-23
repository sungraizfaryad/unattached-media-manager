_Last updated: 2026-11-23._
_Mines and architecture in CLAUDE.md. Next release in `dev/ROADMAP.md`. Forum threads in `dev/SUPPORT.md`._

## Where things stand

**1.4.0 is live** (WP.org SVN r3818095, GitHub `main`, tag `1.4.0`, released 2026-11-23), 300+ installs.

## Done

- 1.4.0: theme and plugin files scanned (stylesheets, block theme templates, settings files, literal URLs and IDs in code), `scan_files` setting on by default, two notices, Where Used for file rows.
- 1.4.0: Replace Media re-indexes changed sources instead of moving every row; file rows are reported, never written.
- 1.4.0: per-item permission checks on every media AJAX action and the usage lookup; Replace Media is `manage_options` only.
- FLP: files step 4.6 s in 37 batches over 7,356 entries; 88 images left the unused list, 0 entered; Plugin Check shows nothing new.

## Decisions

- Sungraiz's: helper agents for the build, one final whole-branch reviewer, permission fix inside 1.4.0 with Replace Media admin only.
- Files step rewinds in `full_scan()`, never on `completed`. Options and terms still have that race: ROADMAP.
- Gal's "Beyond the media library" (orphan files on disk) is NOT file scanning.

## Next

1. Gemini23 x2 replies approved 2026-11-23, not posted: Chrome's "Allow JavaScript from Apple Events" was off. Trash deadline one is urgent (thread from 2026-11-10).
2. Other forum replies (he drafts): Gal orphan files, kreativelabs, Ade.
3. 1.4.1 list in `dev/ROADMAP.md`, plus: pass token for late batches, multisite `changed_at` as a site option, refused count in the upload.php bulk notice, drop the dead bulk trash/restore/delete handlers.
4. `CHANGELOG.md` ships but stops at 1.1.1; readme.txt is the real changelog. Backfill or drop it.

Key files: the 1.4.0 spec and plan in `dev/`, `includes/class-unmam-file-*.php`, `dev/fixture/files-*.php`, `perms-check.php`.
