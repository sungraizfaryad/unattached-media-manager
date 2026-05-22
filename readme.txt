=== Unattached Media Manager ===
Contributors: sungraizfaryad
Tags: media library, unused media, media cleaner, cleanup, attachments
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fix the WordPress Unattached media filter. Automatically attach used media files to their posts so you can safely clean up your library.

== Description ==

= Why This Plugin Is Different =

**Most media cleaner plugins help you delete unused images. This plugin fixes a fundamental WordPress problem first.**

WordPress has a built-in "Unattached" filter in the Media Library. The idea is simple: it shows you media files that aren't connected to any post, so you can decide whether to keep or delete them.

**The problem?** WordPress only marks media as "attached" if it was uploaded directly through the post editor. Any image added through ACF fields, Gutenberg blocks, page builders, widgets, theme options, or shortcodes shows as "Unattached" — even though it's actively being used on your site.

**This makes WordPress's native "Unattached" filter completely unreliable.**

= How Unattached Media Manager Fixes This =

This plugin scans your entire site, finds every place where media files are actually being used, and properly attaches them to their parent posts. Once attached:

* **WordPress's "Unattached" filter actually works** — It now shows only truly unused media
* **You can use WordPress's native tools** — No need to depend on third-party plugins to manage media
* **Import/export plugins work correctly** — Tools like WP All Import, WP Migrate, Duplicator, and others can now properly identify and migrate media with their associated posts
* **You can safely uninstall this plugin** — The attachments remain as part of WordPress's native structure

= The Workflow =

1. **Scan** — The plugin finds all media usage across your site (content, ACF, blocks, widgets, options, etc.)
2. **Attach** — One-click to properly attach all "used but unattached" media to their parent posts
3. **Review** — Now WordPress's "Unattached" filter shows only genuinely unused files
4. **Clean Up** — Use WordPress's native tools OR this plugin's safe deletion features
5. **Done** — Uninstall if you want; the fixes stay with WordPress

= Yes, It Also Deletes Unused Media =

Like other media cleaners, this plugin also helps you safely delete unused media with:

* **Trash support** — Move to trash first, restore if needed
* **Permanent delete** — Remove forever when you're sure
* **Change history** — Track all attachment changes with one-click revert

But the real value is **fixing WordPress's attachment system** so you don't need to depend on any plugin long-term.

= Two Processing Modes =

* **Browser-Driven (Recommended)** — Fast and reliable with real-time progress. Keep the browser tab open until complete.
* **Background (WP-Cron)** — Processing continues even after closing your browser. Ideal for server cron setups.

= Key Features =

* **Comprehensive Scanning** — Detects media usage in post content, featured images, Gutenberg blocks, ACF fields, Elementor, Meta Box, WooCommerce, SEO plugins, widgets, theme options, and more
* **Fix Unattached Media** — One-click to properly attach all "used but unattached" media
* **Media Library Integration** — See usage count directly in your Media Library list view
* **Safe Deletion** — WordPress trash support with restore capability
* **Change History** — Full audit trail with one-click revert for any attachment
* **Pause & Resume** — Stop any operation and continue later
* **Resource Aware** — Three modes (Low/Auto/High) for shared hosting to dedicated servers
* **Export Reports** — Download CSV reports of all media usage
* **Developer Friendly** — Hooks, filters, REST API, and WP-CLI commands

= The Problem It Solves =

WordPress marks media as "Unattached" if it wasn't uploaded directly to a post. But many media files ARE being used — they're just embedded via:

* The block editor (Gutenberg)
* Page builders like Elementor or Beaver Builder
* ACF image/gallery fields
* WooCommerce product galleries
* Theme customizer settings
* Widget areas
* Shortcodes
* Custom meta boxes

**Unattached Media Manager finds ALL these references and properly attaches the media**, so WordPress correctly reflects which files are actually in use.

= Server-Friendly Design =

**This plugin is designed to work on ALL servers, including shared hosting with limited resources:**

* **Never blocks your site** - All heavy operations are processed in batches
* **Adaptive resource usage** - Automatically detects server limits and adjusts accordingly
* **Three resource modes:**
  * **Low Resources** - 5 items/batch, 2-minute intervals (for shared hosting)
  * **Auto (Recommended)** - 15 items/batch, 1-minute intervals (adjusts automatically)
  * **High Performance** - 50 items/batch, 30-second intervals (for dedicated servers)
* **Two processing strategies** - Choose browser-driven (fast) or background WP-Cron mode

= Supported Content Types =

**ALL features below are FREE - no Pro version required!**

