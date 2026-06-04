<?php
/**
 * Scanner engine for Media Usage Inspector
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Scanner class - handles indexing media references
 */
class UNMAM_Scanner {

    /**
     * Single instance
     *
     * @var UNMAM_Scanner|null
     */
    private static $instance = null;

    /**
     * Parsers array
     *
     * @var array
     */
    private $parsers = array();

    /**
     * Whether parsers have been initialized
     *
     * @var bool
     */
    private $parsers_initialized = false;

    /**
     * Get singleton instance
     *
     * @return UNMAM_Scanner
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Parsers are initialized lazily on first use
    }

    /**
     * Ensure parsers are initialized
     */
    private function ensure_parsers_initialized() {
        if ( $this->parsers_initialized ) {
            return;
        }
        $this->init_parsers();
    }

    /**
     * Initialize parsers
     */
    private function init_parsers() {
        $this->parsers_initialized = true;
        $settings = Unattached_Media_Manager::get_setting();

        // Content parser (handles post_content including classic editor)
        if ( ! empty( $settings['scan_post_content'] ) ) {
            $this->parsers['content'] = new UNMAM_Content_Parser();
        }

        // Gutenberg block parser
        if ( ! empty( $settings['scan_gutenberg'] ) ) {
            $this->parsers['blocks'] = new UNMAM_Block_Parser();
        }

        // ACF parser
        if ( ! empty( $settings['scan_acf_fields'] ) ) {
            $this->parsers['acf'] = new UNMAM_ACF_Parser();
        }

        // Meta parser (generic postmeta)
        $this->parsers['meta'] = new UNMAM_Meta_Parser();

        // Options parser
        if ( ! empty( $settings['scan_options'] ) ) {
            $this->parsers['options'] = new UNMAM_Options_Parser();
        }

        // Widget parser
        if ( ! empty( $settings['scan_widgets'] ) ) {
            $this->parsers['widgets'] = new UNMAM_Widget_Parser();
        }

        // Elementor parser (FREE - unlike other plugins!)
        $elementor_parser = new UNMAM_Elementor_Parser();
        if ( $elementor_parser->is_active() ) {
            $this->parsers['elementor'] = $elementor_parser;
        }

        // MetaBox parser (FREE - unlike other plugins!)
        $metabox_parser = new UNMAM_MetaBox_Parser();
        if ( $metabox_parser->is_active() ) {
            $this->parsers['metabox'] = $metabox_parser;
        }

        // WooCommerce parser
        $woo_parser = new UNMAM_WooCommerce_Parser();
        if ( $woo_parser->is_active() ) {
            $this->parsers['woocommerce'] = $woo_parser;
        }

        // SEO plugins parser (Yoast, Rank Math, AIOSEO, SEOPress)
        $seo_parser = new UNMAM_SEO_Parser();
        if ( $seo_parser->is_active() ) {
            $this->parsers['seo'] = $seo_parser;
        }

        /**
         * Filter to add custom parsers
         *
         * @param array $parsers Array of parser instances.
         */
        $this->parsers = apply_filters( 'unmam_parsers', $this->parsers );
    }

    /**
     * Run a batch scan
     *
     * @param string $scan_type Type of scan to run.
     * @return array Result with processed count and status.
     */
    public function run_batch( $scan_type = 'posts' ) {
        $this->ensure_parsers_initialized();

        // Use resource monitor to get optimal batch size
        $resource_monitor = UNMAM_Resource_Monitor::instance();
        $resource_monitor->start_batch();

        $batch_size = $resource_monitor->get_recommended_batch_size();

        $result = array(
            'processed' => 0,
            'status'    => 'running',
            'message'   => '',
        );

        switch ( $scan_type ) {
            case 'posts':
                $result = $this->scan_posts_batch( $batch_size );
                break;

            case 'options':
                $result = $this->scan_options();
                break;

            case 'widgets':
                $result = $this->scan_widgets();
                break;

            case 'custom_tables':
                $result = $this->scan_custom_tables();
                break;

            default:
                /**
                 * Allow custom scan types
                 *
                 * @param array  $result     Result array.
                 * @param string $scan_type  Scan type.
                 * @param int    $batch_size Batch size.
                 */
                $result = apply_filters( 'unmam_custom_scan', $result, $scan_type, $batch_size );
                break;
        }

        return $result;
    }

