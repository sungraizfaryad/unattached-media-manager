# Design Content Scanning (1.3.3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Images used only in Additional CSS, Site Editor templates and template parts, or Site Editor styles stop being listed as unused; Replace Media stops stripping backslashes from what it saves.

**Architecture:** The four core post types join the scannable candidate list, so the existing per-post pipeline (`index_post()`, batch posts step, save hook) covers them. One small helper class answers "is this design content, whose theme is it, how do I describe it". A new parser runs the shared extractor over the two non-markup types. Guards in the scanner and the attach queries keep these posts from ever becoming an attachment's parent.

**Tech Stack:** WordPress plugin, PHP 7.4+, WP-CLI fixture scripts run on the FLP Local site. No automated test suite; each check is a `wp eval-file` script that prints `ok` / `FAIL` and a failure count.

**Spec:** `dev/specs/2026-11-22-design-content-scanning-design.md`

---

## Ground rules for whoever runs this

- Repo: `~/Local Sites/plugins/unattached-media-manager/` (all paths below are relative to it). Test site: FLP.
- **Sungraiz must OK every file edit and every commit** (Local Sites CLAUDE.md). Ask once at the start for the whole plan, and again before any commit if he hasn't said "commit as you go".
- Git identity in this repo is `sungraizfaryad <sungraiz@gmail.com>`; it matches history. Check `git config user.email` before the first commit anyway.
- Never touch FLP's real media or real design content. Fixtures create throwaway items titled or named `UNMAMFIX` / `unmamfix-` and remove them.
- Commit messages end with the line `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Code must read as human-written: short comments only where they earn it, no ALL-CAPS emphasis, no restating the code.

## File map

| File | Change | Responsibility |
|---|---|---|
| `unattached-media-manager.php` | modify | `unmam_design_post_types()` (the one list), candidates include it, load new files, version |
| `includes/class-unmam-design-content.php` | create | `UNMAM_Design_Content`: type test, theme lookup, installed/active checks, `describe()` |
| `includes/parsers/class-unmam-design-parser.php` | create | `UNMAM_Design_Parser`: extractor over `custom_css` and `wp_global_styles` |
| `includes/class-unmam-reference-extractor.php` | modify | `$allow_relative` argument, shared extension list |
| `includes/class-unmam-scanner.php` | modify | register parser, skip deleted themes, no auto-attach for design posts |
| `includes/class-unmam-database.php` | modify | `describe_source()` branch, `design_source_clause()` in the four attach queries |
| `includes/class-unmam-attachment-manager.php` | modify | `wp_slash()` on saves, safer unserialize |
| `includes/admin/class-unmam-admin.php` | modify | "Additional CSS" label in the post type list |
| `dev/fixture/replace-slash.php` | create | Replace Media backslash check (self-cleaning) |
| `dev/fixture/design-seed.php` | create | Seeds design fixture |
| `dev/fixture/design-check.php` | create | Asserts design fixture |
| `dev/fixture/design-teardown.php` | create | Removes design fixture, restores Additional CSS |
| `dev/fixture/design-upgrade-check.php` | create | 1.3.2 settings shape upgrades correctly (self-restoring) |
| `readme.txt`, `CLAUDE.md`, `dev/TESTING.md`, `dev/ROADMAP.md`, `progress.md` | modify | release notes and docs |

---

### Task 0: Branch, start FLP, snapshot

**Files:** none

- [ ] **Step 1: Switch to the feature branch** (created during planning; the spec and this plan are its first commit)

```bash
cd ~/Local\ Sites/plugins/unattached-media-manager
git checkout feature/1.3.3-design-content
git status --short --branch   # expect: ## feature/1.3.3-design-content, clean
```

- [ ] **Step 2: Start FLP through Local's API** (FLP is off by default)

```bash
python3 - <<'PY'
import json, os, urllib.request
d = json.load(open(os.path.expanduser('~/Library/Application Support/Local/graphql-connection-info.json')))
q = {"query": 'mutation { startSite(id: "8-CWukao6") { id status } }'}
r = urllib.request.Request(d['url'], data=json.dumps(q).encode(),
    headers={'Content-Type': 'application/json', 'Authorization': 'Bearer ' + d['authToken']})
print(urllib.request.urlopen(r).read().decode())
PY
dev/flpwp.sh option get stylesheet
```

Expected: `"status":"running"`, then the active theme slug. The plan assumes `sungraiz-property` (a block theme) is active and `genesis-block-theme` is installed but inactive. If either differs, stop and tell Sungraiz; activating a theme needs his OK.

- [ ] **Step 3: Snapshot rows** (TESTING.md rule: rows, not counts)

```bash
dev/flpwp.sh eval 'global $wpdb;
file_put_contents("/tmp/unmam133-posts.txt", implode("\n", $wpdb->get_col("SELECT CONCAT(ID,\"|\",post_type,\"|\",post_name) FROM {$wpdb->posts} WHERE post_type IN (\"custom_css\",\"wp_template\",\"wp_template_part\",\"wp_global_styles\",\"revision\")")));
file_put_contents("/tmp/unmam133-options.txt", implode("\n", $wpdb->get_col("SELECT option_name FROM {$wpdb->options}")));
file_put_contents("/tmp/unmam133-settings.txt", serialize(get_option("unmam_settings")));
echo "max post id: " . $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}") . "\n";'
```

Record the max post id in the session notes.

---

### Task 1: Reproduce the Replace Media backslash bug

**Files:**
- Create: `dev/fixture/replace-slash.php`

- [ ] **Step 1: Write the check**

```php
<?php
/**
 * Replace Media must not strip backslashes from what it saves.
 *
 * Self-contained: creates two throwaway images and a draft post, replaces one image with
 * the other, checks the result, then deletes everything it made.
 * Run: dev/flpwp.sh eval-file dev/fixture/replace-slash.php
 */

require_once ABSPATH . 'wp-admin/includes/image.php';

// Replace Media runs as an admin. Without a user, kses filters every save.
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );

function unmam_rs_image( $name ) {
	$dir  = wp_upload_dir();
	$path = $dir['path'] . '/' . $name . '.png';
	$im   = imagecreatetruecolor( 40, 40 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, crc32( $name ) % 200, 90, 160 ) );
	imagepng( $im, $path );
	imagedestroy( $im );

	$id = wp_insert_attachment( array(
		'post_title'     => 'UNMAMFIX ' . $name,
		'post_mime_type' => 'image/png',
		'post_status'    => 'inherit',
	), $path );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
	return $id;
}

$old     = unmam_rs_image( 'rsold' );
$new     = unmam_rs_image( 'rsnew' );
$old_url = wp_get_attachment_url( $old );
$new_url = wp_get_attachment_url( $new );

// A literal backslash, and a block attribute holding <b>, which core stores as \u003cb\u003e.
$content = '<!-- wp:paragraph --><p>Path C:\\temp\\file</p><!-- /wp:paragraph -->'
	. '<!-- wp:image {"id":' . $old . ',"caption":"\u003cb\u003ebold\u003c/b\u003e"} -->'
	. '<figure class="wp-block-image"><img src="' . $old_url . '" class="wp-image-' . $old . '"/></figure>'
	. '<!-- /wp:image -->'
	// No such attachment; it only starts with the same digits.
	. '<!-- wp:html --><img class="wp-image-' . $old . '9" alt=""/><!-- /wp:html -->';

$pid = wp_insert_post( wp_slash( array(
	'post_type'    => 'post',
	'post_status'  => 'draft',
	'post_title'   => 'UNMAMFIX replace slash',
	'post_content' => $content,
) ) );

// JSON in meta with a quoted word. Unescaped slashes so the meta parser sees the uploads path.
$json = wp_json_encode( array( 'img' => $old_url, 'note' => 'say "hi"' ), JSON_UNESCAPED_SLASHES );
update_post_meta( $pid, 'unmamfix_json', wp_slash( $json ) );

