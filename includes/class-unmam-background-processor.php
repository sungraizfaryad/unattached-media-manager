<?php
/**
 * Background Processor for Media Usage Inspector
 *
 * Handles background scanning using loopback HTTP requests for reliable
 * processing that doesn't depend on site visitors.
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Background Processor class
 */
class UNMAM_Background_Processor {

    /**
     * Single instance
     *
     * @var UNMAM_Background_Processor|null
     */
    private static $instance = null;

    /**
     * Cron hook name for batch processing (fallback)
     */
    const CRON_HOOK = 'unmam_process_batch';

    /**
     * Cron interval name
     */
    const CRON_INTERVAL = 'unmam_every_minute';

    /**
     * Option keys for scan state
     */
    const OPTION_SCAN_STATE = 'unmam_scan_state';
    const OPTION_SCAN_PAUSED = 'unmam_scan_paused';
    const OPTION_PROCESS_LOCK = 'unmam_process_lock';
    const OPTION_PROCESS_KEY = 'unmam_process_key';

    /**
     * Scan states
     */
    const STATE_IDLE = 'idle';
    const STATE_RUNNING = 'running';
    const STATE_PAUSED = 'paused';
    const STATE_COMPLETED = 'completed';

    /**
     * Max execution time for batch processing (in seconds)
     */
    const MAX_EXECUTION_TIME = 25;

    /**
     * Lock timeout in seconds
     */
    const LOCK_TIMEOUT = 60;

    /**
     * Get singleton instance
     *
     * @return UNMAM_Background_Processor
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
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Register custom cron interval (fallback)
        add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );

        // Register cron handler (fallback)
        add_action( self::CRON_HOOK, array( $this, 'process_batch' ) );

        // Loopback endpoint - handles background processing via HTTP requests
        // Uses both authenticated and non-authenticated for reliability
        add_action( 'wp_ajax_unmam_async_process', array( $this, 'handle_async_request' ) );
        add_action( 'wp_ajax_nopriv_unmam_async_process', array( $this, 'handle_async_request' ) );

        // Handle AJAX requests from admin
        add_action( 'wp_ajax_unmam_start_background_scan', array( $this, 'ajax_start_scan' ) );
        add_action( 'wp_ajax_unmam_pause_scan', array( $this, 'ajax_pause_scan' ) );
        add_action( 'wp_ajax_unmam_resume_scan', array( $this, 'ajax_resume_scan' ) );
        add_action( 'wp_ajax_unmam_stop_scan', array( $this, 'ajax_stop_scan' ) );
        add_action( 'wp_ajax_unmam_get_scan_status', array( $this, 'ajax_get_scan_status' ) );

        // Frontend-driven batch processing endpoint
        add_action( 'wp_ajax_unmam_process_scan_batch', array( $this, 'ajax_process_scan_batch' ) );
    }

    /**
     * Add custom cron interval (every minute) - fallback
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function add_cron_interval( $schedules ) {
        $schedules[ self::CRON_INTERVAL ] = array(
            'interval' => 60, // Every minute
            'display'  => __( 'Every Minute', 'unattached-media-manager' ),
        );
        return $schedules;
    }

    /**
     * Generate or get the process key for async requests
     *
     * @return string
     */
    private function get_process_key() {
        $key = get_option( self::OPTION_PROCESS_KEY );
        if ( ! $key ) {
            $key = wp_generate_password( 32, false );
            update_option( self::OPTION_PROCESS_KEY, $key );
        }
        return $key;
    }

    /**
     * Dispatch an async processing request (loopback)
     *
     * This makes a non-blocking HTTP request to the site itself,
     * which continues processing even after the browser is closed.
     */
    private function dispatch_async_request() {
        $url = add_query_arg(
            array(
                'action' => 'unmam_async_process',
                'key'    => $this->get_process_key(),
            ),
            admin_url( 'admin-ajax.php' )
        );

        $args = array(
            'timeout'   => 0.01, // Non-blocking - don't wait for response
            'blocking'  => false,
            'cookies'   => array(), // Don't send cookies
            'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
            'headers'   => array(
                'Cache-Control' => 'no-cache',
            ),
        );

        wp_remote_post( $url, $args );
    }

