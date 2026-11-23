<?php
/**
 * Bulk Actions for Media Usage Inspector
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bulk Actions class
 */
class UNMAM_Bulk_Actions {

    /**
     * Single instance
     *
     * @var UNMAM_Bulk_Actions|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return UNMAM_Bulk_Actions
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
        // Add bulk actions to media library
        add_filter( 'bulk_actions-upload', array( $this, 'add_bulk_actions' ) );
        add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_actions' ), 10, 3 );

        // Admin notices for bulk action results
        add_action( 'admin_notices', array( $this, 'bulk_action_notices' ) );

        // AJAX handlers
        add_action( 'wp_ajax_unmam_bulk_attach', array( $this, 'ajax_bulk_attach' ) );
        add_action( 'wp_ajax_unmam_bulk_detach', array( $this, 'ajax_bulk_detach' ) );
        add_action( 'wp_ajax_unmam_bulk_mark_safe', array( $this, 'ajax_bulk_mark_safe' ) );
        add_action( 'wp_ajax_unmam_bulk_mark_unused', array( $this, 'ajax_bulk_mark_unused' ) );
        add_action( 'wp_ajax_unmam_bulk_delete_unused', array( $this, 'ajax_bulk_delete_unused' ) );
        add_action( 'wp_ajax_unmam_replace_attachment', array( $this, 'ajax_replace_attachment' ) );
    }

    /**
     * Add bulk actions to media library dropdown
     *
     * @param array $actions Existing actions.
     * @return array
     */
    public function add_bulk_actions( $actions ) {
        $actions['unmam_mark_safe']   = __( 'MUI: Mark as Safe', 'unattached-media-manager' );
        $actions['unmam_unmark_safe'] = __( 'MUI: Unmark as Safe', 'unattached-media-manager' );
        $actions['unmam_mark_unused'] = __( 'MUI: Mark as Unused', 'unattached-media-manager' );
        $actions['unmam_detach']      = __( 'MUI: Detach from Parent', 'unattached-media-manager' );
        return $actions;
    }

    /**
     * Handle bulk actions
     *
     * @param string $redirect_to Redirect URL.
     * @param string $action      Action name.
     * @param array  $post_ids    Selected post IDs.
     * @return string
     */
    public function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
        $manager = UNMAM_Attachment_Manager::instance();
        $count   = 0;

        switch ( $action ) {
            case 'unmam_mark_safe':
                foreach ( $post_ids as $post_id ) {
                    if ( '' === $this->item_error( $post_id ) && $manager->mark_safe( $post_id ) ) {
                        $count++;
                    }
                }
                $redirect_to = add_query_arg( 'unmam_marked_safe', $count, $redirect_to );
                break;

            case 'unmam_unmark_safe':
                foreach ( $post_ids as $post_id ) {
                    if ( '' === $this->item_error( $post_id ) && $manager->unmark_safe( $post_id ) ) {
                        $count++;
                    }
                }
                $redirect_to = add_query_arg( 'unmam_unmarked_safe', $count, $redirect_to );
                break;

            case 'unmam_mark_unused':
                foreach ( $post_ids as $post_id ) {
                    if ( '' === $this->item_error( $post_id ) && $manager->mark_unused( $post_id ) ) {
                        $count++;
                    }
                }
                $redirect_to = add_query_arg( 'unmam_marked_unused', $count, $redirect_to );
                break;

            case 'unmam_detach':
                $allowed = array_filter( $post_ids, function ( $post_id ) {
                    return '' === $this->item_error( $post_id );
                } );
                $results = $manager->bulk_detach( $allowed );
                $count   = count( $results['success'] );
                $redirect_to = add_query_arg( 'unmam_detached', $count, $redirect_to );
                break;
        }