* **Post Content** - Classic editor, Gutenberg blocks, shortcodes, inline styles, data attributes
* **Featured Images** - Thumbnail assignments
* **ACF Fields** - Image, gallery, file, repeater, flexible content, and group fields
* **Gutenberg Blocks** - Core image, gallery, cover, media & text blocks
* **Elementor** - All widgets, backgrounds, galleries, sliders, and responsive images
* **Meta Box** - All field types including groups and cloneable fields
* **WooCommerce** - Product galleries, variation images, downloadable files, category thumbnails
* **SEO Plugins** - Yoast SEO, Rank Math, All in One SEO, SEOPress (OpenGraph & Twitter images)
* **Widgets** - Image widgets, text widgets with media, custom HTML
* **Theme Options** - Customizer settings, theme mods, custom logos
* **Options Table** - Plugin settings that store media IDs or URLs
* **Video & Audio** - HTML5 video/audio elements, poster images, source tags
* **Responsive Images** - srcset attributes and lazy-loading data attributes

= For Developers =

Unattached Media Manager is built with extensibility in mind:

* **Hooks & Filters** - Extend scanning with custom parsers
* **REST API** - Query media usage programmatically
* **WP-CLI Commands** - Run scans from the command line (`wp aioms scan`)
* **Custom Post Types** - Automatically scans all public post types

== Installation ==

1. Upload the `unattached-media-manager` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **Media → Media Solution** to access the dashboard
4. Click **Start Full Scan** to begin indexing your media references

= Minimum Requirements =

* WordPress 5.8 or higher
* PHP 7.4 or higher
* MySQL 5.6 or higher

= Recommended =

* PHP memory_limit of 256M or higher
* max_execution_time of 60 seconds or higher

== How to Use ==

= Step 1: Run Your First Scan =

1. Navigate to **Media → Media Solution**
2. Click **Start Full Scan**
3. Choose your processing mode (Browser-Driven recommended for most users)
4. Watch the real-time progress as your media library is scanned

= Step 2: Review the Dashboard =

After scanning, you'll see:

* **Total Media Files** - All attachments in your library
* **In Use** - Media files with detected references
* **Potentially Unused** - Media files with no detected references
* **Used but Unattached** - Media that's used but marked as "Unattached" in WordPress
* **Total References** - Total number of places media is referenced

= Step 3: Fix Unattached Media =

If you have "Used but Unattached" media:

1. Click the **Attach All Media Files** button
2. The operation runs in the background
3. Each attachment is tracked in Change History for easy reverting

= Step 4: Clean Up Unused Media =

Go to the **Unused Media** tab to:

1. **Review** - Check each file before taking action
2. **Move to Trash** - Safely move to WordPress trash (can be restored)
3. **Restore** - Bring items back from trash if needed
4. **Delete Permanently** - Remove forever (cannot be undone)
5. **Empty Trash** - Delete all trashed media at once

**Important:** All bulk operations run in the background. You'll see a status bar showing progress.

= Step 5: Review Change History =

The **Change History** tab shows:

* All attachments made by this plugin
* When each change occurred
* What post each media was attached to
* Option to **Revert** any change (detaches the media)

== Important Precautions ==

= Before Deleting Any Media =

1. **Always run a full scan first** - Make sure the index is up to date
2. **Review files manually** - The scanner detects database references, but images might be:
   - Hardcoded in theme PHP files
   - Used by external websites linking to your images
   - Referenced in custom code or third-party plugins not yet supported
   - Used in email templates stored outside WordPress
3. **Use Trash first** - Move to trash instead of deleting permanently
4. **Wait before emptying trash** - Keep trashed items for a few days to catch any issues

= About "Potentially Unused" Media =

Files marked as "Potentially Unused" means:

* No references were found in the scanned content
* **This doesn't guarantee the file is unused**
* The file might be used in ways not detected:
  - Theme template files (hardcoded)
  - External sites linking to your images
  - Custom plugins with non-standard storage
  - CSS background images defined in stylesheets
  - JavaScript-loaded images

= Recommended Workflow =

1. **Scan** - Run a full scan with all content types enabled
2. **Review** - Look at the Unused Media tab
3. **Research** - For each file, consider where it might be used
4. **Trash** - Move questionable items to trash (not permanent delete)
5. **Monitor** - Check your site for a few days for missing images
6. **Delete** - Only permanently delete after confirming no issues

= Server Resources =

* **Shared Hosting** - Use "Low Resources" mode in settings
* **If operations timeout** - Switch to Low Resources mode
* **Large media libraries** - The scan may take longer but will complete
* **WP Cron must work** - Ensure WordPress cron is running (check with your host)

== Frequently Asked Questions ==

= Will scanning slow down my site? =

No. The scanner processes items in small batches and automatically adjusts its resource usage. Visitors to your site won't notice any slowdown.

= Can I close my browser during operations? =

It depends on your chosen processing mode. With **Browser-Driven mode** (recommended), you need to keep the tab open. With **Background (WP-Cron) mode**, operations continue even after closing your browser — though this requires WP-Cron to be working properly on your site.

= What if I accidentally delete something? =

If you used "Move to Trash" instead of "Delete Permanently", you can restore from the Trash view in the Unused Media tab. This is why we recommend always using Trash first.

= What if I attached media incorrectly? =

Go to the **Change History** tab and click **Revert** on any change. This will detach the media from the post it was attached to.

= What happens if the scan gets stuck? =

