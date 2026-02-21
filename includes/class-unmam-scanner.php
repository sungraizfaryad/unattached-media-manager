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

        $settings       = Unattached_Media_Manager::get_setting();
        $excluded_types = isset( $settings['excluded_post_types'] ) ? $settings['excluded_post_types'] : array( 'revision', 'nav_menu_item' );

        // Build exclusion placeholders
        $placeholders = implode( ', ', array_fill( 0, count( $excluded_types ), '%s' ) );

        // Get post types to scan
        $query_args = array_merge( array( $last_id, $batch_size ), $excluded_types );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic IN clause
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_type, post_content, post_title
                FROM {$wpdb->posts}
                WHERE ID > %d
                AND post_status NOT IN ('trash', 'auto-draft')
                AND post_type NOT IN ({$placeholders})
                ORDER BY ID ASC
                LIMIT %d",
                array_merge( array( $last_id ), $excluded_types, array( $batch_size ) )
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

        $settings       = Unattached_Media_Manager::get_setting();
        $excluded_types = isset( $settings['excluded_post_types'] ) ? $settings['excluded_post_types'] : array( 'revision', 'nav_menu_item' );

        $placeholders = implode( ', ', array_fill( 0, count( $excluded_types ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic IN clause
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                WHERE post_status NOT IN ('trash', 'auto-draft')
                AND post_type NOT IN ({$placeholders})",
                $excluded_types
            )
        );
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

        return array(
            'posts'   => $posts_progress,
            'options' => $options_progress,
            'widgets' => $widgets_progress,
            'overall' => $this->calculate_overall_progress( $posts_progress, $options_progress, $widgets_progress ),
        );
    }

    /**
     * Calculate overall progress percentage
     *
     * @param array|null $posts   Posts progress.
     * @param array|null $options Options progress.
     * @param array|null $widgets Widgets progress.
     * @return array
     */
    private function calculate_overall_progress( $posts, $options, $widgets ) {
        $total     = 0;
        $processed = 0;
        $completed = 0;

        if ( $posts ) {
            $total     += (int) $posts['total_items'];
            $processed += (int) $posts['processed_items'];
            if ( 'completed' === $posts['status'] ) {
                $completed++;
            }
        }

        // Options and widgets count as 1 item each
        if ( $options ) {
            $total++;
            if ( 'completed' === $options['status'] ) {
                $processed++;
                $completed++;
            }
        }

        if ( $widgets ) {
            $total++;
            if ( 'completed' === $widgets['status'] ) {
                $processed++;
                $completed++;
            }
        }

        $percentage = $total > 0 ? round( ( $processed / $total ) * 100, 2 ) : 0;

        return array(
            'total'      => $total,
            'processed'  => $processed,
            'percentage' => $percentage,
            'status'     => ( 3 === $completed ) ? 'completed' : 'running',
        );
    }
}
