_Last updated: 2026-09-05._
_Quick status. Mines and architecture in CLAUDE.md. Next-release design in `dev/ROADMAP.md`._

## Where things stand

1.2.0 is live on WP.org (SVN r3682339) and GitHub (`main`, tag `1.2.0`). It was a large
accuracy release: nine correctness fixes and three trash UX changes.

Two of those fixes were regressions introduced during the work itself and caught by review
before release, both in the dangerous direction. Worth remembering: the options step stopped
rescanning at all without `--reset`, and an ACF save dropped option references. See CLAUDE.md
section 3 for the rules that came out of it.

## Done

- **1.2.0** (LIVE). Reported unused but actually in use: stale `scan_post_types` snapshot, so
  post types registered after the plugin were never scanned; candidates filtered on `public`
  only, missing builder templates, `wp_block` and `wp_navigation`; `index_post()` and the
  batch scan disagreeing so a scan deleted what a save created; WooCommerce and SEO
  `parse_options()` never called; options sweep capped at 1000 rows and 5 name patterns
  (128 to 2268 options scanned on the test site); nested option URLs only found under 6 key
  names. Reported used but not in use: `url_to_attachment_id()` matching any value ending in
  the filename, so `A.png` matched `termmeta.png`; Meta Box treating every numeric field as an
  attachment ID; `wp-image-{ID}` and `data-id` trusted without checking. UX: Restore All,
  toolbar reordered, page size selector. Also fixed `wp unmam stats`, two i18n errors, and an
  over-long upgrade notice. Added a notice naming post types that hold content but are not
  scanned, since an install already broken by the snapshot bug cannot be repaired
  automatically without overriding someone's settings.
- **1.1.1** (LIVE). `EMPTY_TRASH_DAYS=0` data-loss guard, trash-view notices, corrected
  deletion guidance.
- **1.1.0** (LIVE). Unused-tab file URLs, Copy URL, CSV export, per-file Exclude.

## Decisions

- Terms scanning and filesystem scanning are separate releases, not one. Terms is a day and a
  half with two users waiting; filesystem is two days and its value is narrower than first
  assumed. Splitting gets Gal his fix weeks earlier.
- Post-type reconciliation stores `scan_post_types_known`, seeded on first run from the
  current candidate set. Seeding it from the admin's selection would silently re-enable every
  type they had unticked.
- Where a repair is impossible without guessing at intent, tell the admin rather than guess.
  That is why the unscanned-post-types notice exists instead of auto-enabling.
- Numeric IDs stay gated on known key names; only URLs are matched on any key. Matching bare
  numbers anywhere is what made Meta Box invent references.

## Next

- **1.3.0: terms scanning**, plus widening the ACF media field types. Designed and reviewed,
  see `dev/ROADMAP.md`, including the three things that will bite (cleanup collision with the
  WooCommerce term rows, Replace Media reading the wrong table, Where Used rendering).
- **1.4.0: filesystem scanning.** Same doc. Be honest in the changelog that it mainly catches
  CSS backgrounds, not dynamically built URLs.
- **Forum follow-ups, all in `dev/ROADMAP.md`.** @adeqx has not been told the theme/CSS gap is
  still open and may re-test and find his list still wrong. @galbaras should be told when he
  can drop his `wp_termmeta` workaround. @kreativelabs never confirmed the Bricks diagnosis;
  ask whether 1.2.0 fixed it.
- Expect forum traffic about unused counts changing on upgrade. 1.2.0 changes what counts as
  used on every install.

## Key files

- `includes/class-unmam-scanner.php` — scan chain, `get_active_scan_types()`, `scan_options()`.
- `includes/class-unmam-database.php` — `url_to_attachment_id()`, `is_attachment_id()`, unused
  queries, trash/restore/delete.
- `unattached-media-manager.php` — post-type candidates, settings reconciliation.
- `includes/admin/class-unmam-admin.php` — settings save handler, trash toolbar, notices.
- `dev/` — test harness and roadmap, never shipped.
