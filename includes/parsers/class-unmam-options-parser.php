<?php
/**
 * Options Parser for Media Usage Inspector
 *
 * Parses wp_options table for media references
 * Handles theme mods, customizer settings, ACF options pages, etc.
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Options Parser class
 */
class UNMAM_Options_Parser {

    /**
     * Known option names that contain media
     *
     * @var array
     */
    private $known_media_options = array(
        'site_icon',
        'site_logo',
        'custom_logo',
    );

    /**
     * Option patterns to scan
     *
     * @var array
     */
    private $skip_patterns = array(
        '_transient%',
        '_site_transient%',
        'cron',
        'rewrite_rules',
        'auto_core_update%',
        '_edit_lock',
        'wp_user_roles',          // Capability map. Large, never media.
        '_wp_session_%',          // Session payloads.
        'action_scheduler_%',     // Job queues, can be enormous.
        '%_transient_timeout_%',
    );

    /**
     * Get parser name
     *
     * @return string
     */
    public function get_name() {
        return 'options';
    }

    /**
     * Parse options table for media references
     *
     * @return array
     */
    public function parse_options() {
        global $wpdb;

        $references = array();

        // Get known media options first
        foreach ( $this->known_media_options as $option_name ) {
            $value = get_option( $option_name );
            if ( $value && is_numeric( $value ) ) {
                $references[] = array(
                    'attachment_id'   => (int) $value,
                    'source_id'       => 0,
                    'source_type'     => 'option',
                    'context_type'    => 'option',
                    'context_key'     => $option_name,
                    'context_label'   => $this->get_option_label( $option_name ),
                    'reference_type'  => 'id',
                );
            }
        }

        // Scan theme mods
        $theme_mod_refs = $this->parse_theme_mods();
        $references     = array_merge( $references, $theme_mod_refs );

        // Scan ACF options (if ACF is active)
        if ( function_exists( 'get_field' ) ) {
            $acf_refs   = $this->parse_acf_options();
            $references = array_merge( $references, $acf_refs );
        }

        // The generic sweep over wp_options is paged separately by the scanner via
        // parse_generic_options_batch(), so it is deliberately not run here.
        return $references;
    }

    /**
     * Scan one page of wp_options for media references.
     *
     * Replaces the old name-pattern allow-list, which only looked at options called
     * theme_mods_*, options_*, widget_*, *_options or *_settings and so missed anything
     * stored under another name. It also replaces a hard LIMIT 1000 that silently stopped
     * the sweep partway through on larger sites.
     *
     * @param int $after_id   Only read options with a higher option_id.
     * @param int $limit      Maximum rows to read.
     * @param int $size_limit Skip option_value larger than this many bytes.
     * @return array {
     *     @type array $references Reference rows found.
     *     @type int   $rows       Options actually read.
     *     @type int   $last_id    Highest option_id read, for the next cursor.
     *     @type int   $skipped    Options skipped for being oversized.
     * }
     */
    public function parse_generic_options_batch( $after_id, $limit, $size_limit = 1048576 ) {
        global $wpdb;

        $references = array();
        $skipped    = 0;
        $last_id    = (int) $after_id;

        $exclude_clauses = array();
        foreach ( $this->skip_patterns as $pattern ) {
            $exclude_clauses[] = $wpdb->prepare( 'option_name NOT LIKE %s', $pattern );
        }
        $where = $exclude_clauses ? implode( ' AND ', $exclude_clauses ) : '1=1';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Clauses are individually prepared above
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_id, option_name, option_value, LENGTH(option_value) AS value_bytes
                 FROM {$wpdb->options}
                 WHERE option_id > %d AND {$where}
                 ORDER BY option_id ASC
                 LIMIT %d",
                (int) $after_id,
                (int) $limit
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        foreach ( $rows as $row ) {
            $last_id = (int) $row->option_id;

            if ( (int) $row->value_bytes > $size_limit ) {
                $skipped++;
                continue;
            }

            $value = maybe_unserialize( $row->option_value );
            $refs  = $this->check_value_for_media( $row->option_name, $value, 'option' );
            if ( $refs ) {
                $references = array_merge( $references, $refs );
            }
        }

        return array(
            'references' => $references,
            'rows'       => count( $rows ),
            'last_id'    => $last_id,
            'skipped'    => $skipped,
        );
    }

