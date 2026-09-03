# Unattached Media Manager

Fix the WordPress "Unattached" media filter. Find where your media is really used, attach it to the right posts, and clean up what is genuinely unused.

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/unattached-media-manager)](https://wordpress.org/plugins/unattached-media-manager/)
[![Tested up to](https://img.shields.io/badge/WordPress-7.1%20tested-blue)](https://wordpress.org/plugins/unattached-media-manager/)
[![Requires PHP](https://img.shields.io/badge/PHP-7.4%2B-8892bf)](https://wordpress.org/plugins/unattached-media-manager/)
[![License](https://img.shields.io/badge/license-GPLv2%2B-green)](LICENSE.txt)

**WordPress.org:** https://wordpress.org/plugins/unattached-media-manager/

---

## Why this plugin is different

Most media cleaners help you delete unused images. This one fixes the underlying WordPress problem first.

WordPress has a built-in "Unattached" filter in the Media Library. It is supposed to show you media that is not connected to any post. The catch is that WordPress only marks media as attached when it was uploaded directly through the post editor. Anything added through ACF fields, Gutenberg blocks, page builders, widgets, theme options or shortcodes shows up as "Unattached" even while it is being used on a live page.

That makes the native filter unreliable, and it is why deleting everything it lists is dangerous.

This plugin scans the whole site, finds every place media is actually referenced, and attaches those files to their parent posts. Once that is done:

- The native "Unattached" filter shows only genuinely unused media
- Migration and import tools such as WP All Import, WP Migrate and Duplicator can associate media with the right posts
- You can uninstall this plugin and keep the fix, because the attachments are stored in WordPress's own structure

## The workflow

1. **Scan.** The plugin indexes media usage across content, ACF, blocks, widgets, options and more
2. **Attach.** One click attaches all "used but unattached" media to their parent posts
3. **Review.** The Unattached filter now reflects reality
4. **Clean up.** Use WordPress's native tools, or this plugin's trash and delete features
5. **Done.** Uninstall if you want. The fixes stay behind

## Features

- **Comprehensive scanning** across post content, featured images, Gutenberg blocks, ACF, Elementor, Meta Box, WooCommerce, SEO plugins, widgets, theme options and custom database tables
- **Fix unattached media** in one click
- **Media Library integration** showing a usage count and a "Where Used" panel per file
- **Safe deletion** with trash, restore and permanent delete
- **Change history** with one-click revert on any attachment change
- **Pause and resume** on every long-running operation
- **Resource aware**, with Low, Auto and High modes for shared hosting through to dedicated servers
- **CSV export** of the full usage report and of unused file URLs
- **Developer tools**: hooks, filters, a REST API and WP-CLI commands

### What gets scanned

| Source | Covered |
|---|---|
| Post content | Classic editor, Gutenberg, shortcodes, inline styles, data attributes |
| Featured images | Thumbnail assignments |
| ACF | Image, gallery, file, repeater, flexible content, group fields |
| Gutenberg blocks | Image, gallery, cover, media & text |
| Elementor | Widgets, backgrounds, galleries, sliders, responsive images |
| Meta Box | All field types including groups and cloneable fields |
| WooCommerce | Product galleries, variation images, downloadable files, category thumbnails |
| SEO plugins | Yoast, Rank Math, All in One SEO, SEOPress (OpenGraph and Twitter images) |
| Widgets | Image widgets, text widgets, custom HTML |
| Theme options | Customizer settings, theme mods, custom logos |
| Options table | Plugin settings storing media IDs or URLs |
| Custom database tables | Opt-in, schema validated, read only |
| Video and audio | HTML5 elements, poster images, source tags |
| Responsive images | srcset and lazy-loading data attributes |

## Installation

From WordPress:

1. Install **Unattached Media Manager** from Plugins → Add New
2. Activate it
3. Go to **Media → Media Solution**
4. Click **Start Full Scan**

From this repository:

```bash
cd wp-content/plugins
git clone https://github.com/sungraizfaryad/unattached-media-manager.git
wp plugin activate unattached-media-manager
```

### Requirements

- WordPress 5.8 or higher (tested up to 7.1)
- PHP 7.4 or higher
- MySQL 5.6 or higher

256M of PHP memory and a 60 second `max_execution_time` are recommended, though the plugin adapts to lower limits.

## Before you delete anything

Read this part. It is the one that costs people files.

**"Unused" means "no reference found", not "safe to delete".** The scanner reads your database. It cannot see media referenced in theme or plugin PHP files, CSS background images, images injected by JavaScript, or anything used outside the site entirely such as email campaigns or PDFs.

**Trashing media does not break anything, and that is not a bug.** Moving media to the trash only changes its status. The file stays on the server and images already placed in your content keep displaying. So you cannot trash a batch of files, browse the site for broken images, and conclude the rest were safe. Nothing will look broken either way. Images only break after a permanent delete, which is too late to learn from.

Two checks that actually work:

- **Server access logs.** Search for requests under `/wp-content/uploads/` over the last month or two. Files no browser has ever requested are genuinely unused. This also catches images that other sites link to
- **A staging copy.** Clone the site, delete there, then crawl it with a broken link checker

**WordPress empties the trash on its own.** Anything sitting in the trash for longer than `EMPTY_TRASH_DAYS`, 30 days by default, is permanently deleted on a schedule, files included. Nobody has to click Empty Trash. If you are deliberately parking files in the trash while you verify them, raise that value in `wp-config.php` first.

## For developers

### Filters

Add your own parser:

```php
add_filter( 'unmam_parsers', function ( $parsers ) {
    $parsers['my_custom'] = new My_Custom_Parser();
    return $parsers;
} );
```

Your parser implements `UNMAM_Parser_Interface` (`parse_post( $post )` and `get_name()`).

Override which post types get scanned. Useful for builder CPTs that are not registered as public:

```php
add_filter( 'unmam_scan_post_types', function ( $types ) {
    $types[] = 'my_builder_template';
    return $types;
} );
```

### WP-CLI

```bash
wp unmam scan --reset          # full rebuild of the reference index
wp unmam stats                 # totals
wp unmam unused                # list unused media
wp unmam unused --format=csv   # export unused file URLs
wp unmam usage <attachment-id> # where one file is referenced
wp unmam attach <id> <post-id>
```

### REST API

Endpoints live under the `unmam/v1` namespace.

### Architecture

```
includes/
  class-unmam-database.php             custom tables, reference and unused queries
  class-unmam-scanner.php              parser registry and scan pipeline
  class-unmam-background-processor.php cron, loopback and AJAX dispatch
  class-unmam-job-queue.php            bulk trash/restore/delete/attach jobs
  class-unmam-attachment-manager.php   attach, detach, replace, delete guards
  class-unmam-history.php              change log with revert
  class-unmam-resource-monitor.php     adaptive batch sizing
  parsers/                             content, block, acf, meta, options, widget,
                                       elementor, metabox, woocommerce, seo, custom-table
  admin/  api/  cli/
```

## Privacy

The plugin collects no personal data, sends nothing to external servers, uses no third party services and does not track anything. All data lives in custom tables in your own database, and those tables are removed on uninstall.

Attachment relationships (`post_parent`) set by the plugin are deliberately left in place on uninstall, since they are part of WordPress's native structure at that point. Use the Change History tab to revert them first if you want them gone.

## Changelog

See [CHANGELOG.md](CHANGELOG.md), or the [changelog on WordPress.org](https://wordpress.org/plugins/unattached-media-manager/#developers).

## Contributing

Bug reports and pull requests are welcome. If you are reporting media that is wrongly listed as unused, it helps a lot to know how that media is stored, which plugin or builder put it there, and which meta key or table it lives in.

## License

GPLv2 or later. See [LICENSE.txt](LICENSE.txt).
