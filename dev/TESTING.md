# Testing this plugin

There are no automated tests. Everything is verified by hand against a Local by Flywheel
site. This folder holds the harness used for the 1.2.0 release so it does not have to be
rebuilt from scratch each time.

`dev/` is listed in `.svnignore`, so nothing here ships to WordPress.org.

## Test site

FLP (`~/Local Sites/flp/`) is the site used. It is a real site with ~510 attachments, ~8000
posts, Meta Box (bundled inside easy-real-estate) and Yoast active, which is far more useful
than an empty install.

**ACF is NOT installed on FLP**, despite what older notes said. The ACF code paths have to be
tested by copying ACF Pro in from another Local site for the run:

```bash
cp -R ~/Local\ Sites/kka/app/public/wp-content/plugins/advanced-custom-fields-pro \
      ~/Local\ Sites/flp/app/public/wp-content/plugins/
cp dev/fixture/unmam-acf-harness.php ~/Local\ Sites/flp/app/public/wp-content/mu-plugins/
dev/flpwp.sh plugin activate advanced-custom-fields-pro
dev/flpwp.sh eval-file dev/fixture/acf-seed.php     # expects 0 failures
dev/flpwp.sh plugin deactivate advanced-custom-fields-pro
dev/flpwp.sh plugin delete advanced-custom-fields-pro
rm ~/Local\ Sites/flp/app/public/wp-content/mu-plugins/unmam-acf-harness.php
```

ACF leaves four options behind (`acf_first_activated_version`, `acf_site_health` and two
update transients). Delete them after removing the plugin. Registering the field groups locally
means there is nothing in `wp_posts` to clean up.

`acf-seed.php` covers every media-bearing field type plus two URL cases that must not regress:
a link to an external file sharing a basename with one of ours (must not be credited through
the ACF path) and one of our files served from a CDN host (must still resolve).

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

As of 1.2.0, `check.php` shows 3 of 7 detected. The 4 undetected are the 3 term cases and the
theme CSS case.

**As of 1.3.0 it should show 6 of 7.** `termmeta`, `termwysiwyg` and `termthumb` must all pass
with ACF absent, because the generic meta parser reads raw term meta and its last-resort
branch reads the WYSIWYG markup. Only `themecss` stays undetected until 1.4.0. If a case that
used to pass starts failing, that is a regression.

The fixture taxonomy `unmam_tax` is registered by the harness mu-plugin *after* settings were
first stored, so it also exercises the taxonomy reconciliation: it must be scanned without
anyone opening the Settings page.

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

## Snapshot before installing anything heavy

Counts are not enough. Snapshot the actual rows you might delete, or you cannot tell your own
debris from the site's content when cleaning up:

```bash
dev/flpwp.sh eval 'global $wpdb;
file_put_contents("/tmp/snap-pages.txt", implode("\n", $wpdb->get_col("SELECT CONCAT(ID,\"|\",post_name) FROM {$wpdb->posts} WHERE post_type=\"page\"")));
file_put_contents("/tmp/snap-tables.txt", implode("\n", $wpdb->get_col("SHOW TABLES")));
file_put_contents("/tmp/snap-options.txt", implode("\n", $wpdb->get_col("SELECT option_name FROM {$wpdb->options}")));'
```

Diff against those before deleting, and delete only what the diff says you added. Testing the
WooCommerce fix for 1.3.1 removed five pages (`cart`, `checkout`, `my-account`, `shop`,
`refund_returns`) that turned out to be leftovers from an earlier WooCommerce install on FLP,
not from that session. They were WooCommerce debris rather than site content, but only counts
had been recorded, so there was no way to know that before deleting.

Signs that a heavy plugin has been installed on FLP before: a `woocommerce-placeholder`
attachment, orphan `product_*` taxonomy terms, or WooCommerce's pages with no WooCommerce
active.

**WooCommerce specifics.** `set_downloads()` rejects any file outside an approved directory, so
set `wc_downloads_approved_directories_mode` to `disabled` before seeding downloadable
products. Activating WooCommerce through WP-CLI does not always run its full install routine,
so it may create no tables at all; diff `SHOW TABLES` rather than assuming.

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
- **Terms specifically, for 1.3.0:**
  - Rescan without `--reset` after changing a term meta value. The old reference must go and
    the new one must appear. This is the cursor-rewind rule.
  - `wp term meta update`, then run the scheduled event, then confirm the reference row. Then
    `wp term delete` and confirm the rows are gone.
  - Settings: untick a taxonomy, save, read the settings back several times. It must stay
    unticked. Then register a new taxonomy and confirm it is added automatically.
  - Parity with the workaround @galbaras is running: scan once with `wp_termmeta.meta_value`
    configured as a custom table, once with the native terms step, and diff the reference
    rows by attachment. The native step must credit every attachment the workaround credited.
  - Confirm the WooCommerce category-thumbnail rows (`source_type` `term`, `context_type`
    `woocommerce`) survive a terms scan, in both step orders.
  - `wp unmam usage <id>` on a term-only attachment must name the term, not a post.
- WordPress.org Plugin Check, compared against the previous release rather than read as an
  absolute. Install the previous tag as a second plugin folder and diff the error counts.
  4 pre-existing `NotPrepared` errors are expected: 3 in `get_unused_attachments_detailed()`
  and 1 in the custom-table parser.