UNMAM_Scanner::instance()->index_post( $pid );

global $wpdb;
$t     = UNMAM_Database::get_table_name( 'references' );
$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE attachment_id = %d", $old ) );

$fail = 0;
$ok   = function ( $cond, $label ) use ( &$fail ) {
	if ( ! $cond ) {
		$fail++;
	}
	printf( "%-5s %s\n", $cond ? 'ok' : 'FAIL', $label );
};

$meta_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE attachment_id = %d AND context_key = %s", $old, 'unmamfix_json' ) );

$ok( $found > 0, "precondition: old image indexed ($found rows)" );
$ok( $meta_rows > 0, "precondition: meta JSON indexed ($meta_rows rows)" );

if ( $found && $meta_rows ) {
	UNMAM_Attachment_Manager::instance()->replace_references( $old, $new );

	clean_post_cache( $pid );
	$after = get_post_field( 'post_content', $pid, 'raw' );
	$meta  = json_decode( (string) get_post_meta( $pid, 'unmamfix_json', true ), true );

	$ok( false !== strpos( $after, $new_url ) && false === strpos( $after, $old_url ), 'content: URL replaced' );
	$ok( false !== strpos( $after, 'C:\\temp\\file' ), 'content: backslash kept' );
	$ok( false !== strpos( $after, '\u003cb\u003e' ), 'content: \u003c escape kept' );
	$ok( false !== strpos( $after, 'wp-image-' . $old . '9"' ), "content: wp-image-{$old}9 left alone" );
	$ok( is_array( $meta ), 'meta: still valid JSON' );
	$ok( is_array( $meta ) && 'say "hi"' === $meta['note'], 'meta: quotes kept' );
	$ok( is_array( $meta ) && $new_url === $meta['img'], 'meta: URL replaced' );
}

wp_clear_scheduled_hook( 'unmam_index_single_post', array( $pid ) );
wp_delete_post( $pid, true );
foreach ( array( $old, $new ) as $att ) {
	UNMAM_Database::delete_references_by_attachment( $att );
	wp_delete_attachment( $att, true );
}

echo "\nfailures: $fail\n";
```

- [ ] **Step 2: Sync the current build and run it**

```bash
rsync -a --delete \
  --exclude '.git' --exclude '.DS_Store' --exclude '.wordpress-org' --exclude 'dev' \
  --exclude 'CLAUDE.md' --exclude 'progress.md' --exclude 'README.md' \
  --exclude '.svnignore' --exclude '.gitignore' --exclude '_unmam_*.php' \
  ~/Local\ Sites/plugins/unattached-media-manager/ \
  ~/Local\ Sites/flp/app/public/wp-content/plugins/unattached-media-manager/
dev/flpwp.sh eval-file dev/fixture/replace-slash.php
```

Expected before the fix: both preconditions ok, `content: URL replaced` ok, and FAIL on `backslash kept`, `\u003c escape kept`, `still valid JSON`, `quotes kept`, `meta: URL replaced` (the JSON no longer decodes), `wp-image-<old>9 left alone`. If the first precondition fails, `post` is not in FLP's `scan_post_types`; tell Sungraiz rather than changing his settings. If only the meta one fails, the meta parser did not index the key; report it, since the meta half of the fix can't be shown then.

If the bug does **not** reproduce, stop and report. The fix in Task 2 rests on it.

- [ ] **Step 3: Commit the check**

```bash
git add dev/fixture/replace-slash.php
git commit -m "Add a fixture showing Replace Media strips backslashes

Also shows the wp-image-12 / wp-image-123 prefix match.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Fix Replace Media slashing, unserialize and the wp-image prefix match

**Files:**
- Modify: `includes/class-unmam-attachment-manager.php` (the `post_content`/`block`, `postmeta`/`acf`, `term_meta`/`term_acf` branches of `replace_single_reference()`, around lines 220-265, `replace_in_content()` around line 300, and `replace_in_meta()` around line 333)

- [ ] **Step 1: Slash the content save**

Replace:

```php
                            wp_update_post( array(
                                'ID'           => $ref['source_id'],
                                'post_content' => $new_content,
                            ) );
```

with:

```php
                            // wp_insert_post() unslashes its input.
                            wp_update_post( wp_slash( array(
                                'ID'           => $ref['source_id'],
                                'post_content' => $new_content,
                            ) ) );
```

- [ ] **Step 2: Slash both meta saves**

Replace `update_post_meta( $ref['source_id'], $ref['context_key'], $new_meta );` with:

```php
                            update_post_meta( $ref['source_id'], $ref['context_key'], wp_slash( $new_meta ) );
```

Replace `update_term_meta( $ref['source_id'], $ref['context_key'], $new_meta );` with:

```php
                            update_term_meta( $ref['source_id'], $ref['context_key'], wp_slash( $new_meta ) );
```

Leave the `option` branch alone: `update_option()` does not unslash.

- [ ] **Step 3: Harden the unserialize in `replace_in_meta()`**

Replace:

```php
            // Check if it's a serialized value
            $unserialized = @unserialize( $value );
            if ( false !== $unserialized ) {
                $replaced = $this->replace_in_meta( $unserialized, $old_attachment_id, $new_attachment_id, $old_urls, $new_url );
                return serialize( $replaced );
            }
```

with:

```php
            if ( is_serialized( $value ) ) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes are never instantiated
                $unserialized = @unserialize( trim( $value ), array( 'allowed_classes' => false ) );

                // Re-serializing an object here would save it back as __PHP_Incomplete_Class.
                if ( false === $unserialized || $this->contains_object( $unserialized ) ) {
                    return $value;
                }

                $replaced = $this->replace_in_meta( $unserialized, $old_attachment_id, $new_attachment_id, $old_urls, $new_url );
                return serialize( $replaced );
            }
```

Add this method directly after `replace_in_meta()`:

```php
    /**
     * @param mixed $value
     * @return bool
     */
    private function contains_object( $value ) {
        if ( is_object( $value ) ) {
            return true;
        }

        if ( is_array( $value ) ) {
            foreach ( $value as $item ) {
                if ( $this->contains_object( $item ) ) {
                    return true;
                }
            }
        }

        return false;
    }
```

- [ ] **Step 4: Stop wp-image-12 matching wp-image-123**

In `replace_in_content()`, replace:

```php
        // Replace in class attributes (wp-image-XXX)
        $content = str_replace(
            'wp-image-' . $old_attachment_id,
            'wp-image-' . $new_attachment_id,
            $content
        );
```

with:

```php
        // Anchored, so replacing 12 leaves wp-image-123 alone.
        $content = preg_replace(
            '/\bwp-image-' . (int) $old_attachment_id . '(?!\d)/',
            'wp-image-' . (int) $new_attachment_id,
            $content
        );
```

- [ ] **Step 5: Sync and rerun the check**

Run the rsync from Task 1 Step 2, then:

```bash
dev/flpwp.sh eval-file dev/fixture/replace-slash.php
```

Expected: every line `ok`, `failures: 0`.

- [ ] **Step 6: Lint**

```bash
php -l includes/class-unmam-attachment-manager.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add includes/class-unmam-attachment-manager.php
git commit -m "Stop Replace Media stripping backslashes when it saves

wp_insert_post() and update_metadata() both unslash their input, so
escaped block attributes, code samples and any JSON value in meta lost
every backslash. Also stop unserializing objects while replacing, and
stop wp-image-12 matching inside wp-image-123.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: One list of design post types, offered for scanning

**Files:**
- Modify: `unattached-media-manager.php` (`unmam_get_scannable_post_type_candidates()`, lines 51-77)
- Modify: `includes/admin/class-unmam-admin.php` (built-in post type loop, around line 779)

- [ ] **Step 1: Add the list** directly above `unmam_get_scannable_post_type_candidates()`'s docblock:

```php
/**
 * Core post types that hold theme design saved through WordPress itself: Additional CSS,
 * Site Editor templates and parts, and Site Editor styles.
 *
 * Kept as a plain function rather than a class constant because the activation hook builds
 * the default settings before the plugin's classes are loaded.
 *
 * @return string[]
 */