    /**
     * Handle async processing request (loopback endpoint)
     *
     * This is called via HTTP request and processes batches independently.
     */
    public function handle_async_request() {
        // Verify the secret key (used instead of nonce for async loopback requests)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Using secret key verification for async requests
        $provided_key = isset( $_REQUEST['key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : '';
        if ( ! hash_equals( $this->get_process_key(), $provided_key ) ) {
            wp_die( 'Unauthorized', 'Unauthorized', array( 'response' => 401 ) );
        }

        // Increase limits for background processing
        if ( function_exists( 'ignore_user_abort' ) ) {
            ignore_user_abort( true );
        }

        // Prevent caching
        nocache_headers();

        // Start processing
        $this->process_batch();

        // Check if more work remains and dispatch another request
        $state = $this->get_scan_state();
        if ( self::STATE_RUNNING === $state['status'] && ! get_option( self::OPTION_SCAN_PAUSED ) ) {
            // Small delay before next dispatch
            usleep( 500000 ); // 0.5 seconds
            $this->dispatch_async_request();
        }

        wp_die();
    }

    /**
     * Try to acquire a process lock
     *
     * Prevents multiple processes from running simultaneously.
     *
     * @return bool True if lock acquired.
     */
    private function acquire_lock() {
        $lock = get_option( self::OPTION_PROCESS_LOCK );

        // Check if lock exists and is still valid
        if ( $lock && $lock > time() ) {
            return false; // Another process is running
        }

        // Set lock with timeout
        $lock_time = time() + self::LOCK_TIMEOUT;
        update_option( self::OPTION_PROCESS_LOCK, $lock_time );

        return true;
    }

    /**
     * Release the process lock
     */
    private function release_lock() {
        delete_option( self::OPTION_PROCESS_LOCK );
    }

    /**
     * Start a new background scan
     *
     * @param bool $reset Whether to reset existing progress.
     * @return array Result with status.
     */
    public function start_scan( $reset = true ) {
        // Check if already running
        $state = $this->get_scan_state();
        if ( self::STATE_RUNNING === $state['status'] ) {
            return array(
                'success' => false,
                'message' => __( 'A scan is already running.', 'unattached-media-manager' ),
            );
        }

        // Initialize scanner
        $scanner = UNMAM_Scanner::instance();
        $result = $scanner->full_scan( $reset );

        // Update state
        $this->update_scan_state( array(
            'status'       => self::STATE_RUNNING,
            'current_type' => 'posts',
            'started_at'   => current_time( 'mysql' ),
            'total_items'  => $result['total'],
        ) );

        // Clear paused flag and lock
        delete_option( self::OPTION_SCAN_PAUSED );
        delete_option( self::OPTION_PROCESS_LOCK );

        // Schedule cron as fallback (in case user closes the page)
        $this->schedule_cron();

        // Note: Processing is now driven by the frontend via ajax_process_scan_batch
        // The frontend will start calling the batch endpoint immediately after this returns

        return array(
            'success' => true,
            'message' => __( 'Scan started. Processing...', 'unattached-media-manager' ),
            'total'   => $result['total'],
        );
    }

    /**
     * Pause the scan
     *
     * @return array
     */
    public function pause_scan() {
        $state = $this->get_scan_state();

        if ( self::STATE_RUNNING !== $state['status'] ) {
            return array(
                'success' => false,
                'message' => __( 'No scan is currently running.', 'unattached-media-manager' ),
            );
        }

        // Set paused flag
        update_option( self::OPTION_SCAN_PAUSED, true );

        // Update state
        $this->update_scan_state( array(
            'status'    => self::STATE_PAUSED,
            'paused_at' => current_time( 'mysql' ),
        ) );

        // Release lock and unschedule cron
        $this->release_lock();
        $this->unschedule_cron();

        return array(
            'success' => true,
            'message' => __( 'Scan paused.', 'unattached-media-manager' ),
        );
    }

    /**
     * Resume a paused scan
     *
     * @return array
     */
    public function resume_scan() {
        $state = $this->get_scan_state();

        if ( self::STATE_PAUSED !== $state['status'] ) {
            return array(
                'success' => false,
                'message' => __( 'No paused scan to resume.', 'unattached-media-manager' ),
            );
        }

        // Clear paused flag and lock
        delete_option( self::OPTION_SCAN_PAUSED );
        delete_option( self::OPTION_PROCESS_LOCK );

        // Update state
        $this->update_scan_state( array(
            'status'     => self::STATE_RUNNING,
            'resumed_at' => current_time( 'mysql' ),
        ) );

        // Schedule cron as fallback
        $this->schedule_cron();

        // Note: Frontend will start calling batch endpoint again

        return array(
            'success' => true,
            'message' => __( 'Scan resumed. Processing...', 'unattached-media-manager' ),
        );
    }

    /**
     * Stop/Cancel the scan completely (clears all progress)
     *
     * @return array
     */
    public function stop_scan() {
        // Unschedule cron and release lock
        $this->unschedule_cron();
        $this->release_lock();

        // Clear paused flag
        delete_option( self::OPTION_SCAN_PAUSED );

        // Reset state to idle
        $this->update_scan_state( array(
            'status'       => self::STATE_IDLE,
            'current_type' => 'posts',
            'started_at'   => null,
            'total_items'  => 0,
            'stopped_at'   => current_time( 'mysql' ),
        ) );

        // Clear scanner progress
        UNMAM_Database::reset_scan_progress();

        return array(
            'success' => true,
            'message' => __( 'Scan cancelled. You can start a new scan anytime.', 'unattached-media-manager' ),
        );
    }

    /**
     * Process a batch (called by cron or async request)
     *
     * @param int $start_time Optional start time for tracking execution.
     */
    public function process_batch( $start_time = null ) {
        // Track start time
        if ( null === $start_time ) {
            $start_time = time();
        }

        // Check if paused
        if ( get_option( self::OPTION_SCAN_PAUSED ) ) {
            return;
        }

        $state = $this->get_scan_state();

        // Check if we should be running
        if ( self::STATE_RUNNING !== $state['status'] ) {
            $this->unschedule_cron();
            return;
        }

        // Try to acquire lock (prevents duplicate processing)
        if ( ! $this->acquire_lock() ) {
            return; // Another process is already running
        }

        // Get current scan type
        $current_type = isset( $state['current_type'] ) ? $state['current_type'] : 'posts';

        // Run the batch
        $scanner = UNMAM_Scanner::instance();
        $result = $scanner->run_batch( $current_type );

        // Update state based on result
        if ( 'completed' === $result['status'] ) {
            // Move to next type
            $next_type = $this->get_next_scan_type( $current_type );

            if ( $next_type ) {
                $this->update_scan_state( array(
                    'current_type' => $next_type,
                ) );

                // Continue processing if time allows
                if ( ! $this->should_wait() && ! $this->should_stop_processing( $start_time ) ) {
                    $this->process_batch( $start_time );
                }
            } else {
                // All done!
                $this->update_scan_state( array(
                    'status'       => self::STATE_COMPLETED,
                    'completed_at' => current_time( 'mysql' ),
                ) );
                $this->unschedule_cron();
                $this->release_lock();
            }
        } else {
            // Update progress
            $this->update_scan_state( array(
                'last_batch_at'  => current_time( 'mysql' ),
                'last_processed' => $result['processed'],
            ) );

            // Continue processing if time and resources allow
            if ( ! $this->should_wait() && ! $this->should_stop_processing( $start_time ) ) {
                usleep( 50000 ); // 50ms delay
                $this->process_batch( $start_time );
            }
        }

        // Refresh lock if we're continuing
        $state = $this->get_scan_state();
        if ( self::STATE_RUNNING === $state['status'] ) {
            update_option( self::OPTION_PROCESS_LOCK, time() + self::LOCK_TIMEOUT );
        } else {
            $this->release_lock();
        }
    }

    /**
     * Check if we should stop processing due to time limit
     *
     * @param int $start_time When processing started.
     * @return bool
     */
    private function should_stop_processing( $start_time ) {
        return ( time() - $start_time ) >= self::MAX_EXECUTION_TIME;
    }

    /**
     * Check if we should wait before next batch
     *
     * @return bool
     */
    private function should_wait() {
        $resource_monitor = UNMAM_Resource_Monitor::instance();
        return $resource_monitor->should_pause();
    }

    /**
     * Get the next scan type after current
     *
     * @param string $current Current scan type.
     * @return string|null Next type or null if done.
     */
    private function get_next_scan_type( $current ) {
        // Single source of truth for the scan-type chain. When the admin has not
        // configured any custom tables this returns exactly the historical
        // posts/options/widgets sequence, so behavior is unchanged.
        $types = UNMAM_Scanner::get_active_scan_types();
        $index = array_search( $current, $types, true );

        if ( false === $index || $index >= count( $types ) - 1 ) {
            return null;
        }

        return $types[ $index + 1 ];
    }

    /**
     * Schedule cron job (fallback)
     */
    private function schedule_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
        }
    }