    /**
     * Parse theme mods for media
     *
     * @return array
     */
    private function parse_theme_mods() {
        $references = array();
        $theme_slug = get_option( 'stylesheet' );
        $mods       = get_option( "theme_mods_{$theme_slug}" );

        if ( ! is_array( $mods ) ) {
            return $references;
        }

        foreach ( $mods as $key => $value ) {
            // Skip non-media related keys
            if ( in_array( $key, array( 'nav_menu_locations', 'sidebars_widgets', 'custom_css_post_id' ), true ) ) {
                continue;
            }

            // Check if value is attachment ID
            if ( is_numeric( $value ) && $this->is_valid_attachment( (int) $value ) ) {
                $references[] = array(
                    'attachment_id'   => (int) $value,
                    'source_id'       => 0,
                    'source_type'     => 'option',
                    'context_type'    => 'theme_mod',
                    'context_key'     => $key,
                    'context_label'   => sprintf(
                        /* translators: %s: theme mod key */
                        __( 'Theme Setting: %s', 'unattached-media-manager' ),
                        $this->format_key_label( $key )
                    ),
                    'reference_type'  => 'id',
                );
                continue;
            }

            // Check if value is URL
            if ( is_string( $value ) && $this->looks_like_media_url( $value ) ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $value );
                if ( $attachment_id ) {
                    $references[] = array(
                        'attachment_id'   => $attachment_id,
                        'source_id'       => 0,
                        'source_type'     => 'option',
                        'context_type'    => 'theme_mod',
                        'context_key'     => $key,
                        'context_label'   => sprintf(
                            /* translators: %s: theme mod key */
                            __( 'Theme Setting: %s', 'unattached-media-manager' ),
                            $this->format_key_label( $key )
                        ),
                        'reference_type'  => 'url',
                        'reference_value' => $value,
                    );
                }
                continue;
            }

            // Check complex values (arrays)
            if ( is_array( $value ) ) {
                $nested_refs = $this->parse_nested_option( "theme_mods.{$key}", $value );
                $references  = array_merge( $references, $nested_refs );
            }
        }