function unmam_design_post_types()
{
    return array('custom_css', 'wp_template', 'wp_template_part', 'wp_global_styles');
}
```

- [ ] **Step 2: Offer them**

In `unmam_get_scannable_post_type_candidates()` replace:

```php
    $types = array_unique(array_merge(
        get_post_types(array('public' => true), 'names'),
        get_post_types(array('show_ui' => true), 'names')
    ));
```

with:

```php
    // The design types are neither public nor show_ui, so the two queries never see them.
    // post_type_exists() because 5.8 has no template parts or global styles.
    $types = array_unique(array_merge(
        get_post_types(array('public' => true), 'names'),
        get_post_types(array('show_ui' => true), 'names'),
        array_filter(unmam_design_post_types(), 'post_type_exists')
    ));
```

Nothing else is needed to switch them on: the reconcile in `get_setting()` ticks any candidate missing from `scan_post_types_known`, and fresh installs default to all candidates.

- [ ] **Step 3: Label `custom_css` the way the Customizer does**

In the "Built-in Post Types" loop of `class-unmam-admin.php`, replace:

```php
                                <?php foreach ( $builtin_types as $slug => $obj ) : ?>
                                    <label style="display:block; margin-bottom:4px;">
                                        <input type="checkbox" name="unmam_scan_post_type[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $scan_post_types_setting, true ) ); ?>>
                                        <?php echo esc_html( $obj->labels->name ); ?>
```

with:

```php
                                <?php foreach ( $builtin_types as $slug => $obj ) : ?>
                                    <label style="display:block; margin-bottom:4px;">
                                        <input type="checkbox" name="unmam_scan_post_type[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $scan_post_types_setting, true ) ); ?>>
                                        <?php echo esc_html( 'custom_css' === $slug ? __( 'Additional CSS', 'unattached-media-manager' ) : $obj->labels->name ); ?>
```

- [ ] **Step 4: Lint and check the candidates**

```bash
php -l unattached-media-manager.php && php -l includes/admin/class-unmam-admin.php
```

Run the rsync, then:

```bash
dev/flpwp.sh eval 'print_r(array_intersect(unmam_get_scannable_post_type_candidates(), unmam_design_post_types()));'
```

Expected: all four slugs listed.

- [ ] **Step 5: Commit**

```bash
git add unattached-media-manager.php includes/admin/class-unmam-admin.php
git commit -m "Offer Additional CSS, templates, parts and styles for scanning

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `UNMAM_Design_Content` helper

**Files:**
- Create: `includes/class-unmam-design-content.php`
- Modify: `unattached-media-manager.php` (`load_dependencies()`, after the reference extractor line)

- [ ] **Step 1: Create the class**

```php
<?php
/**
 * Theme design WordPress keeps in the database: Additional CSS, Site Editor templates and
 * template parts, and Site Editor styles. Each one belongs to a theme.
 *
 * @package UnattachedMediaManager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UNMAM_Design_Content {

    /**
     * @param string $post_type
     * @return bool
     */
    public static function is_design_type( $post_type ) {
        return in_array( $post_type, unmam_design_post_types(), true );
    }

    /**
     * The stylesheet a design post belongs to, as stored, or '' when it can't be told.
     *
     * Additional CSS keeps it in post_title (post_name is the sanitized copy). The other
     * three carry a wp_theme term named after the stylesheet.
     *
     * @param int    $post_id
     * @param string $post_type
     * @return string
     */
    public static function theme_of( $post_id, $post_type ) {
        if ( 'custom_css' === $post_type ) {
            $title = (string) get_post_field( 'post_title', $post_id, 'raw' );
            return '' !== $title ? $title : (string) get_post_field( 'post_name', $post_id, 'raw' );
        }

        $terms = get_the_terms( $post_id, 'wp_theme' );
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return '';
        }

        return (string) $terms[0]->name;
    }

    /**
     * Unknown theme counts as installed. Skipping it would report its images unused.
     *
     * @param string $stylesheet
     * @return bool
     */
    public static function is_installed( $stylesheet ) {
        if ( '' === $stylesheet ) {
            return true;
        }

        $wanted = sanitize_title( $stylesheet );
        foreach ( array_keys( wp_get_themes() ) as $installed ) {
            if ( sanitize_title( $installed ) === $wanted ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $stylesheet
     * @return bool
     */
    public static function is_active( $stylesheet ) {
        return '' !== $stylesheet && sanitize_title( $stylesheet ) === sanitize_title( get_stylesheet() );
    }

    /**
     * Where Used entry for a design post.
     *
     * Edit links only for the active theme: core builds template links from
     * get_stylesheet(), so for any other theme it would open the wrong template.
     *
     * @param WP_Post $post
     * @return array array( 'title', 'edit_link', 'view_link' )
     */
    public static function describe( $post ) {
        $stylesheet = self::theme_of( $post->ID, $post->post_type );

        switch ( $post->post_type ) {
            case 'custom_css':
                $label = __( 'Additional CSS', 'unattached-media-manager' );
                break;
            case 'wp_template':
                /* translators: %s: template title */
                $label = sprintf( __( 'Template: %s', 'unattached-media-manager' ), $post->post_title ? $post->post_title : $post->post_name );
                break;
            case 'wp_template_part':
                /* translators: %s: template part title */
                $label = sprintf( __( 'Template part: %s', 'unattached-media-manager' ), $post->post_title ? $post->post_title : $post->post_name );
                break;
            default:
                $label = __( 'Styles', 'unattached-media-manager' );
        }

        $title = $label;
        if ( '' !== $stylesheet ) {
            $theme = wp_get_theme( $stylesheet );
            $title = sprintf(
                /* translators: 1: design item, e.g. "Additional CSS", 2: theme name */
                __( '%1$s (%2$s)', 'unattached-media-manager' ),
                $label,
                $theme->exists() ? $theme->get( 'Name' ) : $stylesheet
            );
        }

        $edit_link = null;
        if ( self::is_active( $stylesheet ) ) {
            if ( 'custom_css' === $post->post_type ) {
                if ( current_user_can( 'edit_css' ) ) {
                    $edit_link = admin_url( 'customize.php?autofocus[section]=custom_css' );
                }
            } else {
                $edit_link = get_edit_post_link( $post->ID );
            }
        }

        return array(
            'title'     => $title,
            'edit_link' => $edit_link ? $edit_link : null,
            'view_link' => null,
        );
    }
}
```

- [ ] **Step 2: Load it** in `load_dependencies()`, after the `class-unmam-reference-extractor.php` line:

```php
        require_once UNMAM_PLUGIN_DIR . 'includes/class-unmam-design-content.php';
```

- [ ] **Step 3: Lint and smoke test**

```bash
php -l includes/class-unmam-design-content.php
```

Rsync, then:

```bash
dev/flpwp.sh eval 'var_dump(UNMAM_Design_Content::is_installed(get_stylesheet()), UNMAM_Design_Content::is_installed("unmamfix-gone-theme"), UNMAM_Design_Content::is_installed(""), UNMAM_Design_Content::is_active(get_stylesheet()));'
```

Expected: `bool(true) bool(false) bool(true) bool(true)`.

- [ ] **Step 4: Commit**