    /**
     * Scan a batch of posts
     *
     * @param int $batch_size Number of posts to process.
     * @return array
     */
    private function scan_posts_batch( $batch_size ) {
        global $wpdb;

        $progress = UNMAM_Database::get_scan_progress( 'posts' );
        $last_id  = $progress ? (int) $progress['last_processed_id'] : 0;

        $settings      = Unattached_Media_Manager::get_setting();
        $allowed_types = $this->get_allowed_post_types( $settings );

        if ( empty( $allowed_types ) ) {
            UNMAM_Database::update_scan_progress( 'posts', array(
                'status'       => 'completed',
                'completed_at' => current_time( 'mysql' ),
            ) );
            return array(
                'processed' => 0,
                'status'    => 'completed',
                'message'   => __( 'No post types selected for scanning.', 'unattached-media-manager' ),
            );
        }

        $placeholders = implode( ', ', array_fill( 0, count( $allowed_types ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic IN clause
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_type, post_content, post_title
                FROM {$wpdb->posts}
                WHERE ID > %d
                AND post_status NOT IN ('trash', 'auto-draft')
                AND post_type IN ({$placeholders})
                ORDER BY ID ASC
                LIMIT %d",
                array_merge( array( $last_id ), $allowed_types, array( $batch_size ) )
            )
        );

        if ( empty( $posts ) ) {
            // Scan complete
            UNMAM_Database::update_scan_progress( 'posts', array(
                'status'       => 'completed',
                'completed_at' => current_time( 'mysql' ),
            ) );

            return array(
                'processed' => 0,
                'status'    => 'completed',
                'message'   => __( 'Post scan completed.', 'unattached-media-manager' ),
            );
        }

        $processed = 0;
        $resource_monitor = UNMAM_Resource_Monitor::instance();

        foreach ( $posts as $post ) {
            // Check if we should pause due to resource constraints
            if ( $resource_monitor->should_pause() ) {
                break;
            }

            $this->index_post( $post->ID, $post );
            $processed++;
            $last_id = $post->ID;
        }

        // Record batch performance
        $resource_monitor->end_batch( $processed );

        // Update progress
        UNMAM_Database::update_scan_progress( 'posts', array(
            'last_processed_id' => $last_id,
            'processed_items'   => ( $progress ? (int) $progress['processed_items'] : 0 ) + $processed,
            'status'            => 'running',
        ) );

        // Get resource status for response
        $resource_status = $resource_monitor->get_status();

        return array(
            'processed'      => $processed,
            'status'         => 'running',
            'last_id'        => $last_id,
            'batch_size'     => $batch_size,
            'resource_status' => $resource_status,
            'message'        => sprintf(
                /* translators: %d: number of posts processed */
                __( 'Processed %d posts.', 'unattached-media-manager' ),
                $processed
            ),
        );
    }

    /**
     * Index a single post
     *
     * @param int          $post_id Post ID.
     * @param WP_Post|null $post    Optional post object.
     */
    public function index_post( $post_id, $post = null ) {
        $this->ensure_parsers_initialized();

        if ( ! $post ) {
            $post = get_post( $post_id );
        }

        if ( ! $post || 'attachment' === $post->post_type ) {
            return;
        }

        // Clear existing references for this post
        UNMAM_Database::delete_references_by_source( $post_id, 'post' );

        $references = array();
        $settings   = Unattached_Media_Manager::get_setting();

        // Featured image
        if ( ! empty( $settings['scan_featured_images'] ) ) {
            $thumbnail_id = get_post_thumbnail_id( $post_id );
            if ( $thumbnail_id ) {
                $references[] = array(
                    'attachment_id'  => $thumbnail_id,
                    'source_id'      => $post_id,
                    'source_type'    => 'post',
                    'context_type'   => 'featured_image',
                    'context_key'    => '_thumbnail_id',
                    'context_label'  => __( 'Featured Image', 'unattached-media-manager' ),
                    'reference_type' => 'id',
                );
            }
        }

        // Run parsers
        foreach ( $this->parsers as $parser_name => $parser ) {
            if ( ! $parser instanceof UNMAM_Parser_Interface ) {
                continue;
            }

            $parser_refs = $parser->parse_post( $post );
            if ( ! empty( $parser_refs ) ) {
                $references = array_merge( $references, $parser_refs );
            }
        }

        // Save references
        $attachment_ids = array();
        foreach ( $references as $ref ) {
            UNMAM_Database::insert_reference( $ref );
            if ( ! empty( $ref['attachment_id'] ) ) {
                $attachment_ids[ $ref['attachment_id'] ] = $post_id;
            }
        }

        // Auto-attach feature: Update post_parent for found attachments
        if ( ! empty( $settings['auto_attach'] ) && ! empty( $attachment_ids ) ) {
            $this->auto_attach_media( $attachment_ids );
        }

        /**
         * Fires after a post is indexed
         *
         * @param int   $post_id    Post ID.
         * @param array $references Found references.
         */
        do_action( 'unmam_post_indexed', $post_id, $references );
    }

    /**
     * Auto-attach media to posts
     *
     * This is the key feature: when we find an image is used in a post,
     * we update its post_parent so WordPress's native "Unattached" filter
     * correctly shows only truly orphaned media.
     *
     * @param array $attachment_ids Array of attachment_id => post_id pairs.
     */
    private function auto_attach_media( $attachment_ids ) {
        foreach ( $attachment_ids as $attachment_id => $post_id ) {
            $attachment = get_post( $attachment_id );

            // Only update if currently unattached (post_parent = 0)
            if ( $attachment && 0 === (int) $attachment->post_parent ) {
                wp_update_post( array(
                    'ID'          => $attachment_id,
                    'post_parent' => $post_id,
                ) );

                /**
                 * Fires when an attachment is auto-attached
                 *
                 * @param int $attachment_id Attachment ID.
                 * @param int $post_id       Post ID it was attached to.
                 */
                do_action( 'unmam_attachment_auto_attached', $attachment_id, $post_id );
            }
        }
    }

    /**
     * Scan options table
     *
     * @return array
     */
    public function scan_options() {
        $this->ensure_parsers_initialized();

        // Clear existing option references
        UNMAM_Database::delete_references_by_source( 0, 'option' );

        $references = array();

        if ( isset( $this->parsers['options'] ) ) {
            $references = $this->parsers['options']->parse_options();
        }

        // Save references
        foreach ( $references as $ref ) {
            UNMAM_Database::insert_reference( $ref );
        }

        UNMAM_Database::update_scan_progress( 'options', array(
            'status'       => 'completed',
            'completed_at' => current_time( 'mysql' ),
        ) );

        return array(
            'processed' => count( $references ),
            'status'    => 'completed',
            'message'   => sprintf(
                /* translators: %d: number of references found */
                __( 'Found %d option references.', 'unattached-media-manager' ),
                count( $references )
            ),
        );
    }

    /**
     * Index options (alias for scan_options for consistency)
     */
    public function index_options() {
        return $this->scan_options();
    }

    /**
     * Scan widgets
     *
     * @return array
     */
    public function scan_widgets() {
        $this->ensure_parsers_initialized();

        // Clear existing widget references
        UNMAM_Database::delete_references_by_source( 0, 'widget' );

        $references = array();

        if ( isset( $this->parsers['widgets'] ) ) {
            $references = $this->parsers['widgets']->parse_widgets();
        }

        // Save references
        foreach ( $references as $ref ) {
            UNMAM_Database::insert_reference( $ref );
        }

        UNMAM_Database::update_scan_progress( 'widgets', array(
            'status'       => 'completed',
            'completed_at' => current_time( 'mysql' ),
        ) );

        return array(
            'processed' => count( $references ),
            'status'    => 'completed',
            'message'   => sprintf(
                /* translators: %d: number of references found */
                __( 'Found %d widget references.', 'unattached-media-manager' ),
                count( $references )
            ),
        );
    }

    /**
     * Scan admin-configured custom database tables.
     *
     * Cursor-paginated across all configured table/column entries. Stores
     * references with source_type 'custom_table' so the referenced media is
     * protected from the "unused" list. These references are intentionally
     * NOT eligible for auto-attach (a custom-table row is not a post).
     *
     * @return array
     */
    public function scan_custom_tables() {
        $this->ensure_parsers_initialized();

        $settings = Unattached_Media_Manager::get_setting();
        $entries  = ( isset( $settings['scan_custom_tables'] ) && is_array( $settings['scan_custom_tables'] ) )
            ? array_values( $settings['scan_custom_tables'] )
            : array();

        // Nothing configured: clear any stale rows and mark complete immediately.
        if ( empty( $entries ) ) {
            UNMAM_Database::delete_references_by_source_type( 'custom_table' );
            UNMAM_Database::update_scan_progress( 'custom_tables', array(
                'status'       => 'completed',
                'completed_at' => current_time( 'mysql' ),
            ) );
            return array(
                'processed' => 0,
                'status'    => 'completed',
                'message'   => __( 'No custom tables configured.', 'unattached-media-manager' ),
            );
        }

        if ( ! isset( $this->parsers['custom_tables'] ) ) {
            $this->parsers['custom_tables'] = new UNMAM_Custom_Table_Parser();
        }
        $parser = $this->parsers['custom_tables'];

        // Progress cursor: pack (entry index, row offset) into last_processed_id
        // as entry_index * MULTIPLIER + offset, so we can resume mid-table.
        $multiplier = 1000000000; // 1e9 rows per table ceiling for cursor packing
        $progress   = UNMAM_Database::get_scan_progress( 'custom_tables' );
        $cursor     = $progress ? (int) $progress['last_processed_id'] : 0;
        $entry_idx  = (int) floor( $cursor / $multiplier );
        $row_offset = $cursor % $multiplier;

        // First batch of a fresh run: clear previous custom-table references.
        if ( 0 === $cursor ) {
            UNMAM_Database::delete_references_by_source_type( 'custom_table' );
        }

        // Finished all entries.
        if ( $entry_idx >= count( $entries ) ) {
            UNMAM_Database::update_scan_progress( 'custom_tables', array(
                'status'       => 'completed',
                'completed_at' => current_time( 'mysql' ),
            ) );
            return array(
                'processed' => 0,
                'status'    => 'completed',
                'message'   => __( 'Custom table scan completed.', 'unattached-media-manager' ),
            );
        }

        $resource_monitor = UNMAM_Resource_Monitor::instance();
        $batch_size       = max( 10, (int) $resource_monitor->get_recommended_batch_size() );

        $entry  = $entries[ $entry_idx ];
        $result = $parser->scan_table( $entry, $row_offset, $batch_size );

        $references = isset( $result['references'] ) ? $result['references'] : array();
        $rows_read  = isset( $result['rows'] ) ? (int) $result['rows'] : 0;

        foreach ( $references as $ref ) {
            UNMAM_Database::insert_reference( $ref );
        }

        // Advance cursor.
        if ( $rows_read < $batch_size ) {
            // This entry is exhausted; move to the next entry, offset 0.
            $next_cursor = ( $entry_idx + 1 ) * $multiplier;
        } else {
            // Same entry, advance the row offset.
            $next_cursor = $entry_idx * $multiplier + ( $row_offset + $rows_read );
        }

        $all_done = ( $entry_idx + 1 >= count( $entries ) ) && ( $rows_read < $batch_size );

        UNMAM_Database::update_scan_progress( 'custom_tables', array(
            'last_processed_id' => $next_cursor,
            'status'            => $all_done ? 'completed' : 'running',
            'completed_at'      => $all_done ? current_time( 'mysql' ) : null,
        ) );

        return array(
            'processed' => count( $references ),
            'status'    => $all_done ? 'completed' : 'running',
            'message'   => sprintf(
                /* translators: %d: number of references found in this batch */
                __( 'Scanned custom table batch; found %d references.', 'unattached-media-manager' ),
                count( $references )
            ),
        );
    }

    /**
     * Get the ordered list of active scan types.
     *
     * Single source of truth for the scan pipeline. The historical
     * posts/options/widgets sequence always runs. 'custom_tables' is appended
     * only when the admin has configured at least one table/column entry, so
     * installs that never opt in behave exactly as before.
     *
     * @return string[]
     */
    public static function get_active_scan_types() {
        $types    = array( 'posts', 'options', 'widgets' );
        $settings = Unattached_Media_Manager::get_setting();

        if ( ! empty( $settings['scan_custom_tables'] ) && is_array( $settings['scan_custom_tables'] ) ) {
            $types[] = 'custom_tables';
        }

        return $types;
    }

    /**
     * Full scan - scan everything
     *
     * @param bool $reset Whether to reset progress first.
     * @return array
     */
    public function full_scan( $reset = true ) {
        if ( $reset ) {
            UNMAM_Database::reset_scan_progress();
            global $wpdb;
            $table = UNMAM_Database::get_table_name( 'references' );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "TRUNCATE TABLE {$table}" );
        }

        // Initialize progress
        $total_posts = $this->get_total_posts_count();
        UNMAM_Database::update_scan_progress( 'posts', array(
            'total_items'       => $total_posts,
            'processed_items'   => 0,
            'last_processed_id' => 0,
            'status'            => 'running',
            'started_at'        => current_time( 'mysql' ),
        ) );

        return array(
            'status'  => 'started',
            'total'   => $total_posts,
            'message' => __( 'Full scan started.', 'unattached-media-manager' ),
        );
    }

