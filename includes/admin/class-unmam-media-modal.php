<?php
/**
 * Media Modal integration for Media Usage Inspector
 *
 * Adds "Where Used" panel to the media modal/attachment details
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Media Modal class
 */
class UNMAM_Media_Modal {

    /**
     * Single instance
     *
     * @var UNMAM_Media_Modal|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return UNMAM_Media_Modal
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
        // Add to attachment fields in media modal
        add_filter( 'attachment_fields_to_edit', array( $this, 'add_usage_field' ), 10, 2 );

        // AJAX handlers
        add_action( 'wp_ajax_unmam_get_attachment_usage', array( $this, 'ajax_get_usage' ) );
        add_action( 'wp_ajax_unmam_attach_to_post', array( $this, 'ajax_attach_to_post' ) );
        add_action( 'wp_ajax_unmam_detach_from_post', array( $this, 'ajax_detach_from_post' ) );
        add_action( 'wp_ajax_unmam_mark_safe', array( $this, 'ajax_mark_safe' ) );
        add_action( 'wp_ajax_unmam_unmark_safe', array( $this, 'ajax_unmark_safe' ) );

        // Enqueue scripts on media pages
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // Add column to media list
        add_filter( 'manage_media_columns', array( $this, 'add_usage_column' ) );
        add_action( 'manage_media_custom_column', array( $this, 'render_usage_column' ), 10, 2 );
    }

    /**
     * Enqueue scripts
     *
     * @param string $hook Current admin page.
     */
    public function enqueue_scripts( $hook ) {
        if ( ! in_array( $hook, array( 'upload.php', 'post.php', 'post-new.php' ), true ) ) {
            return;
        }

        wp_enqueue_script(
            'unmam-media-modal',
            UNMAM_PLUGIN_URL . 'assets/js/media-modal.js',
            array( 'jquery', 'media-views' ),
            UNMAM_VERSION,
            true
        );

        wp_localize_script( 'unmam-media-modal', 'unmamMediaModal', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'unmam_media_modal_nonce' ),
            'strings' => array(
                'whereUsed'    => __( 'Where Used', 'unattached-media-manager' ),
                'loading'      => __( 'Loading...', 'unattached-media-manager' ),
                'noReferences' => __( 'No references found', 'unattached-media-manager' ),
                /* translators: %d: number of locations */
                'usedIn'       => __( 'Used in %d location(s)', 'unattached-media-manager' ),
                'markSafe'     => __( 'Mark as Safe', 'unattached-media-manager' ),
                'unmarkSafe'   => __( 'Unmark as Safe', 'unattached-media-manager' ),
                'markedSafe'   => __( 'Marked as Safe', 'unattached-media-manager' ),
                'attachTo'     => __( 'Attach to Post', 'unattached-media-manager' ),
                'detach'       => __( 'Detach', 'unattached-media-manager' ),
                'edit'         => __( 'Edit', 'unattached-media-manager' ),
                'view'         => __( 'View', 'unattached-media-manager' ),
            ),
        ) );
    }

    /**
     * Add usage field to attachment edit form
     *
     * @param array   $fields     Existing fields.
     * @param WP_Post $attachment Attachment object.
     * @return array
     */
    public function add_usage_field( $fields, $attachment ) {
        $references    = UNMAM_Database::get_references_for_attachment( $attachment->ID, array( 'per_page' => 10 ) );
        $total_count   = UNMAM_Database::get_reference_count( $attachment->ID );
        $is_safe       = UNMAM_Attachment_Manager::instance()->is_marked_safe( $attachment->ID );

        ob_start();
        ?>
        <div class="mui-usage-panel" data-attachment-id="<?php echo esc_attr( $attachment->ID ); ?>">
            <div class="mui-usage-summary">
                <?php if ( $total_count > 0 ) : ?>
                    <span class="mui-usage-count mui-usage-found">
                        <?php
                        printf(
                            /* translators: %d: number of references */
                            esc_html__( 'Used in %d location(s)', 'unattached-media-manager' ),
                            intval( $total_count )
                        );
                        ?>
                    </span>
                <?php else : ?>
                    <span class="mui-usage-count mui-usage-none">
                        <?php esc_html_e( 'No references found', 'unattached-media-manager' ); ?>
                    </span>
                <?php endif; ?>

                <?php if ( $is_safe ) : ?>
                    <span class="mui-safe-badge"><?php esc_html_e( 'Marked Safe', 'unattached-media-manager' ); ?></span>
                <?php endif; ?>
            </div>

            <?php if ( ! empty( $references ) ) : ?>
            <ul class="mui-reference-list">
                <?php foreach ( $references as $ref ) : ?>
                <li class="mui-reference-item">
                    <span class="mui-ref-context"><?php echo esc_html( $ref['context_label'] ); ?></span>
                    <?php if ( $ref['source_id'] > 0 ) : ?>
                        <span class="mui-ref-source">
                            <?php
                            $source_title = get_the_title( $ref['source_id'] );
                            /* translators: %d: post ID */
                            echo esc_html( $source_title ?: sprintf( __( 'Post #%d', 'unattached-media-manager' ), $ref['source_id'] ) );
                            ?>
                        </span>
                        <a href="<?php echo esc_url( get_edit_post_link( $ref['source_id'] ) ); ?>" class="mui-ref-edit" target="_blank">
                            <?php esc_html_e( 'Edit', 'unattached-media-manager' ); ?>
                        </a>
                    <?php else : ?>
                        <span class="mui-ref-source"><?php echo esc_html( $ref['context_key'] ); ?></span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>

            <?php if ( $total_count > 10 ) : ?>
            <p class="mui-view-all">
                <a href="<?php echo esc_url( admin_url( 'upload.php?page=unattached-media-manager&attachment=' . $attachment->ID ) ); ?>">
                    <?php esc_html_e( 'View all references →', 'unattached-media-manager' ); ?>
                </a>
            </p>
            <?php endif; ?>
            <?php endif; ?>

            <div class="mui-usage-actions">
                <?php if ( ! $is_safe ) : ?>
                    <button type="button" class="button mui-mark-safe" data-attachment-id="<?php echo esc_attr( $attachment->ID ); ?>">
                        <?php esc_html_e( 'Mark as Safe', 'unattached-media-manager' ); ?>
                    </button>
                <?php else : ?>
                    <button type="button" class="button mui-unmark-safe" data-attachment-id="<?php echo esc_attr( $attachment->ID ); ?>">
                        <?php esc_html_e( 'Unmark as Safe', 'unattached-media-manager' ); ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php
        $html = ob_get_clean();

        $fields['unmam_usage'] = array(
            'label' => __( 'Where Used', 'unattached-media-manager' ),
            'input' => 'html',
            'html'  => $html,
        );

        return $fields;
    }

    /**
     * Add usage column to media list
     *
     * @param array $columns Existing columns.
     * @return array
     */
    public function add_usage_column( $columns ) {
        $columns['unmam_usage'] = __( 'Usage', 'unattached-media-manager' );
        return $columns;
    }

    /**
     * Render usage column
     *
     * @param string $column_name Column name.
     * @param int    $post_id     Post ID.
     */
    public function render_usage_column( $column_name, $post_id ) {
        if ( 'unmam_usage' !== $column_name ) {
            return;
        }

        $count   = UNMAM_Database::get_reference_count( $post_id );
        $is_safe = UNMAM_Attachment_Manager::instance()->is_marked_safe( $post_id );

        if ( $count > 0 ) {
            printf(
                '<span class="mui-col-usage mui-col-used" title="%s">%d</span>',
                esc_attr__( 'References found', 'unattached-media-manager' ),
                intval( $count )
            );
        } else {
            echo '<span class="mui-col-usage mui-col-unused" title="' . esc_attr__( 'No references found', 'unattached-media-manager' ) . '">0</span>';
        }

        if ( $is_safe ) {
            echo '<span class="mui-col-safe dashicons dashicons-shield" title="' . esc_attr__( 'Marked as Safe', 'unattached-media-manager' ) . '"></span>';
        }
    }

    /**
     * AJAX: Get attachment usage
     */
    public function ajax_get_usage() {
        check_ajax_referer( 'unmam_media_modal_nonce', 'nonce' );

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

        if ( ! $attachment_id ) {
            wp_send_json_error( 'Invalid attachment ID' );
        }

        $page       = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $references = UNMAM_Database::get_references_for_attachment( $attachment_id, array(
            'per_page' => 20,
            'page'     => $page,
        ) );

        $total_count = UNMAM_Database::get_reference_count( $attachment_id );
        $is_safe     = UNMAM_Attachment_Manager::instance()->is_marked_safe( $attachment_id );

        // Enrich references with titles
        foreach ( $references as &$ref ) {
            if ( $ref['source_id'] > 0 ) {
                /* translators: %d: post ID */
                $ref['source_title'] = get_the_title( $ref['source_id'] ) ?: sprintf( __( 'Post #%d', 'unattached-media-manager' ), $ref['source_id'] );
                $ref['edit_link']    = get_edit_post_link( $ref['source_id'] );
                $ref['view_link']    = get_permalink( $ref['source_id'] );
            }
        }

        wp_send_json_success( array(
            'references'  => $references,
            'total_count' => $total_count,
            'is_safe'     => $is_safe,
            'has_more'    => ( $page * 20 ) < $total_count,
        ) );
    }

    /**
     * AJAX: Attach to post
     */
    public function ajax_attach_to_post() {
        check_ajax_referer( 'unmam_media_modal_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        $post_id       = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

        if ( ! $attachment_id || ! $post_id ) {
            wp_send_json_error( 'Invalid parameters' );
        }

        $result = UNMAM_Attachment_Manager::instance()->attach( $attachment_id, $post_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'message' => __( 'Attachment attached successfully.', 'unattached-media-manager' ),
        ) );
    }

    /**
     * AJAX: Detach from post
     */
    public function ajax_detach_from_post() {
        check_ajax_referer( 'unmam_media_modal_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

        if ( ! $attachment_id ) {
            wp_send_json_error( 'Invalid attachment ID' );
        }

        $result = UNMAM_Attachment_Manager::instance()->detach( $attachment_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'message' => __( 'Attachment detached successfully.', 'unattached-media-manager' ),
        ) );
    }

    /**
     * AJAX: Mark as safe
     */
    public function ajax_mark_safe() {
        check_ajax_referer( 'unmam_media_modal_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

        if ( ! $attachment_id ) {
            wp_send_json_error( 'Invalid attachment ID' );
        }

        UNMAM_Attachment_Manager::instance()->mark_safe( $attachment_id );

        wp_send_json_success( array(
            'message' => __( 'Attachment marked as safe.', 'unattached-media-manager' ),
        ) );
    }

    /**
     * AJAX: Unmark as safe
     */
    public function ajax_unmark_safe() {
        check_ajax_referer( 'unmam_media_modal_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

        if ( ! $attachment_id ) {
            wp_send_json_error( 'Invalid attachment ID' );
        }

        UNMAM_Attachment_Manager::instance()->unmark_safe( $attachment_id );

        wp_send_json_success( array(
            'message' => __( 'Attachment unmarked as safe.', 'unattached-media-manager' ),
        ) );
    }
}