```bash
git add includes/class-unmam-design-content.php unattached-media-manager.php
git commit -m "Add a helper for design content and the theme it belongs to

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Extractor matches relative upload URLs on request

**Files:**
- Modify: `includes/class-unmam-reference-extractor.php`

- [ ] **Step 1: Pull the extension list into a constant** under `MAX_TEXT_LENGTH`:

```php
    /**
     * File extensions treated as media when matching URLs.
     */
    const MEDIA_EXTENSIONS = 'jpe?g|png|gif|webp|svg|bmp|ico|avif|mp4|m4v|webm|ogg|ogv|mp3|wav|m4a|pdf|docx?|pptx?|xlsx?|zip';
```

- [ ] **Step 2: Change `extract_from_text()`**

Update the docblock and signature:

```php
    /**
     * Extract attachment references from a text blob.
     *
     * Detects both `wp-image-{ID}` editor classes and uploaded-media URLs,
     * resolving URLs to attachment IDs via the shared resolver.
     *
     * @param mixed $text           Text to scan.
     * @param bool  $allow_relative Also match `/wp-content/uploads/...` and `//host/...`
     *                              forms, which CSS often uses. Off for every caller except
     *                              the design parser, so their results don't change.
     * @return array Map of attachment_id => matched URL (string) or attachment_id (int).
     */
    public static function extract_from_text( $text, $allow_relative = false ) {
```

Replace the URL regex line:

```php
        $url_re = '#https?://[^\s"\'<>()]+?\.(?:jpe?g|png|gif|webp|svg|bmp|ico|avif|mp4|m4v|webm|ogg|ogv|mp3|wav|m4a|pdf|docx?|pptx?|xlsx?|zip)#i';
```

with:

```php
        $url_re = '#https?://[^\s"\'<>()]+?\.(?:' . self::MEDIA_EXTENSIONS . ')#i';
```

And just before `return $results;` add:

```php
        if ( $allow_relative ) {
            foreach ( self::relative_upload_urls( $text ) as $url ) {
                $attachment_id = (int) UNMAM_Database::url_to_attachment_id( $url );
                if ( $attachment_id && ! isset( $results[ $attachment_id ] ) ) {
                    $results[ $attachment_id ] = $url;
                }
            }
        }
```

- [ ] **Step 3: Add the helper** after `extract_from_text()`:

```php
    /**
     * Upload URLs written without a scheme or host, made absolute.
     *
     * The lookbehind keeps this off the tail of an absolute URL, which the main pattern
     * has already handled.
     *
     * @param string $text
     * @return string[]
     */
    private static function relative_upload_urls( $text ) {
        $baseurl = untrailingslashit( wp_get_upload_dir()['baseurl'] );
        $path    = untrailingslashit( (string) wp_parse_url( $baseurl, PHP_URL_PATH ) );

        // Uploads served from a domain root leave nothing to anchor on.
        if ( '' === $path ) {
            return array();
        }

        $re = '#(?<![\w.:/-])(?://[^\s"\'<>()/]+)?' . preg_quote( $path, '#' ) . '/[^\s"\'<>()]+?\.(?:' . self::MEDIA_EXTENSIONS . ')#i';
        if ( ! preg_match_all( $re, $text, $matches ) ) {
            return array();
        }

        $origin = substr( $baseurl, 0, -strlen( $path ) );
        $scheme = (string) wp_parse_url( $baseurl, PHP_URL_SCHEME );

        $urls = array();
        foreach ( array_unique( $matches[0] ) as $found ) {
            $urls[] = 0 === strpos( $found, '//' ) ? $scheme . ':' . $found : $origin . $found;
        }

        return $urls;
    }
```

- [ ] **Step 4: Lint and check against a real FLP image**

```bash
php -l includes/class-unmam-reference-extractor.php
```

Rsync, then:

```bash
dev/flpwp.sh eval '
$id   = (int) get_posts(array("post_type"=>"attachment","post_mime_type"=>"image","posts_per_page"=>1,"fields"=>"ids"))[0];
$url  = wp_get_attachment_url($id);
$path = wp_parse_url($url, PHP_URL_PATH);
$host = wp_parse_url($url, PHP_URL_HOST);
$x = "UNMAM_Reference_Extractor::extract_from_text";
printf("abs default   %s\n", isset($x("url($url)")[$id]) ? "ok" : "FAIL");
printf("root default  %s\n", array() === $x("url($path)") ? "ok" : "FAIL");
printf("root allowed  %s\n", isset($x("url($path)", true)[$id]) ? "ok" : "FAIL");
printf("proto allowed %s\n", isset($x("url(//$host$path)", true)[$id]) ? "ok" : "FAIL");
printf("dotdot        %s\n", array() === $x("url(../uploads/x.png)", true) ? "ok" : "FAIL");'
```

Expected: five `ok`. This only reads; nothing is written.

- [ ] **Step 5: Commit**

```bash
git add includes/class-unmam-reference-extractor.php
git commit -m "Let the extractor match root- and protocol-relative upload URLs

Opt-in through a second argument so existing callers behave exactly as
before.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Design parser

**Files:**
- Create: `includes/parsers/class-unmam-design-parser.php`
- Modify: `unattached-media-manager.php` (`load_dependencies()`, after the custom-table parser line)
- Modify: `includes/class-unmam-scanner.php` (`init_parsers()`, after the meta parser)

- [ ] **Step 1: Create the parser**

```php
<?php
/**
 * Design Parser for Unattached Media Manager
 *
 * Additional CSS and Site Editor styles aren't markup, so the content and block parsers
 * find nothing in them. Templates and template parts are block markup and are left to
 * those parsers.
 *
 * @package UnattachedMediaManager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UNMAM_Design_Parser implements UNMAM_Parser_Interface {

    public function get_name() {
        return 'design';
    }

    /**
     * @param WP_Post|object $post Batch scans pass a partial row: ID, post_type, post_content, post_title.
     * @return array
     */
    public function parse_post( $post ) {
        if ( 'custom_css' === $post->post_type ) {
            $label = __( 'Additional CSS', 'unattached-media-manager' );
        } elseif ( 'wp_global_styles' === $post->post_type ) {
            $label = __( 'Styles', 'unattached-media-manager' );
        } else {
            return array();
        }

        // Styles saved before core switched to JSON_UNESCAPED_SLASHES have \/ throughout.
        $text = str_replace( '\/', '/', (string) $post->post_content );

        $references = array();
        foreach ( UNMAM_Reference_Extractor::extract_from_text( $text, true ) as $attachment_id => $matched ) {
            $references[] = array(
                'attachment_id'   => (int) $attachment_id,
                'source_id'       => (int) $post->ID,
                'source_type'     => 'post',
                'context_type'    => 'post_content',
                'context_key'     => null,
                'context_label'   => $label,
                'reference_type'  => is_string( $matched ) ? 'url' : 'id',
                'reference_value' => is_string( $matched ) ? $matched : null,
            );
        }

        return $references;
    }
}
```

- [ ] **Step 2: Load it** in `load_dependencies()`, after the custom-table parser line:

```php
        require_once UNMAM_PLUGIN_DIR . 'includes/parsers/class-unmam-design-parser.php';
```

- [ ] **Step 3: Register it** in `UNMAM_Scanner::init_parsers()`, right after `$this->parsers['meta'] = new UNMAM_Meta_Parser();`:

```php

        // Additional CSS and Site Editor styles. Returns nothing for any other post type.
        $this->parsers['design'] = new UNMAM_Design_Parser();
```

- [ ] **Step 4: Lint**

```bash
php -l includes/parsers/class-unmam-design-parser.php && php -l includes/class-unmam-scanner.php && php -l unattached-media-manager.php
```

Expected: three `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add includes/parsers/class-unmam-design-parser.php includes/class-unmam-scanner.php unattached-media-manager.php
git commit -m "Read Additional CSS and Site Editor styles for media

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Scanner skips deleted themes and never attaches to design posts

**Files:**
- Modify: `includes/class-unmam-scanner.php` (`index_post()`, lines ~315 and ~359)

- [ ] **Step 1: Skip design posts of deleted themes**

Replace:

```php
        // Clear existing references for this post
        UNMAM_Database::delete_references_by_source( $post_id, 'post' );
```

with:

```php
        // Clear existing references for this post
        UNMAM_Database::delete_references_by_source( $post_id, 'post' );

        // Saved design for a theme that has been deleted can't show anywhere. Its rows were
        // just cleared, which is the point.
        $is_design = UNMAM_Design_Content::is_design_type( $post->post_type );
        if ( $is_design && ! UNMAM_Design_Content::is_installed( UNMAM_Design_Content::theme_of( $post_id, $post->post_type ) ) ) {
            return;
        }
```

- [ ] **Step 2: No auto-attach for design posts**

Replace:

```php
        if ( ! empty( $settings['auto_attach'] ) && ! empty( $attachment_ids ) ) {
```

with:

```php
        // A template or a stylesheet is not a page an image can belong to.
        if ( ! empty( $settings['auto_attach'] ) && ! empty( $attachment_ids ) && ! $is_design ) {
```

- [ ] **Step 3: Lint**

```bash
php -l includes/class-unmam-scanner.php
```

- [ ] **Step 4: Commit**

```bash
git add includes/class-unmam-scanner.php
git commit -m "Skip design saved for deleted themes, never attach to design posts

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Where Used and the attach queries

**Files:**
- Modify: `includes/class-unmam-database.php` (`describe_source()` ~line 441; `get_used_but_unattached()` ~1179; `get_used_but_unattached_count()` ~1213; `get_used_but_unattached_detailed()` count and results queries ~1516 and ~1528; new private helper next to `safe_meta_clause()`)

- [ ] **Step 1: Where Used branch**

In `describe_source()`, replace:

```php
        if ( 'post' === $source_type ) {
            $title = get_the_title( $source_id );
```

with:

```php
        if ( 'post' === $source_type ) {
            $source_post = get_post( $source_id );
            if ( $source_post && UNMAM_Design_Content::is_design_type( $source_post->post_type ) ) {
                return UNMAM_Design_Content::describe( $source_post );
            }

            $title = get_the_title( $source_id );
```

- [ ] **Step 2: Add the SQL helper** directly above `safe_meta_clause()`:

```php
    /**
     * Keeps design posts out of attach suggestions. Correlates on the references alias `r`.
     *
     * The type names are a fixed list from code, never user input.
     *
     * @return string
     */
    private static function design_source_clause() {
        global $wpdb;

        $types = "'" . implode( "','", array_map( 'esc_sql', unmam_design_post_types() ) ) . "'";

        return " AND r.source_id NOT IN (SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$types}))";
    }
```

- [ ] **Step 3: Use it in all four queries**

Each of these four queries contains the line `AND r.source_type = 'post'`. In each, append the clause right after that line:

`get_used_but_unattached()`:

```php
            AND r.source_type = 'post'" . self::design_source_clause() . "
            GROUP BY p.ID, p.post_title
```

`get_used_but_unattached_count()`:

```php
            AND r.source_type = 'post'" . self::design_source_clause()
```

(the closing `);` of `get_var(` follows unchanged)

`get_used_but_unattached_detailed()` count query:

```php
            AND r.source_type = 'post'" . self::design_source_clause()
```

`get_used_but_unattached_detailed()` results query (inside `prepare()`; the clause has no `%` so it is safe there):

```php
                AND r.source_type = 'post'" . self::design_source_clause() . "
                GROUP BY p.ID, p.post_title, p.post_mime_type, p.post_date
```

Read each edited query back once to confirm the quotes close correctly; the existing `phpcs:disable ... InterpolatedNotPrepared` blocks already cover them.

- [ ] **Step 4: Lint and smoke test**

```bash
php -l includes/class-unmam-database.php
```

Rsync, then:

```bash
dev/flpwp.sh eval 'echo count(UNMAM_Database::get_used_but_unattached()), " ", UNMAM_Database::get_used_but_unattached_count(), " ", UNMAM_Database::get_used_but_unattached_detailed(array("per_page"=>5))["total"], "\n"; global $wpdb; echo $wpdb->last_error ?: "no sql error", "\n";'
```

Expected: three equal numbers (FLP has no design rows yet, so they match pre-change counts), then `no sql error`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-unmam-database.php
git commit -m "Name design content in Where Used, keep it out of attach suggestions

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Design fixture, end to end

**Files:**
- Create: `dev/fixture/design-seed.php`, `dev/fixture/design-check.php`, `dev/fixture/design-teardown.php`

- [ ] **Step 1: Seed script**

```php
<?php
/**
 * One throwaway image per design-content case, and the design posts that use them.
 * Additional CSS is appended to, never replaced; teardown puts the original back.
 *
 * Run: dev/flpwp.sh eval-file dev/fixture/design-seed.php
 */

require_once ABSPATH . 'wp-admin/includes/image.php';

if ( get_option( 'unmamfix_design' ) ) {
	echo "already seeded - run design-teardown.php first\n";
	return;
}

$active   = get_stylesheet();
$inactive = 'genesis-block-theme';
if ( $inactive === $active || ! wp_get_theme( $inactive )->exists() ) {
	echo "need $inactive installed and not active\n";
	return;
}

function unmam_ds_image( $name ) {
	$dir  = wp_upload_dir();
	$path = $dir['path'] . '/' . $name . '.png';
	$im   = imagecreatetruecolor( 40, 40 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, crc32( $name ) % 200, 90, 160 ) );
	imagepng( $im, $path );
	imagedestroy( $im );

	$id = wp_insert_attachment( array(
		'post_title'     => 'UNMAMFIX ' . $name,
		'post_mime_type' => 'image/png',
		'post_status'    => 'inherit',
	), $path );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
	return $id;
}

// Old date so a real global styles post, if any, still wins core's newest-first lookup.
function unmam_ds_post( $type, $slug, $title, $content, $theme ) {
	$id = wp_insert_post( wp_slash( array(
		'post_type'    => $type,
		'post_name'    => $slug,
		'post_title'   => $title,
		'post_status'  => 'publish',
		'post_content' => $content,
		'post_date'    => '2000-01-01 00:00:00',
	) ), true );

	if ( is_wp_error( $id ) ) {
		echo "$type $slug: " . $id->get_error_message() . "\n";
		return 0;
	}

	wp_set_object_terms( $id, $theme, 'wp_theme' );
	if ( 'wp_template_part' === $type ) {
		wp_set_object_terms( $id, 'uncategorized', 'wp_template_part_area' );
	}
	return $id;
}

// As an admin, like a real Site Editor save. With no user, kses filters the content and
// the global styles sanitizer may drop the background image.
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );

global $wpdb;
$max_id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );

$img = array();
foreach ( array( 'dcssabs', 'dcssroot', 'dcssproto', 'dpart', 'dtpl', 'dstyles', 'dinactive', 'dgone', 'dreplace' ) as $n ) {
	$img[ $n ] = unmam_ds_image( $n );
}
$url  = function ( $n ) use ( $img ) {
	return wp_get_attachment_url( $img[ $n ] );
};
$path = function ( $n ) use ( $url ) {
	return wp_parse_url( $url( $n ), PHP_URL_PATH );
};
$host = wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_HOST );

// Additional CSS for the active theme: absolute, root-relative, protocol-relative.
$css_post = wp_get_custom_css_post( $active );
$backup   = array(
	'existed' => (bool) $css_post,
	'css'     => $css_post ? $css_post->post_content : '',
);
$css = $backup['css'] . "\n/* UNMAMFIX */\n"
	. '.unmamfix-a{background:url(' . $url( 'dcssabs' ) . ")}\n"
	. '.unmamfix-b{background:url("' . $path( 'dcssroot' ) . "\")}\n"
	. '.unmamfix-c{background:url(//' . $host . $path( 'dcssproto' ) . ")}\n";
$css_result = wp_update_custom_css_post( $css, array( 'stylesheet' => $active ) );
if ( is_wp_error( $css_result ) ) {
	echo 'custom css: ' . $css_result->get_error_message() . "\n";
	return;
}

$posts = array();

// Gal's case: an absolute URL in a sidebar part, no attachment id anywhere.
$posts['dpart'] = unmam_ds_post( 'wp_template_part', 'unmamfix-sidebar', 'UNMAMFIX sidebar',
	"<!-- wp:image {\"sizeSlug\":\"full\"} -->\n<figure class=\"wp-block-image size-full\"><img src=\"" . $url( 'dpart' ) . "\" alt=\"\"/></figure>\n<!-- /wp:image -->",
	$active );

$posts['dtpl'] = unmam_ds_post( 'wp_template', 'unmamfix-template', 'UNMAMFIX template',
	'<!-- wp:cover {"url":"' . $url( 'dtpl' ) . '","dimRatio":50} -->' . "\n"
	. '<div class="wp-block-cover"><img class="wp-block-cover__image-background" alt="" src="' . $url( 'dtpl' ) . '" data-object-fit="cover"/><div class="wp-block-cover__inner-container"></div></div>' . "\n"
	. '<!-- /wp:cover -->',
	$active );

// Default wp_json_encode() escapes slashes: the pre-JSON_UNESCAPED_SLASHES format.
$posts['dstyles'] = unmam_ds_post( 'wp_global_styles', 'unmamfix-global-styles', 'Custom Styles',
	wp_json_encode( array(
		'version'                     => 3,
		'isGlobalStylesUserThemeJSON' => true,
		'styles'                      => array( 'background' => array( 'backgroundImage' => array( 'url' => $url( 'dstyles' ), 'id' => $img['dstyles'] ) ) ),
		'settings'                    => new stdClass(),
	) ),
	$active );

$posts['dinactive'] = unmam_ds_post( 'wp_template_part', 'unmamfix-inactive-part', 'UNMAMFIX inactive part',
	'<!-- wp:image --><figure class="wp-block-image"><img src="' . $url( 'dinactive' ) . '" alt=""/></figure><!-- /wp:image -->',
	$inactive );

$posts['dgone'] = unmam_ds_post( 'wp_template_part', 'unmamfix-gone-part', 'UNMAMFIX gone part',
	'<!-- wp:image --><figure class="wp-block-image"><img src="' . $url( 'dgone' ) . '" alt=""/></figure><!-- /wp:image -->',
	'unmamfix-gone-theme' );

update_option( 'unmamfix_design', array(
	'max_id' => $max_id,
	'css_id' => (int) $css_result->ID,
	'css'    => $backup,
	'img'    => $img,
	'posts'  => $posts,
), false );

echo 'seeded ' . count( $img ) . ' images, ' . count( array_filter( $posts ) ) . " design posts, css post {$css_result->ID}\n";
```

- [ ] **Step 2: Check script**

```php
<?php
/**
 * Asserts the design fixture. By default it indexes the fixture posts itself, with
 * auto-attach switched on for the run. Pass `noindex` to check what a full scan left.
 *
 * Run: dev/flpwp.sh eval-file dev/fixture/design-check.php [noindex]
 */

global $wpdb;

$fx = get_option( 'unmamfix_design' );
if ( ! $fx ) {
	echo "run design-seed.php first\n";
	return;
}

// Edit links depend on capabilities.
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );

$t    = UNMAM_Database::get_table_name( 'references' );
$fail = 0;
$ok   = function ( $cond, $label ) use ( &$fail ) {
	if ( ! $cond ) {
		$fail++;
	}
	printf( "%-5s %s\n", $cond ? 'ok' : 'FAIL', $label );
};

$settings = Unattached_Media_Manager::get_setting();
foreach ( unmam_design_post_types() as $type ) {
	$ok( in_array( $type, $settings['scan_post_types'], true ), "$type ticked" );
}

$design_ids = array_merge( array( $fx['css_id'] ), array_values( array_filter( $fx['posts'] ) ) );

if ( empty( $args ) || 'noindex' !== $args[0] ) {
	$real = get_option( 'unmam_settings' );
	update_option( 'unmam_settings', array_merge( $real, array( 'auto_attach' => true ) ) );
	foreach ( $design_ids as $pid ) {
		UNMAM_Scanner::instance()->index_post( $pid );
	}
	update_option( 'unmam_settings', $real );
}

$rows = function ( $key ) use ( $wpdb, $t, $fx ) {
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE attachment_id = %d", $fx['img'][ $key ] ), ARRAY_A );
};

$active_name   = wp_get_theme()->get( 'Name' );
$inactive_name = wp_get_theme( 'genesis-block-theme' )->get( 'Name' );

// image => [ expected label, belongs to active theme ]
$expect = array(
	'dcssabs'   => array( 'Additional CSS', true ),
	'dcssroot'  => array( 'Additional CSS', true ),
	'dcssproto' => array( 'Additional CSS', true ),
	'dpart'     => array( 'Template part: UNMAMFIX sidebar', true ),
	'dtpl'      => array( 'Template: UNMAMFIX template', true ),
	'dstyles'   => array( 'Styles', true ),
	'dinactive' => array( 'Template part: UNMAMFIX inactive part', false ),
);

foreach ( $expect as $key => $e ) {
	$found = $rows( $key );
	$ok( count( $found ) > 0, "$key referenced" );
	if ( ! $found ) {
		continue;
	}

	$src  = UNMAM_Database::describe_source( $found[0] );
	$want = sprintf( '%s (%s)', $e[0], $e[1] ? $active_name : $inactive_name );
	$ok( $src && $want === $src['title'], "$key title: " . ( $src ? $src['title'] : 'null' ) );
	$ok( $src && ( $e[1] ? ! empty( $src['edit_link'] ) : empty( $src['edit_link'] ) ), "$key edit link " . ( $e[1] ? 'present' : 'absent' ) );
	$ok( $src && null === $src['view_link'], "$key no view link" );
	$ok( 0 === (int) get_post_field( 'post_parent', $fx['img'][ $key ] ), "$key not attached" );
}

$ok( 0 === count( $rows( 'dgone' ) ), 'deleted theme skipped' );

$suggested = array_map( 'intval', wp_list_pluck( UNMAM_Database::get_used_but_unattached(), 'attachment_id' ) );
$ok( ! array_intersect( $suggested, array_values( $fx['img'] ) ), 'no fixture image offered for attach' );
$ok( count( UNMAM_Database::get_used_but_unattached() ) === UNMAM_Database::get_used_but_unattached_count(), 'attach list and count agree' );

// Replace Media on Additional CSS. Done last because it changes the fixture.
if ( empty( $args ) || 'noindex' !== $args[0] ) {
	UNMAM_Attachment_Manager::instance()->replace_references( $fx['img']['dcssabs'], $fx['img']['dreplace'] );
	$css = get_post_field( 'post_content', $fx['css_id'], 'raw' );
	$ok( false !== strpos( $css, wp_get_attachment_url( $fx['img']['dreplace'] ) ), 'replace: new URL in Additional CSS' );
	$ok( false === strpos( $css, wp_get_attachment_url( $fx['img']['dcssabs'] ) ), 'replace: old URL gone' );
	$ok( false !== strpos( $css, '.unmamfix-c{' ), 'replace: rest of the CSS intact' );
}

echo "\nfailures: $fail\n";
```

- [ ] **Step 3: Teardown script**

```php
<?php
/**
 * Removes the design fixture and puts Additional CSS back as it was.
 * Run: dev/flpwp.sh eval-file dev/fixture/design-teardown.php
 */

global $wpdb;

$fx = get_option( 'unmamfix_design' );
if ( ! $fx ) {
	echo "nothing to tear down\n";
	return;
}

$active = get_stylesheet();

if ( $fx['css']['existed'] ) {
	wp_update_custom_css_post( $fx['css']['css'], array( 'stylesheet' => $active ) );
} else {
	wp_delete_post( $fx['css_id'], true );
	remove_theme_mod( 'custom_css_post_id' );
}

foreach ( array_filter( $fx['posts'] ) as $pid ) {
	wp_clear_scheduled_hook( 'unmam_index_single_post', array( $pid ) );
	UNMAM_Database::delete_references_by_source( $pid, 'post' );
	wp_delete_post( $pid, true );
}

// Revisions the seed and teardown made on the Additional CSS post.
$revs = $wpdb->get_col( $wpdb->prepare(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d AND ID > %d",
	$fx['css_id'],
	$fx['max_id']
) );
foreach ( $revs as $rev ) {
	wp_delete_post( (int) $rev, true );
}
wp_clear_scheduled_hook( 'unmam_index_single_post', array( $fx['css_id'] ) );

foreach ( $fx['img'] as $att ) {
	UNMAM_Database::delete_references_by_attachment( $att );
	wp_delete_attachment( $att, true );
}

// The fixture's theme term for the deleted-theme case.
$gone = get_term_by( 'name', 'unmamfix-gone-theme', 'wp_theme' );
if ( $gone ) {
	wp_delete_term( $gone->term_id, 'wp_theme' );
}

delete_option( 'unmamfix_design' );

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'UNMAMFIX%' OR post_name LIKE 'unmamfix%'" );
echo "torn down, UNMAMFIX posts left: $left\n";
```

- [ ] **Step 4: Run seed and direct check**

```bash
dev/flpwp.sh eval-file dev/fixture/design-seed.php
dev/flpwp.sh eval-file dev/fixture/design-check.php
```

Expected: `seeded 9 images, 5 design posts, ...`, then every line `ok`, `failures: 0`. If `dpart referenced` fails, the content parser does not resolve a bare `src` with no `wp-image` class; stop and report rather than widening the design parser to templates (spec says templates go through the existing readers).

- [ ] **Step 5: Full scan path** (batch passes partial rows, so it must be tested separately)

Teardown, reseed, then scan. Leave auto-attach as FLP has it; a full scan with it on would attach FLP's real media.

```bash
dev/flpwp.sh eval-file dev/fixture/design-teardown.php
dev/flpwp.sh eval-file dev/fixture/design-seed.php
dev/flpwp.sh unmam scan --reset --batch-size=200
dev/flpwp.sh eval-file dev/fixture/design-check.php noindex
```

Expected: `failures: 0` (the replace lines are skipped in `noindex` mode).

- [ ] **Step 6: Untick and retick** (spec test 4)

```bash
dev/flpwp.sh eval '$s=get_option("unmam_settings"); $s["scan_post_types"]=array_values(array_diff($s["scan_post_types"],array("wp_template_part"))); update_option("unmam_settings",$s);'
dev/flpwp.sh unmam scan --reset --batch-size=200
dev/flpwp.sh eval '$fx=get_option("unmamfix_design"); global $wpdb; $t=UNMAM_Database::get_table_name("references"); echo $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE attachment_id=%d",$fx["img"]["dpart"])), "\n";'
```

Expected: `0`. Then put the setting back from the snapshot and rescan:

```bash
dev/flpwp.sh eval '$s=get_option("unmam_settings"); $s["scan_post_types"][]="wp_template_part"; update_option("unmam_settings",$s);'
dev/flpwp.sh unmam scan --reset --batch-size=200
dev/flpwp.sh eval-file dev/fixture/design-check.php noindex
```

Expected: `failures: 0`.

- [ ] **Step 7: Teardown and confirm**

```bash
dev/flpwp.sh eval-file dev/fixture/design-teardown.php
```

Expected: `UNMAMFIX posts left: 0`.

- [ ] **Step 8: Commit the fixture**

```bash
git add dev/fixture/design-seed.php dev/fixture/design-check.php dev/fixture/design-teardown.php
git commit -m "Add the design content fixture

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Upgrade path and settings page

**Files:**
- Create: `dev/fixture/design-upgrade-check.php`

- [ ] **Step 1: Write the check**

```php
<?php
/**
 * Settings saved by 1.3.2, with Pages deliberately unticked: after the update the four
 * design types are ticked and Pages stays off. Restores the real settings at the end.
 *
 * Run: dev/flpwp.sh eval-file dev/fixture/design-upgrade-check.php
 */

$real = get_option( 'unmam_settings' );

$old_candidates = array_values( array_diff( unmam_get_scannable_post_type_candidates(), unmam_design_post_types() ) );

$shape                          = $real;
$shape['scan_post_types_known'] = $old_candidates;
$shape['scan_post_types']       = array_values( array_diff( $old_candidates, array( 'page' ) ) );
update_option( 'unmam_settings', $shape );

// Read several times: the reconcile must settle, not flip back and forth.
for ( $i = 0; $i < 3; $i++ ) {
	$s = Unattached_Media_Manager::get_setting();
}

$fail = 0;
foreach ( unmam_design_post_types() as $type ) {
	$on = in_array( $type, $s['scan_post_types'], true );
	$fail += $on ? 0 : 1;
	printf( "%-5s %s ticked\n", $on ? 'ok' : 'FAIL', $type );
}

$page_off = ! in_array( 'page', $s['scan_post_types'], true );
$fail    += $page_off ? 0 : 1;
printf( "%-5s page still unticked\n", $page_off ? 'ok' : 'FAIL' );

update_option( 'unmam_settings', $real );
echo "\nfailures: $fail\n";
```

- [ ] **Step 2: Run it**

```bash
dev/flpwp.sh eval-file dev/fixture/design-upgrade-check.php
```

Expected: five `ok`, `failures: 0`.

- [ ] **Step 3: Settings page renders the new boxes** (curl method from `dev/TESTING.md`, "Admin UI without a browser")

Fetch `https://flp.local/wp-admin/admin.php?page=unattached-media-manager&tab=settings` with the generated cookies, then:

```bash
grep -o 'value="\(custom_css\|wp_template\|wp_template_part\|wp_global_styles\)"[^>]*checked' page.html | wc -l
grep -c 'Additional CSS' page.html
```

Expected: `4`, and at least `1`. Delete the cookie jar and `page.html` afterwards. The save handler needs no change: it keeps any submitted slug that is in `unmam_get_scannable_post_type_candidates()`, which now includes the four.

- [ ] **Step 4: Commit**

```bash
git add dev/fixture/design-upgrade-check.php
git commit -m "Add an upgrade check for the design post types

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Regression and Plugin Check

**Files:** none

- [ ] **Step 1: Existing fixture still at 6 of 7**

```bash
cp dev/fixture/unmam-test-harness.php ~/Local\ Sites/flp/app/public/wp-content/mu-plugins/
dev/flpwp.sh eval-file dev/fixture/seed.php
dev/flpwp.sh unmam scan --reset --batch-size=200
dev/flpwp.sh eval-file dev/fixture/check.php
dev/flpwp.sh eval-file dev/fixture/teardown.php
rm ~/Local\ Sites/flp/app/public/wp-content/mu-plugins/unmam-test-harness.php
```

Expected: `undetected cases : 1 of 7`, only `themecss` (that is 1.4.0). `orphan ref ids : 0`.

- [ ] **Step 2: Replace Media check once more on the final build**

```bash
dev/flpwp.sh eval-file dev/fixture/replace-slash.php
```

Expected: `failures: 0`.

- [ ] **Step 3: Plugin Check against 1.3.2**

```bash
dev/flpwp.sh plugin check unattached-media-manager --require=dev/plugin-check.php \
  --format=csv --fields=file,line,type,code > /tmp/pc-133.csv
cut -d, -f3,4 /tmp/pc-133.csv | sort | uniq -c | sort -rn > /tmp/pc-133-counts.txt
```

Then `git archive 1.3.2` into a temp folder, rsync that into FLP, run the same command into `/tmp/pc-132.csv`, diff the counts, and rsync the feature branch back. Expected: no new `ERROR` codes; the four known `PreparedSQL.NotPrepared` errors only. New warnings must each be explained (for example the `unserialize` ignore) or fixed.

---

### Task 12: Version, changelog, docs

**Files:**
- Modify: `unattached-media-manager.php` (header `Version:` line 7, `UNMAM_VERSION` line 111)
- Modify: `readme.txt` (`Stable tag:`, changelog, upgrade notice)
- Modify: `CLAUDE.md`, `dev/TESTING.md`, `dev/ROADMAP.md`, `progress.md`

- [ ] **Step 1: Bump all three together**

`unattached-media-manager.php`: ` * Version: 1.3.3` and `define('UNMAM_VERSION', '1.3.3');`. `readme.txt`: `Stable tag: 1.3.3`.

- [ ] **Step 2: Changelog**: insert above `= 1.3.2 =` under `== Changelog ==`:

```
= 1.3.3 =
**Finds images used in your theme's design settings.**

* **Images used only in Additional CSS, an edited template or template part, or Site Editor styles are now found.** They used to be listed as unused. Saved design from every installed theme counts; design left behind by a deleted theme does not.
* Where Used names the place and the theme, for example "Template part: Sidebar (Twenty Twenty-Five)", with an edit link when it belongs to the active theme.
* Images are never attached to these, since a stylesheet or a template is not a page.
* **Replace Media no longer removes backslashes from content it updates.** Blocks that keep quotes or symbols in their settings, code samples, and JSON stored in custom fields could be damaged.
* **Replace Media no longer touches the wrong image.** Replacing image 12 also changed the tag of image 123, or any other image whose number started with 12, on the same page.
* Images used only in theme files (stylesheets or templates that were never edited in the Site Editor) are still not detected. That is planned.
```

And under `== Upgrade Notice ==`, above `= 1.3.2 =`:

```
= 1.3.3 =
Images used only in Additional CSS or the Site Editor could be listed as unused. Run a full scan after updating. Also fixes two Replace Media bugs: it removed backslashes from content, and replacing one image could change the tag of another whose number started the same way.
```

- [ ] **Step 3: CLAUDE.md**: add after the "Terms (1.3.0)" block in section 3:

```markdown
**Design content (1.3.3)**

- `custom_css`, `wp_template`, `wp_template_part` and `wp_global_styles` are scanned as ordinary post types. `unmam_design_post_types()` is the one list; it is a plain function because activation builds default settings before classes load.
- Rows are `source_type` `post`, so every place that excludes design posts does it by post type: `index_post()` (no auto-attach, deleted-theme skip) and `UNMAM_Database::design_source_clause()` in the four attach queries. A new attach query needs the clause too.
- A design post whose theme is not installed is skipped after its rows are cleared. An unknown theme counts as installed.
- Edit links only for the active theme: core builds template edit links from `get_stylesheet()`.
- `UNMAM_Design_Parser` only reads `custom_css` and `wp_global_styles`. Templates and parts go through the content and block parsers.
- `extract_from_text( $text, true )` also matches root- and protocol-relative upload URLs. Only the design parser passes `true`; 1.4.0's file scan should too.

**Replace Media**

- Anything saved through `wp_insert_post()`, `update_post_meta()` or `update_term_meta()` must be `wp_slash()`ed. They unslash their input, and without it every backslash in content and JSON meta was lost before 1.3.3.
- `replace_in_meta()` never instantiates classes when unserializing, and leaves a value holding an object untouched.
- `wp-image-{ID}` replacement is anchored. A plain `str_replace()` turned `wp-image-123` into `wp-image-<new>3` when replacing 12.
```

- [ ] **Step 4: TESTING.md**: add a section after "The fixture":

````markdown
## Design content fixture (1.3.3)

Needs a block theme active and `genesis-block-theme` installed but inactive (true on FLP).

```bash
dev/flpwp.sh eval-file dev/fixture/design-seed.php
dev/flpwp.sh eval-file dev/fixture/design-check.php          # indexes directly, auto-attach on
dev/flpwp.sh eval-file dev/fixture/design-teardown.php
dev/flpwp.sh eval-file dev/fixture/design-seed.php
dev/flpwp.sh unmam scan --reset --batch-size=200
dev/flpwp.sh eval-file dev/fixture/design-check.php noindex  # batch path
dev/flpwp.sh eval-file dev/fixture/design-teardown.php
dev/flpwp.sh eval-file dev/fixture/design-upgrade-check.php  # restores settings itself
dev/flpwp.sh eval-file dev/fixture/replace-slash.php         # cleans up itself
```

The seed appends to the active theme's Additional CSS and teardown restores it. The fixture global styles post is dated 2000 so a real one keeps winning core's newest-first lookup.
````

- [ ] **Step 5: ROADMAP.md**

Add a "1.3.3 — Design content scanning (BUILT, NOT RELEASED)" section above 1.4.0 pointing at the spec; it becomes SHIPPED with the date when Sungraiz deploys. In the 1.4.0 section, replace the line about the REST `/scan/batch` hardcoded enum with: "The REST `/scan/batch` enum already reads `get_active_scan_types()` (since 1.3.0); nothing to add there."

- [ ] **Step 6: progress.md**: overwrite Done / Decisions / Next steps for 1.3.3 (max 30 lines; keep it current, not a log).

- [ ] **Step 7: Commit**

```bash
git add unattached-media-manager.php readme.txt CLAUDE.md dev/TESTING.md dev/ROADMAP.md progress.md
git commit -m "Release 1.3.3: design content scanning

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Leave FLP as found

**Files:** none

- [ ] **Step 1: Compare against the snapshot**

```bash
dev/flpwp.sh eval 'global $wpdb;
$now = $wpdb->get_col("SELECT CONCAT(ID,\"|\",post_type,\"|\",post_name) FROM {$wpdb->posts} WHERE post_type IN (\"custom_css\",\"wp_template\",\"wp_template_part\",\"wp_global_styles\",\"revision\")");
$was = explode("\n", file_get_contents("/tmp/unmam133-posts.txt"));
print_r(array_diff($now, $was));
$opts = $wpdb->get_col("SELECT option_name FROM {$wpdb->options}");
print_r(array_diff($opts, explode("\n", file_get_contents("/tmp/unmam133-options.txt"))));
echo serialize(get_option("unmam_settings")) === file_get_contents("/tmp/unmam133-settings.txt") ? "settings unchanged\n" : "SETTINGS DIFFER\n";'
```

Expected: no fixture posts left. New options only from cron or transients, not `unmamfix_*`. Settings: the four design types are now ticked (that is the upgrade working), everything else identical. Anything unexpected: report, don't delete blindly.

- [ ] **Step 2: A final full scan** so FLP's references match its real content again

```bash
dev/flpwp.sh unmam scan --reset --batch-size=200
```

- [ ] **Step 3: Stop FLP**

Same Python call as Task 0 Step 2 with `stopSite` in place of `startSite`.

- [ ] **Step 4: Remove the temp files**

```bash
rm -f /tmp/unmam133-*.txt /tmp/pc-13*.csv /tmp/pc-133-counts.txt
```

- [ ] **Step 5: Hand back to Sungraiz** for merge to `main`, push, and the WP.org deploy (`~/Local Sites/plugins/deploy.sh`, see `DEPLOY.md`). None of those happen without his yes.
