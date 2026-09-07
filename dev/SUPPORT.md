# Support threads

Forum threads, what was actually wrong, and the replies. Kept in `dev/` so it never ships.
Add to this rather than rewriting it: the history of what a user was told matters.

---

## @galbaras (Gal)

The most useful tester the plugin has. Verifies claims independently and supplies
reproduction code. Everything he has reported has turned out to be real.

### Thread 1 — ACF fields on WooCommerce product categories

**Status: fixed in 1.3.0. Reply below not yet sent as of 2026-09-07.**

Term meta was never scanned, so an ACF field on a product category was invisible and its media
was reported unused. He confirmed the diagnosis himself by adding `wp_termmeta.meta_value`
through the custom-tables feature and watching the problem disappear.

He later re-tested 1.2.0 by crawling with SEO Macroscope and found every file on the unused
list genuinely unused, but with that workaround still in place.

Measured during 1.3.0 testing: his workaround credited **1** attachment where the native terms
step credits **33**. The workaround only matched URLs and `wp-image-` classes inside field
text, so it could never see a bare attachment ID, which is how ACF image and gallery fields
store their value. Dropping it is a straight improvement, not just a simplification.

```
Hi Gal,

Thanks for going back and verifying with the Macroscope crawl — that was genuinely useful.

1.3.0 is out now and it fixes the root cause. The plugin reads taxonomy term meta directly, so
ACF fields on product categories are scanned natively. You can remove the
`wp_termmeta.meta_value` entry from Custom Database Tables and run a full scan.

It should actually improve your results, not just simplify your setup. That workaround could
only match URLs and `wp-image-` classes inside a field's text — it couldn't see a plain
attachment ID, which is how ACF image and gallery fields normally store their value. On my test
site it credited 1 file where native term scanning credits 33.

Nothing to switch on. Term scanning is enabled by default, including on upgrade. Expect your
unused count to drop after the first full scan.

Thanks again,
Sungraiz
```

### Thread 2 — WooCommerce variation downloads reported unused

**Status: fixed in 1.3.1. Reply below not yet sent as of 2026-09-07.**

He supplied a working reproduction snippet. Confirmed and fixed. A variable product is not
itself downloadable, so the parent-level `is_downloadable()` guard skipped every file sold
through a variation. Verifying it turned up a second bug in the same block:
`get_available_variations()` returns only purchasable, visible variations, so images on hidden
or disabled variations were missed too.

```
Hi Gal,

Fixed and shipped in 1.3.1 — thanks for the clear report and the snippet, it made this quick to
confirm.

You were exactly right about the cause. The downloads check was running against the parent
product, and a variable product isn't itself downloadable, so its variations were never looked
at. Product variations also aren't scanned as posts, so nothing else picked them up either.
Files sold through a variation could therefore show up as unused. Simple downloadable products
were already covered and weren't affected.

While verifying I found a second issue in the same place: the variation loop used
get_available_variations(), which only returns purchasable, visible variations. So images on
hidden, disabled or out-of-stock variations were being missed too. Switching to get_children(),
as you did, fixes both.

I tested it against WooCommerce 11.1 with downloads in both /uploads/ and /woocommerce_uploads/,
plus a disabled variation, and checked the reverse case too so the fix doesn't invent references
for products with no media.

Please update to 1.3.1 and run a full scan. Your downloadable files should stop appearing in the
unused list.

Cheers,
Sungraiz
```

### Thread 3 — move trashed files so they stop resolving on the front end

**Status: open, deliberately deferred. Reply below not yet sent.**

He points out that WordPress trash only changes `post_status`, so the file stays on disk and
keeps rendering, which means trashing cannot be used to test a deletion before committing to it.
He is right, and it is the plugin's most obvious remaining gap.

Deferred because Media Cleaner already ships this (`uploads/wpmc-trash`) and, after years and a
far larger install base, still has open reports of files that cannot be restored and trash
counters out of sync with disk. It is a database-and-filesystem consistency problem, and a
half-working restore on this plugin would be worse than not having the feature.

See `dev/ROADMAP.md` for the alternatives considered (admin-only front-end badge, render-time
usage observer, server access log parsing) and why none of them shipped yet.

```
Hi Gal,

I think you're right that this is the missing piece — testing a deletion before it's permanent is
exactly what the current trash can't do, since WordPress only changes the post status and leaves
the file in place.

I don't want to rush it though. Moving files means keeping the database and the filesystem in
agreement through both delete and restore, and that's where similar plugins have historically had
the most trouble. A half-working restore on a plugin like this would be worse than not having it.

So: noted properly, not dismissed. I'd like to see how 1.3.0 settles first, then design it as its
own release.

Cheers,
Sungraiz
```

---

## @adeqx (Ade)

### Thread 1 — trashed media still rendering, restore was painful

**Status: all three requests shipped in 1.2.0. He was never told. Reply below not yet sent.**

He had trashed 2000+ items, found images still rendering, and restored everything 20 at a time.
His three asks were a Restore All, larger batches, and moving Restore away from the delete
buttons. All three shipped in 1.2.0 and are verified present in the code:

- `#mui-restore-all` "Restore All %d" with a confirm step (`class-unmam-admin.php` ~1811)
- page size selector 20 / 50 / 100 / 200 (~1639)
- toolbar reordered to Restore All, Restore Selected, Delete Permanently, Empty Trash (~1807)

**The outstanding item is the caveat, not the features.** He has several GB to audit and was
never told that media referenced only from a theme file or stylesheet is still undetected. He is
the user most likely to bulk-delete on a bad list, so tell him before he re-tests.

```
Hi Ade,

Sorry for the slow follow-up — your feedback went straight into the 1.2.0 release, and all three
of your points are now shipped:

- Restore All: one button that restores everything in the trash at once.
- Batch size: you can now show 20, 50, 100 or 200 items per page instead of a fixed 20.
- Toolbar order: Restore is now first, with Delete Permanently and Empty Trash grouped away to
  the right, so Restore is no longer sandwiched between two destructive buttons.

That last one was entirely your catch, and it was a fair one — thank you.

One thing I should have flagged earlier, and it matters for your audit: the plugin only finds
media referenced in the database. If an image is referenced solely from a stylesheet or a
hardcoded URL in a theme file, it won't be detected, and it can appear in the Unused list even
though your site is using it. Scanning theme and plugin files is planned but not done yet, so
please spot-check anything that looks like a theme or background image before deleting.

1.3.0 and 1.3.1 have also landed since, adding scanning of categories, tags and custom taxonomy
terms, and fixing WooCommerce variation downloads — worth re-running a full scan.

Thanks again for the detailed feedback.

Cheers,
Sungraiz
```

---

## @kreativelabs

**Status: unverified, needs a nudge.**

Bricks Builder templates not being scanned. Diagnosed as the post-type snapshot plus the
`public`-only filter, both fixed in 1.2.0. He never confirmed. Ask whether it is resolved.

---

## Recurring themes worth watching

- **Two users have now independently asked for a way to verify a deletion before it is
  permanent** (Gal explicitly, Ade implicitly by trashing 2000+ files to test and then having to
  restore them all). That is a stronger signal than one request and should raise the priority of
  the verification work in `dev/ROADMAP.md`.
- Expect questions about unused counts changing after 1.3.0. It changes what counts as used on
  every install, and the count normally goes **down**.
