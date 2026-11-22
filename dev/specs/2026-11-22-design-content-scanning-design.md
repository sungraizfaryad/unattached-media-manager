# Design content scanning (1.3.3)

Approved 2026-11-22. Split out of the 1.4.0 filesystem scan after finding that four kinds of
theme design WordPress keeps in the database are never scanned either. Cheaper than reading
files and probably more common, so it ships first. Raised indirectly by @galbaras (block theme
sidebar image listed as unused) and @adeqx (could not trust the unused list).

## Problem

`unmam_get_scannable_post_type_candidates()` offers `public` or `show_ui` types. These four
are neither, so they have never been scanned:

| Post type          | What it holds                                         | Format              |
|--------------------|-------------------------------------------------------|---------------------|
| `custom_css`       | Customizer > Additional CSS, one post per theme       | plain CSS           |
| `wp_template`      | A template once edited in the Site Editor             | block markup        |
| `wp_template_part` | Header, footer, sidebar etc. once edited              | block markup        |
| `wp_global_styles` | Site Editor > Styles, including background images     | JSON                |

An image used only in one of these is listed as unused and can be deleted while the site
still shows it. Templates and parts that were never edited live in the theme's files and are
still 1.4.0's job.

The existing per-post readers do not help with two of them. The content parser only finds
background images inside a `style=""` attribute, and the block parser needs block markup, so
plain CSS and the global styles JSON produce nothing.

## What changes

**Offered and on by default**

- The candidate list adds the four types, each behind `post_type_exists()` (the plugin still
  supports 5.8, which has no template parts or global styles).
- Nothing else is needed to switch them on. The reconcile-on-read in `get_setting()` treats a
  type missing from `scan_post_types_known` as newly seen and ticks it, and fresh installs
  default to the full candidate list.
- They show as normal tick boxes on the Settings tab. `custom_css` is labelled "Additional
  CSS" there, since that is what the Customizer calls it.

**A small parser for the non-markup two**

- New `UNMAM_Design_Parser`, implementing `UNMAM_Parser_Interface`, name `design`. It only
  acts on `custom_css` and `wp_global_styles` and returns nothing for anything else.
- It runs `UNMAM_Reference_Extractor::extract_from_text()` over `post_content`. No new regex.
  Global styles saved before core switched to `JSON_UNESCAPED_SLASHES` have `\/` in their
  URLs, so the parser replaces `\/` with `/` before extracting.
- Rows: `source_type` `post`, `context_type` `post_content` (so Replace Media's existing
  content branch handles them), `context_label` "Additional CSS" or "Styles".
- Templates and parts go through the existing content and block readers, same as any page.
- The extractor gets an optional second argument, `$allow_relative`, default `false`. When
  true it also matches root-relative (`/wp-content/uploads/...`) and protocol-relative
  (`//host/wp-content/uploads/...`) URLs and makes them absolute against the site URL before
  resolving. CSS is often written that way. Only the design parser passes `true`; every other
  caller keeps its current behaviour. 1.4.0's file scan will want it too.

**Deleted themes are skipped**

- Every design post belongs to a theme. `custom_css` stores the theme in `post_name`; the
  other three carry a `wp_theme` term.
- `index_post()` clears the post's rows as it does today, then returns early if the design
  post's theme is no longer installed. Installed themes are compared by `sanitize_title()` of
  the stylesheet on both sides, because `custom_css` stores the sanitized form.
- If the theme cannot be worked out (no term, empty name), the post is scanned. Failing open
  is the safe direction.
- Accepted: deleting a theme does not trigger a rescan, so its rows stay until the next full
  scan. That over-reports, which is safe.

**Never attached**

- Design posts are not pages, so nothing is ever attached to them.
- `auto_attach_media()` is skipped in `index_post()` for the four types.
- `get_used_but_unattached()` and the paged query that backs the Attach view exclude rows
  whose source post is one of the four types, so bulk attach never suggests them as parent.

**Where Used**

- `UNMAM_Database::describe_source()` gets a branch inside its `post` case for the four types.
  All four callers (media modal twice, REST, WP-CLI `usage`) go through it, so one change
  covers them.
