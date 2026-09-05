# Testing this plugin

There are no automated tests. Everything is verified by hand against a Local by Flywheel
site. This folder holds the harness used for the 1.2.0 release so it does not have to be
rebuilt from scratch each time.

`dev/` is listed in `.svnignore`, so nothing here ships to WordPress.org.

## Test site

FLP (`~/Local Sites/flp/`) is the site used. It is a real site with ~510 attachments, ~8000
posts, Meta Box, ACF and Yoast active, which is far more useful than an empty install.

**Never touch its real media.** Create throwaway attachments with a recognisable prefix and
delete them afterwards.

## WP-CLI

`dev/flpwp.sh` wraps wp-cli with the Local MySQL socket and a raised memory limit. The run
id in it is site-specific and will change if the site is recreated:

```bash
RUN="$HOME/Library/Application Support/Local/run"
for d in "$RUN"/*/; do grep -rqs "flp" "$d/conf" 2>/dev/null && echo "$d"; done
```

512M is not enough once WooCommerce is installed; the wrapper uses 1024M.

## The fixture

`dev/fixture/` reproduces every known scanner gap at once.

| File | Purpose |
|---|---|
| `unmam-test-harness.php` | mu-plugin registering a public CPT, a `show_ui`-only CPT (Bricks-shaped) and a taxonomy |
| `seed.php` | Creates media, posts, terms, an oddly-named option and a theme CSS file referencing an upload |
| `check.php` | Prints a pass/fail table of which gaps are detected, plus orphan rows and the unused count |
| `teardown.php` | Removes everything the seed created |

```bash
cp dev/fixture/unmam-test-harness.php ~/Local\ Sites/flp/app/public/wp-content/mu-plugins/
dev/flpwp.sh eval-file dev/fixture/seed.php
dev/flpwp.sh unmam scan --reset --batch-size=200
dev/flpwp.sh eval-file dev/fixture/check.php
dev/flpwp.sh eval-file dev/fixture/teardown.php
rm ~/Local\ Sites/flp/app/public/wp-content/mu-plugins/unmam-test-harness.php
```

As of 1.2.0, `check.php` should show 3 of 7 detected. The 4 still undetected are the
3 term cases (1.3.0) and the theme CSS case (1.4.0). If a case that used to pass starts
failing, that is a regression.

## Admin UI without a browser

Playwright is often unavailable. Generating a WordPress auth cookie is enough to fetch and
inspect admin HTML with curl, and it makes no changes to the site:

```bash
dev/flpwp.sh eval '$u=get_users(array("role"=>"administrator","number"=>1))[0]; $e=time()+3600;
echo COOKIEHASH."\n".wp_generate_auth_cookie($u->ID,$e,"logged_in")."\n".wp_generate_auth_cookie($u->ID,$e,"secure_auth")."\n";'
```

Write those into a cookie jar as `wordpress_logged_in_<HASH>` and `wordpress_sec_<HASH>`,
then `curl -sk -b jar https://flp.local/wp-admin/admin.php?page=unattached-media-manager`.
Delete the jar afterwards. The same cookies drive `admin-ajax.php`, which is how Restore All
was verified end to end; the nonce is in the `unmamAdmin` object in the page source.

## Things worth testing every release

- Upgrade path: set `unmam_settings` to a pre-release shape with a post type deliberately
  unticked, then read the settings back several times. It must stay unticked.
- Settings save round trip. `save_settings()` rebuilds the whole option, so any new key not
  listed there is silently lost on save. This has already shipped as a bug once, in 1.0.7.
- Scan chain: run `run_batch()` in a loop over `get_active_scan_types()` until every step
  reports `completed`, with a batch ceiling so a hang shows up as a failure rather than
  hanging the test. Drive it through `full_scan()`, not `run_batch()` alone, or the progress
  percentage is meaningless.
- Pause, resume and stop through `UNMAM_Background_Processor`, not the scanner directly.
  `pause_scan()` only acts when the processor state is `running`, which only `start_scan()`
  sets.
- Rescan without `--reset` after changing an existing option's value. This is where the
  options cursor bug lived.
- `EMPTY_TRASH_DAYS=0` must still refuse to trash. Pass it with wp-cli's `--exec` flag
  rather than editing `wp-config.php`.
- WordPress.org Plugin Check, compared against the previous release rather than read as an
  absolute. Install the previous tag as a second plugin folder and diff the error counts.
  4 pre-existing `NotPrepared` errors are expected.