        return $references;
    }

    /**
     * Parse ACF options pages
     *
     * @return array
     */
    private function parse_acf_options() {
        $references = array();

        // Get all ACF options
        $options = get_option( 'options' );
        if ( ! is_array( $options ) ) {
            // Try to get individual option fields
            global $wpdb;
            $acf_options = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
                    'options_%',
                    'options\_\_%'
                )
            );

            foreach ( $acf_options as $opt ) {
                $value = maybe_unserialize( $opt->option_value );
                $refs  = $this->check_value_for_media( $opt->option_name, $value, 'acf_option' );
                $references = array_merge( $references, $refs );
            }
        }

        return $references;
    }

    /**
     * Check value for media references
     *
     * @param string $option_name  Option name.
     * @param mixed  $value        Option value.
     * @param string $context_type Context type.
     * @return array
     */
    private function check_value_for_media( $option_name, $value, $context_type ) {
        $references = array();

        // Direct ID
        if ( is_numeric( $value ) && $this->is_valid_attachment( (int) $value ) ) {
            $references[] = $this->create_option_reference( (int) $value, $option_name, $context_type, 'id' );
            return $references;
        }

        // Direct URL
        if ( is_string( $value ) && $this->looks_like_media_url( $value ) ) {
            $attachment_id = UNMAM_Database::url_to_attachment_id( $value );
            if ( $attachment_id ) {
                $references[] = $this->create_option_reference( $attachment_id, $option_name, $context_type, 'url', $value );
            }
            return $references;
        }

        // Array value
        if ( is_array( $value ) ) {
            $refs = $this->parse_nested_option( $option_name, $value, $context_type );
            $references = array_merge( $references, $refs );
        }

        return $references;
    }

    /**
     * Parse nested option value
     *
     * @param string $option_name  Option name.
     * @param array  $data         Data array.
     * @param string $context_type Context type.
     * @return array
     */
    private function parse_nested_option( $option_name, $data, $context_type = 'option' ) {
        $references = array();

        if ( ! is_array( $data ) ) {
            return $references;
        }

        $id_keys  = array( 'id', 'ID', 'image_id', 'attachment_id', 'media_id', 'logo_id', 'icon_id' );
        $url_keys = array( 'url', 'src', 'image', 'image_url', 'logo', 'icon', 'background' );

        foreach ( $data as $key => $value ) {
            // Check ID keys
            if ( in_array( $key, $id_keys, true ) && is_numeric( $value ) ) {
                if ( $this->is_valid_attachment( (int) $value ) ) {
                    $references[] = $this->create_option_reference( (int) $value, "{$option_name}[{$key}]", $context_type, 'id' );
                }
                continue;
            }

            // Check URL keys
            if ( in_array( $key, $url_keys, true ) && is_string( $value ) && $this->looks_like_media_url( $value ) ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $value );
                if ( $attachment_id ) {
                    $references[] = $this->create_option_reference( $attachment_id, "{$option_name}[{$key}]", $context_type, 'url', $value );
                }
                continue;
            }

            // Any string pointing into the uploads folder is a media reference whatever the
            // key is called. Plugins name these keys anything, so matching only a fixed list
            // ('url', 'src', 'image'...) missed most of them. Note this catch-all is for URLs
            // only: a bare number stays gated on the ID keys above, because treating every
            // number as an attachment ID is how unrelated values become false references.
            if ( is_string( $value ) && $this->looks_like_media_url( $value ) ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $value );
                if ( $attachment_id ) {
                    $references[] = $this->create_option_reference( $attachment_id, "{$option_name}[{$key}]", $context_type, 'url', $value );
                }
                continue;
            }

            // Recurse into nested arrays
            if ( is_array( $value ) ) {
                $nested = $this->parse_nested_option( "{$option_name}[{$key}]", $value, $context_type );
                $references = array_merge( $references, $nested );
            }
        }

        return $references;
    }

    /**
     * Create option reference array
     *
     * @param int    $attachment_id   Attachment ID.
     * @param string $option_key      Option key.
     * @param string $context_type    Context type.
     * @param string $reference_type  Reference type.
     * @param string $reference_value Reference value.
     * @return array
     */
    private function create_option_reference( $attachment_id, $option_key, $context_type, $reference_type, $reference_value = null ) {
        return array(
            'attachment_id'   => $attachment_id,
            'source_id'       => 0,
            'source_type'     => 'option',
            'context_type'    => $context_type,
            'context_key'     => $option_key,
            'context_label'   => sprintf(
                /* translators: %s: option key */
                __( 'Option: %s', 'unattached-media-manager' ),
                $this->format_key_label( $option_key )
            ),
            'reference_type'  => $reference_type,
            'reference_value' => $reference_value,
        );
    }

    /**
     * Get label for known options
     *
     * @param string $option_name Option name.
     * @return string
     */
    private function get_option_label( $option_name ) {
        $labels = array(
            'site_icon'   => __( 'Site Icon', 'unattached-media-manager' ),
            'site_logo'   => __( 'Site Logo', 'unattached-media-manager' ),
            'custom_logo' => __( 'Custom Logo', 'unattached-media-manager' ),
        );

        return isset( $labels[ $option_name ] ) ? $labels[ $option_name ] : $option_name;
    }

    /**
     * Format key for display
     *
     * @param string $key Key to format.
     * @return string
     */
    private function format_key_label( $key ) {
        $key = str_replace( array( '_', '-' ), ' ', $key );
        return ucwords( $key );
    }

    /**
     * Check if URL looks like media
     *
     * @param string $value Value to check.
     * @return bool
     */
    private function looks_like_media_url( $value ) {
        if ( ! is_string( $value ) ) {
            return false;
        }

        $media_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp4', 'webm', 'mp3', 'pdf' );
        $pattern          = '/\.(' . implode( '|', $media_extensions ) . ')(\?.*)?$/i';

        if ( preg_match( $pattern, $value ) ) {
            return true;
        }

        if ( strpos( $value, '/wp-content/uploads/' ) !== false ) {
            return true;
        }

        return false;
    }

    /**
     * Check if ID is valid attachment
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    private function is_valid_attachment( $attachment_id ) {
        return $attachment_id > 0 && get_post_type( $attachment_id ) === 'attachment';
    }
}