- Title: "Additional CSS", "Template: {title}", "Template part: {title}" or "Styles",
  followed by the theme's display name, e.g. "Template part: Sidebar (Twenty Twenty-Five)".
- Edit link only when the post belongs to the active theme. Core's `get_edit_post_link()`
  builds template links from `get_stylesheet()`, so for another theme it would open the wrong
  template. `custom_css` gets `customize.php?autofocus[section]=custom_css` behind
  `current_user_can( 'edit_css' )`; the other three use `get_edit_post_link()`.
- No view link for any of them.

**Replace Media stops stripping backslashes**

Found while checking whether Replace Media is safe on these posts. It is not safe anywhere.

- The `post_content` / `block` branch calls `wp_update_post()` with unslashed content, and
  `wp_insert_post()` runs `wp_unslash()` on it. Backslashes are lost: code samples, and block
  attributes core escapes as `\u003c`, `\u0022` and so on, which turn into `u003c`.
- The `postmeta` / `acf` and `term_meta` / `term_acf` branches do the same through
  `update_post_meta()` / `update_term_meta()`. `replace_in_meta()` re-encodes JSON values with
  `wp_json_encode()`, so a JSON value comes back full of `\"` and loses all of them, which
  leaves invalid JSON.
- Global styles would be hit the same way, since `JSON_HEX_TAG` puts `<` in them.
- Accepted: Replace Media cannot rewrite a global styles post saved in the old escaped
  format, because the stored URL is `https:\/\/...` and the replacer looks for the plain one.
  It reports nothing for that row rather than damaging it.
- Fix: wrap the values in `wp_slash()` in those three branches. `update_option()` does not
  unslash, so the `option` branch stays as it is.
- Elementor is not affected. Its rows use `context_type` `elementor`, which Replace Media
  rejects as unsupported, and the meta parser skips `_elementor_*`.
- Same pass: `replace_in_meta()` calls `@unserialize()` on any string. Switch to
  `is_serialized()` plus `unserialize( $value, array( 'allowed_classes' => false ) )`, and
  leave the value untouched if it holds an object rather than rebuilding it incomplete.
- Same pass: `replace_in_content()` swaps `wp-image-{ID}` with a plain `str_replace()`, so
  replacing 12 also rewrites `wp-image-123` into `wp-image-<new>3`. Anchor it so the number
  must end there. Approved into 1.3.3 on 2026-11-22.
- **Reproduce first.** On FLP, before touching the code: a post with a literal backslash and a
  block whose attributes contain `<`, both using one image; replace that image; confirm the
  damage; snapshot and restore the rows. Only then fix.

## Not in this release

- Theme and plugin files on disk (1.4.0).
- `customize_changeset` drafts. Unpublished Customizer changes are not live.
- Gal's orphan files with no library entry (separate feature).

## Testing (FLP, row snapshot, restore after)

For each of the four places, an image used only there must show as used, and Where Used must
name the place and theme:

1. Active theme: Additional CSS with an absolute URL, one with a root-relative URL, an edited
   template part (Gal's sidebar case), an edited template, a Styles background image.
2. An installed but inactive theme: same rows, no edit link.
3. A deleted theme's leftover posts: skipped on full scan, image shows unused.
4. Untick one type in Settings, full scan: its images drop out. Retick: they come back.
5. Auto-attach on, then a full scan: none of these images get a parent. Attach view does not
   suggest a design post.
6. Replace Media on a design post and on the backslash test post: content intact afterwards.
7. Upgrade path: settings saved under 1.3.2, update, load any admin page: four types ticked,
   other unticked types still unticked.

If FLP's active theme is classic, test templates and styles under an installed block theme.
Activating a theme on FLP needs Sungraiz's OK first.

Plugin Check clean via `dev/plugin-check.php`.

## Release

1.3.3. Bump header `Version:`, readme `Stable tag:` and `UNMAM_VERSION` together. `uninstall.php`
needs nothing: no new options, and settings live in the existing `unmam_settings`.

Changelog, plain: "Images used only in Additional CSS, an edited template or template part, or
Site Editor styles are now found. Images used only in theme files are still not detected,
that is planned. Replace Media no longer removes backslashes from content it updates."