        return $redirect_to;
    }

    /**
     * Display admin notices for bulk action results
     */
    public function bulk_action_notices() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no action taken
        if ( ! empty( $_GET['unmam_marked_safe'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no action taken
            $count = absint( $_GET['unmam_marked_safe'] );
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                sprintf(
                    /* translators: %d: number of items */
                    esc_html( _n(
                        '%d media item marked as safe.',
                        '%d media items marked as safe.',
                        $count,
                        'unattached-media-manager'
                    ) ),
                    intval( $count )
                )
            );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no action taken
        if ( ! empty( $_GET['unmam_unmarked_safe'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no action taken
            $count = absint( $_GET['unmam_unmarked_safe'] );
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                sprintf(
                    /* translators: %d: number of items */
                    esc_html( _n(
                        '%d media item unmarked as safe.',
                        '%d media items unmarked as safe.',
                        $count,
                        'unattached-media-manager'
                    ) ),
                    intval( $count )
                )
            );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no action taken
        if ( ! empty( $_GET['unmam_marked_unused'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no action taken
            $count = absint( $_GET['unmam_marked_unused'] );
            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                sprintf(
                    /* translators: %d: number of items */
                    esc_html( _n(
                        '%d media item marked as unused.',
                        '%d media items marked as unused.',
                        $count,
                        'unattached-media-manager'
                    ) ),
                    intval( $count )
                )
            );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no action taken
        if ( ! empty( $_GET['unmam_detached'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, no action taken
            $count = absint( $_GET['unmam_detached'] );
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                sprintf(
                    /* translators: %d: number of items */
                    esc_html( _n(
                        '%d media item detached.',
                        '%d media items detached.',
                        $count,
                        'unattached-media-manager'
                    ) ),
                    intval( $count )
                )
            );
        }
    }

    /**
     * Why the current user may not act on this attachment, or '' when they may.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $cap           Capability to check on the item.
     * @return string
     */
    private function item_error( $attachment_id, $cap = 'edit_post' ) {
        if ( 'attachment' !== get_post_type( $attachment_id ) ) {
            return __( 'Invalid attachment ID.', 'unattached-media-manager' );
        }
        if ( ! current_user_can( $cap, $attachment_id ) ) {
            return __( 'Permission denied', 'unattached-media-manager' );
        }
        return '';
    }

    /**
     * AJAX: Bulk attach
     */
    public function ajax_bulk_attach() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $attachment_ids = isset( $_POST['attachment_ids'] ) ? array_map( 'absint', (array) $_POST['attachment_ids'] ) : array();
        $post_id        = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

        if ( empty( $attachment_ids ) || ! $post_id ) {
            wp_send_json_error( 'Invalid parameters' );
        }

        $can_edit_post = current_user_can( 'edit_post', $post_id );
        $allowed       = array();
        $errors        = array();

        foreach ( $attachment_ids as $id ) {
            $error = $this->item_error( $id );
            if ( '' === $error && ! $can_edit_post ) {
                $error = __( 'Permission denied', 'unattached-media-manager' );
            }
            if ( '' === $error ) {
                $allowed[] = $id;
            } else {
                $errors[ $id ] = $error;
            }
        }

        $manager = UNMAM_Attachment_Manager::instance();
        $results = $manager->bulk_attach( $allowed, $post_id );
        $errors  = $errors + $results['errors'];

        wp_send_json_success( array(
            'success_count' => count( $results['success'] ),
            'error_count'   => count( $errors ),
            'errors'        => $errors,
        ) );
    }

    /**
     * AJAX: Bulk detach
     */
    public function ajax_bulk_detach() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $attachment_ids = isset( $_POST['attachment_ids'] ) ? array_map( 'absint', (array) $_POST['attachment_ids'] ) : array();

        if ( empty( $attachment_ids ) ) {
            wp_send_json_error( 'No attachments specified' );
        }

        $allowed = array();
        $errors  = array();

        foreach ( $attachment_ids as $id ) {
            $error = $this->item_error( $id );
            if ( '' === $error ) {
                $allowed[] = $id;
            } else {
                $errors[ $id ] = $error;
            }
        }

        $manager = UNMAM_Attachment_Manager::instance();
        $results = $manager->bulk_detach( $allowed );
        $errors  = $errors + $results['errors'];

        wp_send_json_success( array(
            'success_count' => count( $results['success'] ),
            'error_count'   => count( $errors ),
            'errors'        => $errors,
        ) );
    }

    /**
     * AJAX: Bulk mark safe
     */
    public function ajax_bulk_mark_safe() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $attachment_ids = isset( $_POST['attachment_ids'] ) ? array_map( 'absint', (array) $_POST['attachment_ids'] ) : array();

        if ( empty( $attachment_ids ) ) {
            wp_send_json_error( 'No attachments specified' );
        }

        $manager = UNMAM_Attachment_Manager::instance();
        $count   = 0;
        $errors  = array();

        foreach ( $attachment_ids as $id ) {
            $error = $this->item_error( $id );
            if ( '' !== $error ) {
                $errors[ $id ] = $error;
                continue;
            }
            if ( $manager->mark_safe( $id ) ) {
                $count++;
            }
        }

        wp_send_json_success( array(
            'count'  => $count,
            'errors' => $errors,
        ) );
    }

    /**
     * AJAX: Bulk mark unused
     */
    public function ajax_bulk_mark_unused() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $attachment_ids = isset( $_POST['attachment_ids'] ) ? array_map( 'absint', (array) $_POST['attachment_ids'] ) : array();

        if ( empty( $attachment_ids ) ) {
            wp_send_json_error( 'No attachments specified' );
        }

        $manager = UNMAM_Attachment_Manager::instance();
        $count   = 0;
        $errors  = array();

        foreach ( $attachment_ids as $id ) {
            $error = $this->item_error( $id );
            if ( '' !== $error ) {
                $errors[ $id ] = $error;
                continue;
            }
            if ( $manager->mark_unused( $id ) ) {
                $count++;
            }
        }

        wp_send_json_success( array(
            'count'  => $count,
            'errors' => $errors,
        ) );
    }

    /**
     * AJAX: Bulk delete unused
     */
    public function ajax_bulk_delete_unused() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'delete_posts' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $attachment_ids = isset( $_POST['attachment_ids'] ) ? array_map( 'absint', (array) $_POST['attachment_ids'] ) : array();
        $force_delete   = isset( $_POST['force_delete'] ) && $_POST['force_delete'] === 'true';

        if ( empty( $attachment_ids ) ) {
            wp_send_json_error( 'No attachments specified' );
        }

        $manager = UNMAM_Attachment_Manager::instance();
        $deleted = 0;
        $errors  = array();

        foreach ( $attachment_ids as $id ) {
            $error = $this->item_error( $id, 'delete_post' );
            if ( '' !== $error ) {
                $errors[ $id ] = $error;
                continue;
            }
            $result = $manager->safe_delete( $id, $force_delete );
            if ( is_wp_error( $result ) ) {
                $errors[ $id ] = $result->get_error_message();
            } else {
                $deleted++;
            }
        }

        wp_send_json_success( array(
            'deleted' => $deleted,
            'errors'  => $errors,
        ) );
    }

    /**
     * AJAX: Replace attachment references
     */
    public function ajax_replace_attachment() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $old_id  = isset( $_POST['old_attachment_id'] ) ? absint( $_POST['old_attachment_id'] ) : 0;
        $new_id  = isset( $_POST['new_attachment_id'] ) ? absint( $_POST['new_attachment_id'] ) : 0;
        $dry_run = isset( $_POST['dry_run'] ) && $_POST['dry_run'] === 'true';

        if ( ! $old_id || ! $new_id ) {
            wp_send_json_error( 'Invalid attachment IDs' );
        }

        $manager = UNMAM_Attachment_Manager::instance();
        $results = $manager->replace_references( $old_id, $new_id, $dry_run );

        if ( ! $results['success'] ) {
            wp_send_json_error( $results['error'] );
        }

        wp_send_json_success( $results );
    }
}
