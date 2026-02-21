<?php
/**
 * REST API Controller for Media Usage Inspector
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST Controller class
 */
class UNMAM_REST_Controller extends WP_REST_Controller {

    /**
     * Namespace
     *
     * @var string
     */
    protected $namespace = 'unmam/v1';

    /**
     * Register routes
     */
    public function register_routes() {
        // Get attachment usage (admin-only as it reveals site-wide content relationships)
        register_rest_route( $this->namespace, '/attachments/(?P<id>\d+)/usage', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_attachment_usage' ),
                'permission_callback' => array( $this, 'admin_permissions_check' ),
                'args'                => array(
                    'id' => array(
                        'required'          => true,
                        'validate_callback' => function( $param ) {
                            return is_numeric( $param );
                        },
                    ),
                    'page' => array(
                        'default'           => 1,
                        'validate_callback' => function( $param ) {
                            return is_numeric( $param ) && $param > 0;
                        },
                    ),
                    'per_page' => array(
                        'default'           => 20,
                        'validate_callback' => function( $param ) {
                            return is_numeric( $param ) && $param > 0 && $param <= 100;
                        },
                    ),
                ),
            ),
        ) );

        // Get statistics (admin-only as it reveals site-wide internal data)
        register_rest_route( $this->namespace, '/statistics', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_statistics' ),
                'permission_callback' => array( $this, 'admin_permissions_check' ),
            ),
        ) );

        // Get unused attachments (admin-only as it lists site-wide unused media)
        register_rest_route( $this->namespace, '/attachments/unused', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_unused_attachments' ),
                'permission_callback' => array( $this, 'admin_permissions_check' ),
                'args'                => array(
                    'page' => array(
                        'default' => 1,
                    ),
                    'per_page' => array(
                        'default' => 50,
                    ),
                ),
            ),
        ) );

        // Scan endpoints
        register_rest_route( $this->namespace, '/scan/start', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'start_scan' ),
                'permission_callback' => array( $this, 'admin_permissions_check' ),
            ),
        ) );

        register_rest_route( $this->namespace, '/scan/status', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_scan_status' ),
                'permission_callback' => array( $this, 'admin_permissions_check' ),
            ),
        ) );

        register_rest_route( $this->namespace, '/scan/batch', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'run_scan_batch' ),
                'permission_callback' => array( $this, 'admin_permissions_check' ),
                'args'                => array(
                    'type' => array(
                        'default' => 'posts',
                        'enum'    => array( 'posts', 'options', 'widgets' ),
                    ),
                ),
            ),
        ) );

        // Attachment actions (check if user can edit both the attachment and target post)
        register_rest_route( $this->namespace, '/attachments/(?P<id>\d+)/attach', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'attach_to_post' ),
                'permission_callback' => array( $this, 'attach_permissions_check' ),
                'args'                => array(
                    'id' => array(
                        'required' => true,
                    ),
                    'post_id' => array(
                        'required'          => true,
                        'validate_callback' => function( $param ) {
                            return is_numeric( $param );
                        },
                    ),
                ),
            ),
        ) );

        register_rest_route( $this->namespace, '/attachments/(?P<id>\d+)/detach', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'detach_from_post' ),
                'permission_callback' => array( $this, 'edit_attachment_permissions_check' ),
            ),
        ) );

        register_rest_route( $this->namespace, '/attachments/(?P<id>\d+)/mark-safe', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'mark_safe' ),
                'permission_callback' => array( $this, 'edit_attachment_permissions_check' ),
            ),
        ) );

        register_rest_route( $this->namespace, '/attachments/(?P<id>\d+)/unmark-safe', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'unmark_safe' ),
                'permission_callback' => array( $this, 'edit_attachment_permissions_check' ),
            ),
        ) );

        // Replace references
        register_rest_route( $this->namespace, '/attachments/replace', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'replace_references' ),
                'permission_callback' => array( $this, 'admin_permissions_check' ),
                'args'                => array(
                    'old_id' => array(
                        'required' => true,
                    ),
                    'new_id' => array(
                        'required' => true,
                    ),
                    'dry_run' => array(
                        'default' => false,
                    ),
                ),
            ),
        ) );

        // Export
        register_rest_route( $this->namespace, '/export', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'export_report' ),
                'permission_callback' => array( $this, 'admin_permissions_check' ),
                'args'                => array(
                    'format' => array(
                        'default' => 'json',
                        'enum'    => array( 'json', 'csv' ),
                    ),
                ),
            ),
        ) );

        // Index single post
        register_rest_route( $this->namespace, '/index/post/(?P<id>\d+)', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'index_single_post' ),
                'permission_callback' => array( $this, 'admin_permissions_check' ),
            ),
        ) );
    }

    /**
     * Check if user can edit a specific attachment
     *
     * @param WP_REST_Request $request Request object.
     * @return bool
     */
    public function edit_attachment_permissions_check( $request ) {
        $attachment_id = (int) $request->get_param( 'id' );

        // First check basic capability.
        if ( ! current_user_can( 'upload_files' ) ) {
            return false;
        }

        // Then check if user can edit this specific attachment.
        return current_user_can( 'edit_post', $attachment_id );
    }

    /**
     * Check if user can attach media to a target post
     *
     * @param WP_REST_Request $request Request object.
     * @return bool
     */
    public function attach_permissions_check( $request ) {
        $attachment_id = (int) $request->get_param( 'id' );
        $post_id       = (int) $request->get_param( 'post_id' );

        if ( ! current_user_can( 'upload_files' ) ) {
            return false;
        }

        // Check user can edit the attachment.
        if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
            return false;
        }

        // Also check user can edit the target post.
        if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
            return false;
        }

        return true;
    }

    /**
     * Check if user is admin
     *
     * @param WP_REST_Request $request Request object.
     * @return bool
     */
    public function admin_permissions_check( $request ) {
        return current_user_can( 'manage_options' );
    }

    /**
     * Get attachment usage
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_attachment_usage( $request ) {
        $attachment_id = (int) $request->get_param( 'id' );
        $page          = (int) $request->get_param( 'page' );
        $per_page      = (int) $request->get_param( 'per_page' );

        $references = UNMAM_Database::get_references_for_attachment( $attachment_id, array(
            'page'     => $page,
            'per_page' => $per_page,
        ) );

        $total = UNMAM_Database::get_reference_count( $attachment_id );

        // Enrich with post titles
        foreach ( $references as &$ref ) {
            if ( $ref['source_id'] > 0 ) {
                $ref['source_title'] = get_the_title( $ref['source_id'] );
                $ref['edit_url']     = get_edit_post_link( $ref['source_id'], 'raw' );
            }
        }

        return rest_ensure_response( array(
            'attachment_id' => $attachment_id,
            'references'    => $references,
            'total'         => $total,
            'page'          => $page,
            'per_page'      => $per_page,
            'total_pages'   => ceil( $total / $per_page ),
        ) );
    }

    /**
     * Get statistics
     *
     * @return WP_REST_Response
     */
    public function get_statistics() {
        return rest_ensure_response( UNMAM_Database::get_statistics() );
    }

    /**
     * Get unused attachments
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_unused_attachments( $request ) {
        $page     = (int) $request->get_param( 'page' );
        $per_page = (int) $request->get_param( 'per_page' );

        $unused = UNMAM_Database::get_unused_attachments( array(
            'page'     => $page,
            'per_page' => $per_page,
        ) );

        return rest_ensure_response( array(
            'attachments' => $unused,
            'page'        => $page,
            'per_page'    => $per_page,
        ) );
    }

    /**
     * Start full scan
     *
     * @return WP_REST_Response
     */
    public function start_scan() {
        $result = UNMAM_Scanner::instance()->full_scan( true );
        return rest_ensure_response( $result );
    }

    /**
     * Get scan status
     *
     * @return WP_REST_Response
     */
    public function get_scan_status() {
        return rest_ensure_response( UNMAM_Scanner::instance()->get_scan_status() );
    }

    /**
     * Run scan batch
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function run_scan_batch( $request ) {
        $type   = $request->get_param( 'type' );
        $result = UNMAM_Scanner::instance()->run_batch( $type );
        return rest_ensure_response( $result );
    }

    /**
     * Attach to post
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function attach_to_post( $request ) {
        $attachment_id = (int) $request->get_param( 'id' );
        $post_id       = (int) $request->get_param( 'post_id' );

        $result = UNMAM_Attachment_Manager::instance()->attach( $attachment_id, $post_id );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Attachment attached successfully.', 'unattached-media-manager' ),
        ) );
    }

    /**
     * Detach from post
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function detach_from_post( $request ) {
        $attachment_id = (int) $request->get_param( 'id' );

        $result = UNMAM_Attachment_Manager::instance()->detach( $attachment_id );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Attachment detached successfully.', 'unattached-media-manager' ),
        ) );
    }

    /**
     * Mark as safe
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function mark_safe( $request ) {
        $attachment_id = (int) $request->get_param( 'id' );
        UNMAM_Attachment_Manager::instance()->mark_safe( $attachment_id );

        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Attachment marked as safe.', 'unattached-media-manager' ),
        ) );
    }

    /**
     * Unmark as safe
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function unmark_safe( $request ) {
        $attachment_id = (int) $request->get_param( 'id' );
        UNMAM_Attachment_Manager::instance()->unmark_safe( $attachment_id );

        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Attachment unmarked as safe.', 'unattached-media-manager' ),
        ) );
    }

    /**
     * Replace references
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function replace_references( $request ) {
        $old_id  = (int) $request->get_param( 'old_id' );
        $new_id  = (int) $request->get_param( 'new_id' );
        $dry_run = (bool) $request->get_param( 'dry_run' );

        $result = UNMAM_Attachment_Manager::instance()->replace_references( $old_id, $new_id, $dry_run );

        return rest_ensure_response( $result );
    }

    /**
     * Export report
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function export_report( $request ) {
        $format = $request->get_param( 'format' );
        $data   = UNMAM_Attachment_Manager::instance()->export_report();

        if ( 'csv' === $format ) {
            $csv = '';
            foreach ( $data as $row ) {
                $csv .= implode( ',', array_map( function( $cell ) {
                    return '"' . str_replace( '"', '""', $cell ) . '"';
                }, $row ) ) . "\n";
            }

            return rest_ensure_response( array(
                'format'  => 'csv',
                'content' => $csv,
            ) );
        }

        return rest_ensure_response( array(
            'format' => 'json',
            'data'   => $data,
        ) );
    }

    /**
     * Index single post
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function index_single_post( $request ) {
        $post_id = (int) $request->get_param( 'id' );

        UNMAM_Scanner::instance()->index_post( $post_id );

        return rest_ensure_response( array(
            'success' => true,
            'post_id' => $post_id,
            'message' => __( 'Post indexed successfully.', 'unattached-media-manager' ),
        ) );
    }
}