You can pause the scan at any time and resume it later. If something goes wrong, use the Stop button to reset and start fresh.

= Does this work with page builders? =

Yes. Unattached Media Manager scans all post content, which includes content created by page builders like Elementor, Beaver Builder, Divi, and others. The scanner looks for both image IDs and URLs in the content.

= Does this work with ACF? =

Yes. There's a dedicated ACF parser that understands image fields, gallery fields, file fields, repeaters, flexible content, and group fields.

= Will it detect images added via code/theme? =

If images are stored in the database (post meta, options, theme mods), they will be detected. Images hardcoded in PHP theme files are not detected as they're not stored in the database.

= Is it safe to delete media marked as "unused"? =

**Always review before deleting!** The scanner indexes database references, but images might be:
- Hardcoded in theme files
- Used by external services
- Linked from other websites
- Used in custom code

We strongly recommend using Trash first and waiting a few days before permanently deleting.

= Does this work with Multisite? =

Currently, Unattached Media Manager works on individual sites. Network-wide scanning for Multisite is planned for a future release.

= My server has very limited resources. Will this work? =

Yes! Set the Resource Mode to "Low Resources" in Settings. This uses smaller batches (5 items) with longer intervals (2 minutes) to prevent timeouts on shared hosting.

= How do I extend the scanner? =

Use the `aioms_parsers` filter to add custom parsers:

`
add_filter( 'aioms_parsers', function( $parsers ) {
    $parsers['my_custom'] = new My_Custom_Parser();
    return $parsers;
} );
`

Your parser should implement the `MUI_Parser_Interface`.

== Screenshots ==

1. **Dashboard** - Overview of media usage statistics (Total Media Files, In Use, Unused, Unattached).
2. **Fix Unattached Media** - Displaying used media files that need to be attached and organized.
3. **Unused Media** - Review all potentially unused media and safely move them to trash.
4. **Change History** - Complete audit trail of all changes made by the plugin, with options to revert.
5. **Settings Page** - Configure server resource usage (Low, Auto, High) and background processing behavior.
6. **References by Context** - Detailed breakdown of exactly where media is being used (Metabox, Post Content, Gutenberg, etc.).
7. **Potentially Unused Media Details** - View specific media files identified as having no references before taking action.
8. **Attachment Settings** - Choose which content areas (Post Content, Featured Images, ACF Fields, Widgets, Theme Options) are actively scanned.

== Changelog ==

= 1.0.7 =
* **Confirmed compatibility with WordPress 7.0**
* **New:** Filter Unused Media by filename, mime type, and upload date range
* **Improved:** Skip `_mfrh_history` and `_original_filename` meta keys (Media File Renamer plugin internals — not actual media references)
* **Improved:** Confirmed Rank Math Schema video thumbnail meta (`rank_math_schema_VideoObject`) is matched by the generic post meta walker

= 1.0.3 - 1.0.6 =
* Maintenance releases: WordPress.org SVN asset structure fixes (banner, icon, screenshots), readme metadata updates. No functional code changes.

= 1.0.0 =
* Initial release
* **Scanning Features:**
  * Comprehensive media scanning across posts, ACF, blocks, widgets, and options
  * Dual processing modes: Browser-Driven (fast) or Background (WP-Cron)
  * Pause and resume functionality
  * Adaptive resource management (Low/Auto/High modes)
* **Media Management:**
  * One-click fix for unattached media
  * Trash/restore functionality for safe deletion
  * Permanent delete option for confirmed unused media
  * Bulk operations for trash, restore, and delete
  * Empty trash function
* **Tracking & Safety:**
  * Full change history with audit trail
  * One-click revert for any attachment change
  * Background job queue for all bulk operations
  * Universal stop button for any operation
* **Developer Tools:**
  * REST API endpoints
  * WP-CLI commands (`wp aioms`)
  * Extensible parser system
* **UI/UX:**
  * Media Library integration with usage counts
  * CSV export functionality
  * Real-time progress updates
  * Sticky status bar for background operations

== Upgrade Notice ==

= 1.0.7 =
Confirmed compatibility with WordPress 7.0. Adds filters (filename / mime type / date range) to the Unused Media tab and improves meta key handling.

= 1.0.0 =
Initial release of Unattached Media Manager. Start organizing your media library today!

== Privacy Policy ==

Unattached Media Manager does not:

* Collect any personal data
* Send any data to external servers
* Use any third-party services
* Track users or usage

All data is stored locally in your WordPress database in custom tables that are removed when you uninstall the plugin.

== Uninstallation ==

When you uninstall (delete) the plugin:

* All custom database tables are removed
* All plugin options are deleted
* All plugin transients are cleared
* All scheduled cron events are removed
* Post meta created by the plugin is deleted

**Note:** Attachment relationships (post_parent) that were set by this plugin are NOT removed, as these are now part of WordPress's native media library structure. If you need to revert these, use the Change History tab before uninstalling.

== Credits ==

* Built with love for the WordPress community
* Icons from WordPress Dashicons
* Inspired by the need to keep media libraries clean and organized