    /**
     * Unschedule cron job
     */
    private function unschedule_cron() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * Get current scan state
     *
     * @return array
     */
    public function get_scan_state() {
        $default = array(
            'status'       => self::STATE_IDLE,
            'current_type' => 'posts',
            'started_at'   => null,
            'total_items'  => 0,
        );

        $state = get_option( self::OPTION_SCAN_STATE, $default );
        return wp_parse_args( $state, $default );
    }

    /**
     * Update scan state
     *
     * @param array $data Data to update.
     */
    private function update_scan_state( $data ) {
        $state = $this->get_scan_state();
        $state = array_merge( $state, $data );
        update_option( self::OPTION_SCAN_STATE, $state );
    }

    /**
     * Get full status for UI
     *
     * @return array
     */
    public function get_full_status() {
        $state = $this->get_scan_state();
        $scanner = UNMAM_Scanner::instance();
        $scan_status = $scanner->get_scan_status();
        $resource_monitor = UNMAM_Resource_Monitor::instance();

        return array(
            'state'           => $state,
            'scan_progress'   => $scan_status,
            'is_cron_active'  => (bool) wp_next_scheduled( self::CRON_HOOK ),
            'next_cron_run'   => wp_next_scheduled( self::CRON_HOOK ),
            'resource_status' => $resource_monitor->get_status(),
            'is_processing'   => $this->is_processing(),
        );
    }

