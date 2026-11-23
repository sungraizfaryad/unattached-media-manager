<?php
/**
 * File Scanner for Unattached Media Manager
 *
 * Reads theme and plugin files for image references. A file is read in
 * overlapping pieces of at most 1 MB, which is all the extractor will take
 * in one go, so a URL cut by one piece's end is whole in the next.
 *
 * scan_batch() is the 'files' scan step. It walks every theme, plugin and
 * must-use plugin a slice at a time, keeps its place in one option, and writes
 * a reference row for each image a file mentions.
 *
 * @package UnattachedMediaManager
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * File Scanner class.
 */
class UNMAM_File_Scanner {

    const PIECE_BYTES   = UNMAM_Reference_Extractor::MAX_TEXT_LENGTH;
    const OVERLAP_BYTES = 4096;

    const CONTEXTS = array(
        'theme'  => 'theme_file',
        'plugin' => 'plugin_file',
        'mu'     => 'mu_plugin_file',
    );

    const STATE_OPTION = 'unmam_file_scan_state';
    const SKIPPED_KEPT = 100;
    const MAX_ATTEMPTS = 3;
    const MAX_COMPONENT_ATTEMPTS = 5;

    /**
     * Byte ranges to read for a file of this size.
     *
     * Each piece starts OVERLAP_BYTES before the previous one ended and is never longer
     * than PIECE_BYTES, overlap included. The last one ends at $size.
     *
     * @param int $size File size in bytes.
     * @return array List of array( offset, length ). Empty for an empty file.
     */
    public static function piece_ranges( int $size ): array {
        $ranges = array();
        $offset = 0;

        while ( $offset < $size ) {
            $length   = min( $size - $offset, self::PIECE_BYTES );
            $ranges[] = array( $offset, $length );

            if ( $offset + $length >= $size ) {
                break;
            }
            $offset += $length - self::OVERLAP_BYTES;
        }

        return $ranges;
    }