    /**
     * Get total posts count for scanning
     *
     * @return int
     */
    private function get_total_posts_count() {
        global $wpdb;

        $settings      = Unattached_Media_Manager::get_setting();
        $allowed_types = $this->get_allowed_post_types( $settings );

        if ( empty( $allowed_types ) ) {
            return 0;
        }

        $placeholders = implode( ', ', array_fill( 0, count( $allowed_types ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic IN clause
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                WHERE post_status NOT IN ('trash', 'auto-draft')
                AND post_type IN ({$placeholders})",
                $allowed_types
            )
        );
    }

    /**
     * Resolve the post types the scanner should query.
     *
     * Prefers the allow-list ($settings['scan_post_types']) introduced in 1.0.8.
     * Falls back to "all registered public types minus the legacy excluded
     * list" so installs that never visited Settings keep behaving like 1.0.7.
     * Always strips 'attachment', 'revision', and 'nav_menu_item' as a safety net.
     *
     * @param array $settings
     * @return string[]
     */
    private function get_allowed_post_types( $settings ) {
        if ( ! empty( $settings['scan_post_types'] ) && is_array( $settings['scan_post_types'] ) ) {
            $types = array_map( 'sanitize_key', $settings['scan_post_types'] );
        } else {
            $all      = get_post_types( array( 'public' => true ), 'names' );
            $excluded = isset( $settings['excluded_post_types'] ) && is_array( $settings['excluded_post_types'] )
                ? $settings['excluded_post_types']
                : array( 'revision', 'nav_menu_item' );
            $types = array_diff( $all, $excluded );
        }

        // Hard guards: never scan attachments, revisions, or nav menu items.
        $types = array_values( array_diff( $types, array( 'attachment', 'revision', 'nav_menu_item' ) ) );

        /**
         * Filter the post types the scanner queries.
         *
         * @since 1.0.8
         * @param string[] $types Post type slugs.
         */
        return apply_filters( 'unmam_scan_post_types', $types );
    }

    /**
     * Get overall scan status
     *
     * @return array
     */
    public function get_scan_status() {
        $posts_progress   = UNMAM_Database::get_scan_progress( 'posts' );
        $options_progress = UNMAM_Database::get_scan_progress( 'options' );
        $widgets_progress = UNMAM_Database::get_scan_progress( 'widgets' );

        $status = array(
            'posts'   => $posts_progress,
            'options' => $options_progress,
            'widgets' => $widgets_progress,
        );

        // Only surface custom_tables progress when the step is active, so the
        // payload and completion math are identical to prior versions otherwise.
        $active_types = self::get_active_scan_types();
        if ( in_array( 'custom_tables', $active_types, true ) ) {
            $status['custom_tables'] = UNMAM_Database::get_scan_progress( 'custom_tables' );
        }

        $status['overall'] = $this->calculate_overall_progress( $status, $active_types );

        return $status;
    }

    /**
     * Calculate overall progress percentage.
     *
     * Driven by the active scan-type list so the "all complete" check scales
     * with however many steps are configured (3 by default, 4 with custom
     * tables) instead of a hardcoded count.
     *
     * @param array    $progress     Map of scan_type => progress row (or null).
     * @param string[] $active_types Ordered active scan types.
     * @return array
     */
    private function calculate_overall_progress( $progress, $active_types ) {
        $total       = 0;
        $processed   = 0;
        $completed   = 0;
        $step_count  = count( $active_types );

        // 'posts' is weighted by its real item count; the other steps count as
        // a single unit each (they complete in one or a few batches).
        foreach ( $active_types as $type ) {
            $row = isset( $progress[ $type ] ) ? $progress[ $type ] : null;

            if ( 'posts' === $type ) {
                if ( $row ) {
                    $total     += (int) $row['total_items'];
                    $processed += (int) $row['processed_items'];
                    if ( 'completed' === $row['status'] ) {
                        $completed++;
                    }
                }
                continue;
            }

            if ( $row ) {
                $total++;
                if ( 'completed' === $row['status'] ) {
                    $processed++;
                    $completed++;
                }
            }
        }

        $percentage = $total > 0 ? round( ( $processed / $total ) * 100, 2 ) : 0;

        return array(
            'total'      => $total,
            'processed'  => $processed,
            'percentage' => $percentage,
            'status'     => ( $completed >= $step_count ) ? 'completed' : 'running',
        );
    }
}