    /**
     * Check if a process is currently running
     *
     * @return bool
     */
    public function is_processing() {
        $lock = get_option( self::OPTION_PROCESS_LOCK );
        return $lock && $lock > time();
    }

    /**
     * AJAX: Start background scan
     */
    public function ajax_start_scan() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'unattached-media-manager' ) );
        }

        $result = $this->start_scan( true );

        if ( $result['success'] ) {
            wp_send_json_success( array_merge( $result, $this->get_full_status() ) );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    /**
     * AJAX: Pause scan
     */
    public function ajax_pause_scan() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'unattached-media-manager' ) );
        }

        $result = $this->pause_scan();

        if ( $result['success'] ) {
            wp_send_json_success( array_merge( $result, $this->get_full_status() ) );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    /**
     * AJAX: Resume scan
     */
    public function ajax_resume_scan() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'unattached-media-manager' ) );
        }

        $result = $this->resume_scan();

        if ( $result['success'] ) {
            wp_send_json_success( array_merge( $result, $this->get_full_status() ) );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    /**
     * AJAX: Stop scan
     */
    public function ajax_stop_scan() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'unattached-media-manager' ) );
        }

        $result = $this->stop_scan();

        if ( $result['success'] ) {
            wp_send_json_success( array_merge( $result, $this->get_full_status() ) );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    /**
     * AJAX: Get scan status
     *
     * Returns current scan status without processing.
     */
    public function ajax_get_scan_status() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'unattached-media-manager' ) );
        }

        wp_send_json_success( $this->get_full_status() );
    }

    /**
     * AJAX: Process a single batch (frontend-driven approach)
     *
     * This endpoint is called repeatedly by JavaScript to process batches.
     * Much more reliable than loopback HTTP or WP-Cron because the browser
     * drives the process directly.
     */
    public function ajax_process_scan_batch() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'unattached-media-manager' ) );
        }

        $state = $this->get_scan_state();

        // Check if scan is paused or not running
        if ( self::STATE_RUNNING !== $state['status'] ) {
            wp_send_json_success( array(
                'finished'     => true,
                'reason'       => 'not_running',
                'state'        => $state,
                'scan_progress' => UNMAM_Scanner::instance()->get_scan_status(),
            ) );
        }

        if ( get_option( self::OPTION_SCAN_PAUSED ) ) {
            wp_send_json_success( array(
                'finished'     => false,
                'reason'       => 'paused',
                'state'        => $state,
                'scan_progress' => UNMAM_Scanner::instance()->get_scan_status(),
            ) );
        }

        // Get current scan type
        $current_type = isset( $state['current_type'] ) ? $state['current_type'] : 'posts';

        // Process ONE batch
        $scanner = UNMAM_Scanner::instance();
        $result  = $scanner->run_batch( $current_type );

        // Check if this type is completed
        if ( 'completed' === $result['status'] ) {
            // Move to next type
            $next_type = $this->get_next_scan_type( $current_type );

            if ( $next_type ) {
                // More types to process
                $this->update_scan_state( array(
                    'current_type' => $next_type,
                ) );

                wp_send_json_success( array(
                    'finished'       => false,
                    'reason'         => 'type_completed',
                    'completed_type' => $current_type,
                    'next_type'      => $next_type,
                    'state'          => $this->get_scan_state(),
                    'scan_progress'  => $scanner->get_scan_status(),
                ) );
            } else {
                // ALL DONE!
                $this->update_scan_state( array(
                    'status'       => self::STATE_COMPLETED,
                    'completed_at' => current_time( 'mysql' ),
                ) );
                $this->unschedule_cron();
                $this->release_lock();

                wp_send_json_success( array(
                    'finished'      => true,
                    'reason'        => 'scan_completed',
                    'state'         => $this->get_scan_state(),
                    'scan_progress' => $scanner->get_scan_status(),
                ) );
            }
        } else {
            // More batches in this type
            $this->update_scan_state( array(
                'last_batch_at'  => current_time( 'mysql' ),
                'last_processed' => $result['processed'],
            ) );

            wp_send_json_success( array(
                'finished'      => false,
                'reason'        => 'batch_processed',
                'processed'     => $result['processed'],
                'current_type'  => $current_type,
                'state'         => $this->get_scan_state(),
                'scan_progress' => $scanner->get_scan_status(),
            ) );
        }
    }
}