    /**
     * Everything in one file that may point at an attachment, across all its pieces.
     *
     * The list is de-duplicated on type plus value and keeps the order in which candidates
     * were first seen, so it comes out the same on every read; the scan cursor counts into it.
     *
     * $incomplete is set to false on entry and to true when part of the file could not be
     * searched: the file or a piece could not be read, or a pattern match gave up. Whatever
     * was found is still returned, so a true flag means "there may be more", not "nothing".
     *
     * @param string    $abs_path   Absolute path to the file.
     * @param bool|null $incomplete See above.
     * @return array List of array( 'type', 'value' ), as find_candidates() returns.
     */
    public static function file_candidates( string $abs_path, &$incomplete = null ): array {
        $incomplete = false;

        clearstatcache( true, $abs_path );
        if ( ! is_file( $abs_path ) || ! is_readable( $abs_path ) ) {
            $incomplete = true;
            return array();
        }

        $size = @filesize( $abs_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file can vanish after is_readable(); false is handled.
        if ( false === $size ) {
            $incomplete = true;
            return array();
        }

        $options = array(
            'relative' => true,
            'tails'    => true,
            'calls'    => 'php' === strtolower( pathinfo( $abs_path, PATHINFO_EXTENSION ) ),
        );

        $found = array();
        $seen  = array();

        foreach ( self::piece_ranges( $size ) as $range ) {
            $piece = @file_get_contents( $abs_path, false, null, $range[0], $range[1] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file can shrink or vanish since filesize(); false is handled.
            if ( false === $piece ) {
                $incomplete = true;
                continue;
            }
            if ( strlen( $piece ) !== $range[1] ) {
                $incomplete = true; // The file shrank since filesize().
            }

            // JSON escapes slashes, and JSON stored inside JSON escapes the escape.
            $plain = preg_replace( '#\\\\+/#', '/', $piece );
            if ( null === $plain ) {
                $incomplete = true;
                $plain      = $piece;
            }

            foreach ( UNMAM_Reference_Extractor::find_candidates( $plain, $options, $incomplete ) as $candidate ) {
                $key = $candidate['type'] . '|' . $candidate['value'];
                if ( isset( $seen[ $key ] ) ) {
                    continue;
                }
                $seen[ $key ] = true;
                $found[]      = $candidate;
            }
        }

        return $found;
    }

    /**
     * Every theme, plugin and must-use plugin to scan, in byte order of their keys.
     *
     * The pass stores this list, so a plugin installed half way through cannot shift positions.
     * Plugins come from listing the plugins folder rather than get_plugins(), which skips a
     * folder whose main file has no valid header. This plugin's own folder is left out: its
     * readme and parsers mention sample upload URLs.
     *
     * @return array List of array( 'key' => string, 'type' => 'theme'|'plugin'|'mu', 'root' => string ).
     */
    public static function components(): array {
        $list = array();

        foreach ( wp_get_themes( array( 'errors' => null ) ) as $stylesheet => $theme ) {
            $root = $theme->get_stylesheet_directory();
            if ( '' !== $root ) {
                $list[] = array(
                    'key'  => 'theme:' . (string) $stylesheet,
                    'type' => 'theme',
                    'root' => $root,
                );
            }
        }

        $own = dirname( UNMAM_PLUGIN_BASENAME );
        if ( '.' === $own ) {
            $own = UNMAM_PLUGIN_BASENAME;
        }

        $names = @scandir( WP_PLUGIN_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable folder just means no plugin components.
        foreach ( is_array( $names ) ? $names : array() as $name ) {
            $name = (string) $name;
            if ( 0 === strpos( $name, '.' ) || $name === $own ) {
                continue;
            }

            $path = WP_PLUGIN_DIR . '/' . $name;
            if ( is_dir( $path ) || ( is_file( $path ) && '.php' === strtolower( substr( $name, -4 ) ) ) ) {
                $list[] = array(
                    'key'  => 'plugin:' . $name,
                    'type' => 'plugin',
                    'root' => $path,
                );
            }
        }

        if ( is_dir( WPMU_PLUGIN_DIR ) ) {
            $list[] = array(
                'key'  => 'mu',
                'type' => 'mu',
                'root' => WPMU_PLUGIN_DIR,
            );
        }

        usort(
            $list,
            function ( $a, $b ) {
                return strcmp( $a['key'], $b['key'] );
            }
        );

        return $list;
    }

    /**
     * Delete every file reference, the scan state and the time of the last completed pass.
     */
    public static function clear(): void {
        foreach ( self::CONTEXTS as $context ) {
            UNMAM_Database::delete_references_by_source_and_context( 'file', $context );
        }
        delete_option( self::STATE_OPTION );
        delete_option( 'unmam_files_scanned_at' );
    }

    /**
     * Files the last pass could not read, for the notice and the CLI.
     *
     * @return array array( 'total' => int, 'items' => array of array( 'key', 'path', 'reason' ) ).
     *               The reason is too_big, unreadable, too_deep or stopped. Only the first
     *               SKIPPED_KEPT are kept; the total counts them all.
     */
    public static function skipped(): array {
        $state = self::load_state();
        if ( null === $state ) {
            return array(
                'total' => 0,
                'items' => array(),
            );
        }

        $items = array();
        foreach ( $state['skipped'] as $item ) {
            $items[] = array(
                'key'    => self::scrub( $item['key'] ),
                'path'   => self::scrub( $item['path'] ),
                'reason' => $item['reason'],
            );
        }

        return array(
            'total' => (int) $state['skipped_total'],
            'items' => $items,
        );
    }

    /**
     * Whether the current user may see which theme and plugin files use an image.
     *
     * The full list is an inventory of installed and inactive plugins. WP-CLI counts as
     * trusted only when it runs as no user, which is the shell default.
     */
    public static function can_see_details(): bool {
        return current_user_can( 'activate_plugins' )
            || ( defined( 'WP_CLI' ) && WP_CLI && 0 === get_current_user_id() );
    }

    /**
     * A usage row with the parts that name a theme or plugin file taken out, unless the user
     * may see them. Other rows come back as they were.
     */
    public static function without_details( array $ref ): array {
        if ( isset( $ref['source_type'] ) && 'file' === $ref['source_type'] && ! self::can_see_details() ) {
            unset( $ref['context_key'], $ref['reference_value'] );
        }

        return $ref;
    }

    /**
     * Installed plugins keyed by folder, or by file name for a single-file plugin.
     *
     * Read once per request. plugin.php is not loaded on every kind of request, so it is pulled in here.
     *
     * @return array Key => the get_plugins() header data of its first plugin file, plus 'files',
     *               the plugin basenames found under that key.
     */
    public static function installed_plugins(): array {
        static $plugins = null;

        if ( null === $plugins ) {
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $plugins = array();
            foreach ( get_plugins() as $file => $data ) {
                $key = false === strpos( $file, '/' ) ? $file : strstr( $file, '/', true );
                if ( ! isset( $plugins[ $key ] ) ) {
                    $plugins[ $key ]          = $data;
                    $plugins[ $key ]['files'] = array();
                }
                $plugins[ $key ]['files'][] = $file;
            }
        }

        return $plugins;
    }

    /**
     * What to show for a file row in Where Used: which component and file, as plain text.
     *
     * There is never an edit or view link. Many hosts turn the file editor off, and editing
     * live code from a link can take a site down. Users who cannot activate plugins get a
     * generic title with no path.
     *
     * @param string $context_key Value from a file row, component and path joined by a pipe.
     * @return array array( 'title' => string, 'edit_link' => null, 'view_link' => null ).
     */
    public static function describe( string $context_key ): array {
        $described = array(
            'title'     => __( 'Theme or plugin file', 'unattached-media-manager' ),
            'edit_link' => null,
            'view_link' => null,
        );

        if ( ! self::can_see_details() ) {
            return $described;
        }

        $parts     = explode( '|', $context_key, 2 );
        $component = self::component_label( $parts[0] );
        if ( '' === $component ) {
            return $described;
        }

        // A key that would not fit kept only a hash of the path, so there is no path to show.
        $path = '';
        if ( isset( $parts[1] ) && ! preg_match( '/^#[0-9a-f]{32}$/', $parts[1] ) ) {
            $path = $parts[1];
        }

        $described['title'] = '' === $path
            ? $component
            : sprintf(
                /* translators: 1: theme or plugin name, 2: path of a file inside it */
                __( '%1$s: %2$s', 'unattached-media-manager' ),
                $component,
                $path
            );

        return $described;
    }

    /**
     * Name of a component as the admin knows it, with its status: "Twenty Twenty (inactive)".
     *
     * @param string $component Component key, for example theme:twentytwenty or plugin:akismet.
     * @return string Empty when the key is not one this plugin writes.
     */
    private static function component_label( string $component ): string {
        if ( 'mu' === $component ) {
            return __( 'Must-use plugins', 'unattached-media-manager' );
        }

        if ( 0 === strpos( $component, 'theme:' ) && strlen( $component ) > 6 ) {
            $slug  = substr( $component, 6 );
            $theme = wp_get_theme( $slug );

            if ( ! $theme->exists() ) {
                /* translators: %s: theme folder name */
                return sprintf( __( '%s (not installed)', 'unattached-media-manager' ), $slug );
            }

            $name = (string) $theme->get( 'Name' );
            $name = '' === $name ? $slug : $name;

            if ( get_stylesheet() === $slug || get_template() === $slug ) {
                return $name;
            }

            /* translators: %s: theme name */
            return sprintf( __( '%s (inactive)', 'unattached-media-manager' ), $name );
        }

        if ( 0 === strpos( $component, 'plugin:' ) && strlen( $component ) > 7 ) {
            $key       = substr( $component, 7 );
            $installed = self::installed_plugins();
            $known     = isset( $installed[ $key ] );
            $name      = $known && '' !== (string) $installed[ $key ]['Name'] ? (string) $installed[ $key ]['Name'] : $key;

            // The list is read once per request, so a plugin deleted since then is still in it.
            if ( ! $known || ! file_exists( WP_PLUGIN_DIR . '/' . $key ) ) {
                /* translators: %s: plugin name or folder name */
                return sprintf( __( '%s (not installed)', 'unattached-media-manager' ), $name );
            }

            foreach ( $installed[ $key ]['files'] as $file ) {
                if ( is_plugin_active( $file ) || is_plugin_active_for_network( $file ) ) {
                    return $name;
                }
            }

            /* translators: %s: plugin name */
            return sprintf( __( '%s (inactive)', 'unattached-media-manager' ), $name );
        }

        return '';
    }

    /**
     * Note that a theme or plugin changed, so the notice asks for a new scan.
     */
    public static function mark_changed(): void {
        update_option( 'unmam_files_changed_at', time(), false );
    }

    /**
     * What the admin notices and `wp unmam status` report about the file scan.
     *
     * Everything is false or empty while the scan is switched off.
     *
     * @return array array( 'never_scanned' => bool, 'changed' => bool, 'skipped' => skipped() ).
     */
    public static function notice_state(): array {
        $state = array(
            'never_scanned' => false,
            'changed'       => false,
            'skipped'       => array(
                'total' => 0,
                'items' => array(),
            ),
        );

        if ( empty( Unattached_Media_Manager::get_setting( 'scan_files' ) ) ) {
            return $state;
        }

        $scanned = (int) get_option( 'unmam_files_scanned_at', 0 );
        $changed = (int) get_option( 'unmam_files_changed_at', 0 );

        // A change in the same second a pass began counts: that pass may have read the file first.
        $state['never_scanned'] = 0 === $scanned;
        $state['changed']       = $changed > 0 && $changed >= $scanned;
        $state['skipped']       = self::skipped();

        return $state;
    }

    /**
     * The sentence that says how many files the scan could not read.
     */
    public static function skipped_summary( int $total ): string {
        return sprintf(
            /* translators: %d: number of theme and plugin files the scan could not read */
            _n(
                '%d theme or plugin file could not be read, so images used only in it may be listed as unused.',
                '%d theme or plugin files could not be read, so images used only in them may be listed as unused.',
                $total,
                'unattached-media-manager'
            ),
            $total
        );
    }

    /**
     * One item of skipped() as a line of text, for the notice and the CLI.
     *
     * The title comes from describe(), so a user who may not see paths gets the generic one.
     *
     * @param array $item Item with key, path and reason.
     * @return string For example "Some Theme: css/a.css: over 10 MB".
     */
    public static function skipped_line( array $item ): string {
        switch ( $item['reason'] ) {
            case 'too_big':
                $reason = __( 'over 10 MB', 'unattached-media-manager' );
                break;
            case 'too_deep':
                $reason = __( 'folder too deep', 'unattached-media-manager' );
                break;
            case 'stopped':
                $reason = __( 'stopped the scan', 'unattached-media-manager' );
                break;
            default:
                $reason = __( 'unreadable', 'unattached-media-manager' );
        }

        return sprintf(
            /* translators: 1: theme or plugin file, 2: why the scan could not read it */
            __( '%1$s: %2$s', 'unattached-media-manager' ),
            self::describe( $item['key'] . '|' . $item['path'] )['title'],
            $reason
        );
    }

    /**
     * The context_key of a file row: component key and relative path joined by a pipe.
     *
     * Both parts come from disk, and wpdb refuses a row whose fields hold invalid UTF-8 or are
     * too long, so the bad bytes are replaced and a key over 255 characters keeps the component
     * and the end of the path.
     *
     * @param string $component_key   For example theme:genesis-block-theme.
     * @param string $relative_path   Path inside the component.
     * @return string
     */
    public static function context_key( string $component_key, string $relative_path ): string {
        $component_key = self::scrub( $component_key );
        $relative_path = self::scrub( $relative_path );

        $key = $component_key . '|' . $relative_path;
        if ( mb_strlen( $key, 'UTF-8' ) <= 255 ) {
            return $key;
        }

        $head = $component_key . "|\u{2026}";
        $room = 255 - mb_strlen( $head, 'UTF-8' );
        if ( $room < 1 ) {
            return mb_substr( $head, 0, 255, 'UTF-8' );
        }

        return $head . mb_substr( $relative_path, -$room, null, 'UTF-8' );
    }

    /**
     * Replace the invalid UTF-8 bytes in a string that came off the disk.
     *
     * wp_check_invalid_utf8( $s, true ) cannot be used on its own: before WP 6.9 its strip branch
     * is a bare iconv(), which returns false (and a notice) on invalid input, so a bad file
     * name came out empty.
     */
    private static function scrub( string $text ): string {
        if ( function_exists( 'wp_scrub_utf8' ) ) {
            return wp_scrub_utf8( $text );
        }
        if ( function_exists( 'mb_scrub' ) ) {
            return mb_scrub( $text, 'UTF-8' );
        }

        $clean = wp_check_invalid_utf8( $text, true );
        if ( ! is_string( $clean ) ) {
            // No mbstring and an old core: keep the ASCII and mark the rest.
            $clean = (string) preg_replace( '/[^\x20-\x7E]/', '?', $text );
        }
        return $clean;
    }

    /**
     * Write the row for one image found in one file.
     *
     * The only place a file row is written. If the insert is refused anyway, it is tried once
     * more under a key built from a hash of the path, so the image is credited either way.
     *
     * @param array  $component     Item from components().
     * @param string $relative_path Path of the file inside the component.
     * @param int    $attachment_id Attachment the file mentions.
     * @param array  $candidate     Item from find_candidates() that resolved to it.
     * @return int|false Row id, or false when both attempts failed.
     */
    public static function write_reference( array $component, string $relative_path, int $attachment_id, array $candidate ) {
        $context = self::CONTEXTS[ $component['type'] ];
        $row     = array(
            'attachment_id'   => $attachment_id,
            'source_id'       => 0,
            'source_type'     => 'file',
            'context_type'    => $context,
            'context_key'     => self::context_key( $component['key'], $relative_path ),
            'context_label'   => self::context_label( $context ),
            'reference_type'  => 'id' === $candidate['type'] ? 'id' : 'url',
            // A matched URL cannot be this long in practice; the cap keeps one odd value from sinking the row.
            'reference_value' => self::scrub( substr( (string) $candidate['value'], 0, 4096 ) ),
        );

        $id = UNMAM_Database::insert_reference( $row );
        if ( false === $id ) {
            $stem               = mb_substr( self::scrub( $component['key'] ), 0, 200, 'UTF-8' );
            $row['context_key'] = $stem . '|#' . md5( $relative_path );
            $id                 = UNMAM_Database::insert_reference( $row );
        }

        return $id;
    }

    /**
     * Run one batch of the files step.
     *
     * A pass starts in UNMAM_Scanner::full_scan(), which clears the progress row and the state.
     * This builds the component list when it finds neither, and otherwise carries on from the
     * saved cursor until a limit is reached (see limits()).
     *
     * @return array array( 'processed' => files read this batch, 'status' => 'running'|'completed', 'message' => string ).
     */
    public static function scan_batch(): array {
        if ( empty( Unattached_Media_Manager::get_setting( 'scan_files' ) ) ) {
            self::clear();
            UNMAM_Database::update_scan_progress( 'files', array(
                'status'       => 'completed',
                'completed_at' => current_time( 'mysql' ),
            ) );
            return self::result( 0, 'completed', __( 'Theme and plugin files are not being scanned.', 'unattached-media-manager' ) );
        }

        // Deliberately does not rewind a finished step. The browser batch endpoint takes no lock
        // while cron keeps running, so a late duplicate can arrive after the last batch wrote
        // "completed"; rewinding then would wipe every file row and read one batch.
        $progress = UNMAM_Database::get_scan_progress( 'files' );
        if ( $progress && 'completed' === $progress['status'] ) {
            return self::result( 0, 'completed', __( 'Theme and plugin file scan already completed.', 'unattached-media-manager' ) );
        }

        $state = self::load_state();
        if ( ! $progress || null === $state ) {
            $state = self::start_pass();
        }

        // A batch that starts exactly where the last one did has not moved the cursor, which
        // means that one died on something the time checks could not catch.
        $position            = $state['index'] . '|' . $state['path'] . '|' . $state['candidate'];
        $state['attempts']   = $position === $state['attempt_at'] ? (int) $state['attempts'] + 1 : 1;
        $state['attempt_at'] = $position;
        self::save_state( $state );

        $budget = array(
            'limits'  => self::limits(),
            'started' => microtime( true ),
            'units'   => 0,
            'files'   => 0,
            'bytes'   => 0,
            'entries' => 0,
        );

        if ( $state['attempts'] >= self::MAX_COMPONENT_ATTEMPTS ) {
            self::skip_stuck_component( $state );
        } elseif ( $state['attempts'] >= self::MAX_ATTEMPTS ) {
            self::skip_stuck_file( $state, $budget['limits'] );
        }

        self::scan_components( $state, $budget );

        $state['attempts']   = 0;
        $state['attempt_at'] = '';
        self::save_state( $state );

        $total = count( $state['components'] );
        if ( $state['index'] < $total ) {
            UNMAM_Database::update_scan_progress( 'files', array(
                'processed_items' => $state['index'],
                'status'          => 'running',
            ) );
            return self::result(
                $budget['files'],
                'running',
                sprintf(
                    /* translators: %d: number of theme and plugin files read */
                    __( 'Scanned %d theme and plugin files.', 'unattached-media-manager' ),
                    $budget['files']
                )
            );
        }

        UNMAM_Database::update_scan_progress( 'files', array(
            'processed_items' => $total,
            'status'          => 'completed',
            'completed_at'    => current_time( 'mysql' ),
        ) );
        // Dated from the start of the pass, so a theme or plugin that changed while it ran still
        // reads as changed.
        $started = (int) $state['pass_started'];
        update_option( 'unmam_files_scanned_at', $started > 0 ? $started : time(), false );

        return self::result(
            $budget['files'],
            'completed',
            sprintf(
                /* translators: 1: number of theme and plugin files read, 2: number of image references found */
                __( 'Scanned %1$d theme and plugin files and found %2$d image references.', 'unattached-media-manager' ),
                $state['files'],
                $state['refs']
            )
        );
    }

    /**
     * Clear the last pass and build the state for a new one.
     *
     * @return array The new state.
     */
    private static function start_pass(): array {
        foreach ( self::CONTEXTS as $context ) {
            UNMAM_Database::delete_references_by_source_and_context( 'file', $context );
        }

        $components = self::components();
        $state      = self::blank_state();

        $state['components']   = $components;
        $state['pass_started'] = time();
        self::save_state( $state );

        UNMAM_Database::update_scan_progress( 'files', array(
            'total_items'       => count( $components ),
            'processed_items'   => 0,
            'last_processed_id' => 0,
            'status'            => 'running',
            'started_at'        => current_time( 'mysql' ),
        ) );

        return $state;
    }

    private static function blank_state(): array {
        return array(
            'components'    => array(),
            'pass_started'  => 0,  // Unix time the pass began; 0 on a state saved before this existed.
            'index'         => 0,  // Current component.
            'path'          => '', // File in progress when candidate > 0, else the last finished file.
            'candidate'     => 0,  // Next candidate in that file.
            'size'          => 0,  // Size and mtime of 'path' while candidate > 0.
            'mtime'         => 0,
            'attempt_at'    => '',
            'attempts'      => 0,
            'files'         => 0,
            'refs'          => 0,
            'skipped'       => array(),
            'skipped_total' => 0,
        );
    }

    /**
     * Limits for one batch. The tests force tiny ones through the filter.
     */
    private static function limits(): array {
        $defaults = array(
            'files'   => 200,
            'bytes'   => 8388608,
            'entries' => 5000,
            'seconds' => 20,
        );

        /**
         * Filter the limits of one files batch. A batch stops at whichever it reaches first.
         *
         * @param array $defaults files, bytes (read), entries (folder entries visited), seconds.
         */
        $limits = apply_filters( 'unmam_file_scan_batch_limits', $defaults );
        $limits = is_array( $limits ) ? array_merge( $defaults, $limits ) : $defaults;

        return array(
            'files'   => max( 1, (int) $limits['files'] ),
            'bytes'   => max( 1, (int) $limits['bytes'] ),
            'entries' => max( 1, (int) $limits['entries'] ),
            'seconds' => max( 0, (float) $limits['seconds'] ),
        );
    }

    /**
     * Whether this batch has done what it should. Always false until one unit of work is done,
     * so a host that is already short of time or memory still moves forward a step a batch.
     * A unit is one file read or one candidate looked up. Folder entries are not checked here:
     * the walk is already paid for by the time its files come back.
     */
    private static function over_budget( array $budget ): bool {
        if ( $budget['units'] < 1 ) {
            return false;
        }

        $limits = $budget['limits'];

        return $budget['files'] >= $limits['files']
            || $budget['bytes'] >= $limits['bytes']
            || ( microtime( true ) - $budget['started'] ) >= $limits['seconds']
            || UNMAM_Resource_Monitor::instance()->should_pause();
    }

    /**
     * The third time a batch starts on the same spot, give up on the file it would start with.
     *
     * That is the file the cursor is inside, or else the next one the walk returns. It goes in
     * the skipped list as "stopped" and the cursor moves past it.
     */
    private static function skip_stuck_file( array &$state, array $limits ): void {
        $component = isset( $state['components'][ $state['index'] ] ) ? $state['components'][ $state['index'] ] : null;

        if ( $component ) {
            $victim = '';
            if ( $state['candidate'] > 0 ) {
                $victim = $state['path'];
            } else {
                $walk   = UNMAM_File_Walker::walk( $component['root'], $state['path'], 1, $limits['entries'] );
                $victim = $walk['files'] ? $walk['files'][0] : '';

                // The cursor is about to move past these, so report them now.
                foreach ( $walk['skipped'] as $item ) {
                    self::note_skipped( $state, $component['key'], $item['path'], $item['reason'] );
                }
            }

            if ( '' !== $victim ) {
                self::note_skipped( $state, $component['key'], $victim, 'stopped' );
                self::file_done( $state, $victim );
            }
        }

        $state['attempts']   = 0;
        $state['attempt_at'] = '';
        self::save_state( $state );
    }

    /**
     * The fifth time a batch starts on the same spot, even skipping a file did not help, which
     * means the walk itself is what dies (a folder too big to list, say). Give up on the rest
     * of this component and move to the next.
     */
    private static function skip_stuck_component( array &$state ): void {
        if ( isset( $state['components'][ $state['index'] ] ) ) {
            self::note_skipped( $state, $state['components'][ $state['index'] ]['key'], '', 'stopped' );
            self::next_component( $state );
        }

        $state['attempts']   = 0;
        $state['attempt_at'] = '';
        self::save_state( $state );
    }

    /**
     * Read files from the cursor on until the budget runs out or the last component is done.
     */
    private static function scan_components( array &$state, array &$budget ): void {
        $count  = count( $state['components'] );
        $limits = $budget['limits'];

        while ( $state['index'] < $count && ! self::over_budget( $budget ) ) {
            $component = $state['components'][ $state['index'] ];

            // Removed since the pass began.
            if ( ! file_exists( $component['root'] ) ) {
                self::next_component( $state );
                continue;
            }

            // The file the last batch stopped inside.
            if ( $state['candidate'] > 0 && ! self::scan_file( $component, $state['path'], $state, $budget, (int) $state['candidate'] ) ) {
                return;
            }

            if ( self::over_budget( $budget ) || $budget['entries'] >= $limits['entries'] ) {
                return;
            }

            $walk = UNMAM_File_Walker::walk(
                $component['root'],
                $state['path'],
                max( 1, $limits['files'] - $budget['files'] ),
                max( 1, $limits['entries'] - $budget['entries'] )
            );

            $budget['entries'] += $walk['entries'];
            foreach ( $walk['skipped'] as $item ) {
                self::note_skipped( $state, $component['key'], $item['path'], $item['reason'] );
            }

            foreach ( $walk['files'] as $relative ) {
                if ( self::over_budget( $budget ) || ! self::scan_file( $component, $relative, $state, $budget, 0 ) ) {
                    return;
                }
            }

            if ( $walk['done'] ) {
                self::next_component( $state );
            } else {
                $state['path'] = $walk['last'];
            }
        }
    }

    /**
     * Read one file and write a row for each image it mentions, from candidate $from on.
     *
     * @return bool True when the file is finished, false when the budget ran out inside it;
     *              the cursor then holds the file and the next candidate.
     */
    private static function scan_file( array $component, string $relative, array &$state, array &$budget, int $from ): bool {
        $absolute = self::absolute_path( $component, $relative );
        clearstatcache( true, $absolute );

        // Gone since the walk listed it.
        if ( ! is_file( $absolute ) ) {
            self::file_done( $state, $relative );
            return true;
        }

        $size  = (int) filesize( $absolute );
        $mtime = (int) filemtime( $absolute );

        // Grew past the limit since the walk listed it.
        if ( $size > UNMAM_File_Walker::MAX_FILE_BYTES ) {
            self::note_skipped( $state, $component['key'], $relative, 'too_big' );
            self::file_done( $state, $relative );
            return true;
        }

        // Edited since the last batch stopped inside it, so the saved index no longer fits.
        // Reading it again only repeats rows, which insert_reference() merges.
        if ( $from > 0 && ( $size !== (int) $state['size'] || $mtime !== (int) $state['mtime'] ) ) {
            $from = 0;
        }

        $incomplete = false;
        $candidates = self::file_candidates( $absolute, $incomplete );

        $budget['bytes'] += $size;
        $budget['units']++;

        // What was found still counts; the file is reported because there may be more.
        if ( $incomplete ) {
            self::note_skipped( $state, $component['key'], $relative, 'unreadable' );
        }

        $total = count( $candidates );
        for ( $i = $from; $i < $total; $i++ ) {
            // The first lookup after a read always runs, so every batch makes some progress.
            if ( $i > $from && self::over_budget( $budget ) ) {
                $state['path']      = $relative;
                $state['candidate'] = $i;
                $state['size']      = $size;
                $state['mtime']     = $mtime;
                return false;
            }

            $attachment_id = UNMAM_Reference_Extractor::resolve_candidate( $candidates[ $i ] );
            $budget['units']++;

            if ( $attachment_id ) {
                if ( false === self::write_reference( $component, $relative, $attachment_id, $candidates[ $i ] ) ) {
                    // Not credited, so say so; otherwise the image would read as unused with no hint why.
                    self::note_skipped( $state, $component['key'], $relative, 'unreadable' );
                } else {
                    $state['refs']++;
                }
            }

            if ( $i + 1 < $total && 0 === ( $i + 1 - $from ) % 50 ) {
                $state['path']      = $relative;
                $state['candidate'] = $i + 1;
                $state['size']      = $size;
                $state['mtime']     = $mtime;
                self::save_state( $state );
            }
        }

        $state['files']++;
        $budget['files']++;
        self::file_done( $state, $relative );

        return true;
    }

    private static function file_done( array &$state, string $relative ): void {
        $state['path']      = $relative;
        $state['candidate'] = 0;
        $state['size']      = 0;
        $state['mtime']     = 0;
        self::save_state( $state );
    }

    private static function next_component( array &$state ): void {
        $state['index']++;
        $state['path']      = '';
        $state['candidate'] = 0;
        $state['size']      = 0;
        $state['mtime']     = 0;

        // Saved now: a batch that dies on this component's first file has to count its attempts
        // against this position, not the last file of the one before.
        self::save_state( $state );
    }

    /**
     * Where a file of this component is on disk. A component can be a single file.
     */
    private static function absolute_path( array $component, string $relative ): string {
        if ( is_file( $component['root'] ) ) {
            return $component['root'];
        }

        return untrailingslashit( $component['root'] ) . '/' . $relative;
    }

    /**
     * Add a file to the skipped list, once. A batch that stops part way through a walk's
     * result sees the same entries again next time.
     */
    private static function note_skipped( array &$state, string $key, string $path, string $reason ): void {
        foreach ( $state['skipped'] as $item ) {
            if ( $item['key'] === $key && $item['path'] === $path ) {
                return;
            }
        }

        $state['skipped_total']++;
        if ( count( $state['skipped'] ) < self::SKIPPED_KEPT ) {
            $state['skipped'][] = array(
                'key'    => $key,
                'path'   => $path,
                'reason' => $reason,
            );
        }
    }

    private static function context_label( string $context ): string {
        switch ( $context ) {
            case 'theme_file':
                return __( 'Theme file', 'unattached-media-manager' );
            case 'plugin_file':
                return __( 'Plugin file', 'unattached-media-manager' );
            default:
                return __( 'Must-use plugin file', 'unattached-media-manager' );
        }
    }

    private static function result( int $processed, string $status, string $message ): array {
        return array(
            'processed' => $processed,
            'status'    => $status,
            'message'   => $message,
        );
    }

    /**
     * The saved state, or null when there is none to continue from.
     */
    private static function load_state(): ?array {
        $state = get_option( self::STATE_OPTION );
        if ( ! is_array( $state ) || ! isset( $state['components'], $state['index'] ) ) {
            return null;
        }

        array_walk_recursive(
            $state,
            function ( &$value ) {
                if ( is_string( $value ) && "\0" === substr( $value, 0, 1 ) ) {
                    $value = (string) hex2bin( substr( $value, 1 ) );
                }
            }
        );

        return array_merge( self::blank_state(), $state );
    }

    /**
     * Save the state. A file name that is not valid UTF-8 would make update_option() refuse
     * the whole thing and the cursor would never move, so those strings are stored as hex
     * behind a NUL byte, which no file name can start with.
     */
    private static function save_state( array $state ): void {
        array_walk_recursive(
            $state,
            function ( &$value ) {
                if ( is_string( $value ) && wp_check_invalid_utf8( $value ) !== $value ) {
                    $value = "\0" . bin2hex( $value );
                }
            }
        );

        update_option( self::STATE_OPTION, $state, false );
    }
}
