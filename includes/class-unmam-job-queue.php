<?php
/**
 * Job Queue for Media Usage Inspector
 *
 * Handles all background jobs (trash, delete, restore, attach, revert)
 * using WP Cron so operations continue even when browser is closed.
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Job Queue class
 */
class UNMAM_Job_Queue {

    /**
     * Single instance
     *
     * @var UNMAM_Job_Queue|null
     */
    private static $instance = null;

    /**
     * Cron hook name for job processing
     */
    const CRON_HOOK = 'unmam_process_job';

    /**
     * Cron interval name
     */
    const CRON_INTERVAL = 'unmam_job_interval';

    /**
     * Option key for job state
     */
    const OPTION_JOB_STATE = 'unmam_job_state';

    /**
     * Job types
     */
    const JOB_TRASH        = 'trash';
    const JOB_RESTORE      = 'restore';
    const JOB_DELETE       = 'delete';
    const JOB_ATTACH       = 'attach';
    const JOB_REVERT       = 'revert';
    const JOB_EMPTY_TRASH  = 'empty_trash';

    /**
     * Job statuses
     */
    const STATUS_PENDING   = 'pending';
    const STATUS_RUNNING   = 'running';
    const STATUS_PAUSED    = 'paused';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Batch sizes by resource mode
     */
    const BATCH_SIZES = array(
        'low'  => 5,
        'auto' => 15,
        'high' => 50,
    );

    /**
     * Cron intervals by resource mode (in seconds)
     */
    const CRON_INTERVALS = array(
        'low'  => 120, // 2 minutes
        'auto' => 60,  // 1 minute
        'high' => 30,  // 30 seconds
    );

    /**
     * Get singleton instance
     *
     * @return UNMAM_Job_Queue
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
        // Register custom cron interval based on resource mode
        add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );

        // Register cron handler
        add_action( self::CRON_HOOK, array( $this, 'process_job' ) );

        // AJAX handlers for job management
        add_action( 'wp_ajax_unmam_start_job', array( $this, 'ajax_start_job' ) );
        add_action( 'wp_ajax_unmam_stop_job', array( $this, 'ajax_stop_job' ) );
        add_action( 'wp_ajax_unmam_get_job_status', array( $this, 'ajax_get_job_status' ) );
        add_action( 'wp_ajax_unmam_pause_job', array( $this, 'ajax_pause_job' ) );
        add_action( 'wp_ajax_unmam_resume_job', array( $this, 'ajax_resume_job' ) );

        // Frontend-driven batch processing endpoint
        add_action( 'wp_ajax_unmam_process_job_batch', array( $this, 'ajax_process_job_batch' ) );
    }

    /**
     * Add custom cron interval based on resource mode
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function add_cron_interval( $schedules ) {
        $interval = $this->get_cron_interval();

        $schedules[ self::CRON_INTERVAL ] = array(
            'interval' => $interval,
            /* translators: %d: number of seconds */
            'display'  => sprintf( __( 'Every %d seconds', 'unattached-media-manager' ), $interval ),
        );
        return $schedules;
    }

    /**
     * Get cron interval based on resource mode
     *
     * @return int Interval in seconds.
     */
    private function get_cron_interval() {
        $mode = $this->get_resource_mode();
        return isset( self::CRON_INTERVALS[ $mode ] ) ? self::CRON_INTERVALS[ $mode ] : 60;
    }

    /**
     * Get batch size based on resource mode
     *
     * @return int Batch size.
     */
    private function get_batch_size() {
        $mode = $this->get_resource_mode();
        return isset( self::BATCH_SIZES[ $mode ] ) ? self::BATCH_SIZES[ $mode ] : 15;
    }

    /**
     * Get current resource mode
     *
     * @return string
     */
    private function get_resource_mode() {
        $settings = Unattached_Media_Manager::get_setting();
        return isset( $settings['resource_mode'] ) ? $settings['resource_mode'] : 'auto';
    }

    /**
     * Create a new job
     *
     * @param string $job_type Type of job (trash, restore, delete, attach, revert).
     * @param array  $item_ids Array of item IDs to process.
     * @param array  $meta     Optional metadata for the job.
     * @return array|WP_Error Job state or error.
     */
    public function create_job( $job_type, $item_ids, $meta = array() ) {
        // Check if a job is already running
        $current_job = $this->get_job_state();
        if ( self::STATUS_RUNNING === $current_job['status'] || self::STATUS_PAUSED === $current_job['status'] ) {
            return new WP_Error(
                'job_running',
                __( 'A job is already in progress. Please wait for it to complete or stop it first.', 'unattached-media-manager' )
            );
        }

        // Validate job type
        $valid_types = array( self::JOB_TRASH, self::JOB_RESTORE, self::JOB_DELETE, self::JOB_ATTACH, self::JOB_REVERT, self::JOB_EMPTY_TRASH );
        if ( ! in_array( $job_type, $valid_types, true ) ) {
            return new WP_Error( 'invalid_job_type', __( 'Invalid job type.', 'unattached-media-manager' ) );
        }

        // Create job state
        $job_state = array(
            'job_id'           => uniqid( 'unmam_job_' ),
            'job_type'         => $job_type,
            'status'           => self::STATUS_RUNNING,
            'items'            => array_values( array_unique( array_map( 'intval', $item_ids ) ) ),
            'total_items'      => count( $item_ids ),
            'processed_items'  => 0,
            'successful_items' => 0,
            'failed_items'     => 0,
            'current_index'    => 0,
            'errors'           => array(),
            'meta'             => $meta,
            'started_at'       => current_time( 'mysql' ),
            'updated_at'       => current_time( 'mysql' ),
            'completed_at'     => null,
        );

        $this->save_job_state( $job_state );

        // Schedule cron as fallback (in case user closes the page)
        $this->schedule_cron();

        // Note: Processing is now driven by the frontend via ajax_process_job_batch
        // The frontend will start calling the batch endpoint immediately after this returns

        return $job_state;
    }

    /**
     * Process job batch (called by cron)
     */
    public function process_job() {
        $job = $this->get_job_state();

        // Check if we have a running job
        if ( ! in_array( $job['status'], array( self::STATUS_RUNNING ), true ) ) {
            $this->unschedule_cron();
            return;
        }

        // Check resource constraints
        $resource_monitor = UNMAM_Resource_Monitor::instance();
        $resource_monitor->start_batch();

        if ( $resource_monitor->should_pause() ) {
            // Resources constrained, will retry on next cron run
            return;
        }

        $batch_size    = $this->get_batch_size();
        $items         = $job['items'];
        $current_index = $job['current_index'];
        $processed     = 0;

        // Process batch
        for ( $i = 0; $i < $batch_size && ( $current_index + $i ) < count( $items ); $i++ ) {
            // Check if resources are still okay
            if ( $resource_monitor->should_pause() ) {
                break;
            }

            $item_id = $items[ $current_index + $i ];
            $result  = $this->process_single_item( $job['job_type'], $item_id, $job['meta'] );

            if ( is_wp_error( $result ) ) {
                $job['failed_items']++;
                $job['errors'][] = array(
                    'item_id' => $item_id,
                    'error'   => $result->get_error_message(),
                );
            } else {
                $job['successful_items']++;
            }

            $processed++;
        }

        // Update job state
        $job['current_index']   += $processed;
        $job['processed_items'] += $processed;
        $job['updated_at']       = current_time( 'mysql' );

        // Check if job is complete
        if ( $job['current_index'] >= count( $items ) ) {
            $job['status']       = self::STATUS_COMPLETED;
            $job['completed_at'] = current_time( 'mysql' );
            $this->unschedule_cron();
        }

        $this->save_job_state( $job );
        $resource_monitor->end_batch( $processed );

        // If not complete and we have time, process another batch
        if ( self::STATUS_RUNNING === $job['status'] && ! $resource_monitor->should_pause() && ! wp_doing_cron() ) {
            // Small delay between batches
            $mode  = $this->get_resource_mode();
            $delay = 'low' === $mode ? 500000 : ( 'high' === $mode ? 50000 : 100000 ); // microseconds
            usleep( $delay );
            $this->process_job();
        }
    }

    /**
     * Process a single item based on job type
     *
     * @param string $job_type Type of job.
     * @param int    $item_id  Item ID.
     * @param array  $meta     Job metadata.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    private function process_single_item( $job_type, $item_id, $meta = array() ) {
        switch ( $job_type ) {
            case self::JOB_TRASH:
                return UNMAM_Database::trash_attachment( $item_id );

            case self::JOB_RESTORE:
                return UNMAM_Database::restore_attachment( $item_id );

            case self::JOB_DELETE:
            case self::JOB_EMPTY_TRASH:
                return UNMAM_Database::delete_attachment_permanently( $item_id );

            case self::JOB_ATTACH:
                return $this->process_attach_item( $item_id, $meta );

            case self::JOB_REVERT:
                return UNMAM_History::revert_change( $item_id );

            default:
                return new WP_Error( 'invalid_job_type', __( 'Invalid job type.', 'unattached-media-manager' ) );
        }
    }

    /**
     * Process attach item
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $meta          Job metadata.
     * @return bool|WP_Error
     */
    private function process_attach_item( $attachment_id, $meta = array() ) {
        // Get suggested parent from references
        global $wpdb;
        $table = UNMAM_Database::get_table_name( 'references' );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safely generated
        $suggested_parent = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MIN(source_id)
                FROM {$table}
                WHERE attachment_id = %d
                AND source_id > 0
                AND source_type = 'post'",
                $attachment_id
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( ! $suggested_parent ) {
            return new WP_Error( 'no_parent', __( 'No suitable parent found for attachment.', 'unattached-media-manager' ) );
        }

        $scan_id       = isset( $meta['scan_id'] ) ? $meta['scan_id'] : 'bulk_' . gmdate( 'Y-m-d_H-i-s' );
        $context_type  = isset( $meta['context_type'] ) ? $meta['context_type'] : 'bulk_attach';
        $context_label = isset( $meta['context_label'] ) ? $meta['context_label'] : __( 'Bulk Attachment', 'unattached-media-manager' );

        return UNMAM_Database::attach_media_with_history(
            $attachment_id,
            $suggested_parent,
            $context_type,
            $context_label,
            $scan_id
        );
    }

    /**
     * Stop the current job
     *
     * @return array Updated job state.
     */
    public function stop_job() {
        $job = $this->get_job_state();

        if ( ! in_array( $job['status'], array( self::STATUS_RUNNING, self::STATUS_PAUSED ), true ) ) {
            return $job;
        }

        $job['status']       = self::STATUS_CANCELLED;
        $job['completed_at'] = current_time( 'mysql' );

        $this->save_job_state( $job );
        $this->unschedule_cron();

        return $job;
    }

    /**
     * Pause the current job
     *
     * @return array Updated job state.
     */
    public function pause_job() {
        $job = $this->get_job_state();

        if ( self::STATUS_RUNNING !== $job['status'] ) {
            return $job;
        }

        $job['status']     = self::STATUS_PAUSED;
        $job['paused_at']  = current_time( 'mysql' );
        $job['updated_at'] = current_time( 'mysql' );

        $this->save_job_state( $job );
        $this->unschedule_cron();

        return $job;
    }

    /**
     * Resume a paused job
     *
     * @return array Updated job state.
     */
    public function resume_job() {
        $job = $this->get_job_state();

        if ( self::STATUS_PAUSED !== $job['status'] ) {
            return $job;
        }

        $job['status']     = self::STATUS_RUNNING;
        $job['resumed_at'] = current_time( 'mysql' );
        $job['updated_at'] = current_time( 'mysql' );

        $this->save_job_state( $job );
        $this->schedule_cron();

        // Immediately trigger processing
        $this->process_job();

        return $job;
    }

    /**
     * Get current job state
     *
     * @return array
     */
    public function get_job_state() {
        $default = array(
            'job_id'           => null,
            'job_type'         => null,
            'status'           => self::STATUS_COMPLETED,
            'items'            => array(),
            'total_items'      => 0,
            'processed_items'  => 0,
            'successful_items' => 0,
            'failed_items'     => 0,
            'current_index'    => 0,
            'errors'           => array(),
            'meta'             => array(),
            'started_at'       => null,
            'updated_at'       => null,
            'completed_at'     => null,
        );

        $state = get_option( self::OPTION_JOB_STATE, $default );
        return wp_parse_args( $state, $default );
    }

    /**
     * Save job state
     *
     * @param array $state Job state.
     */
    private function save_job_state( $state ) {
        update_option( self::OPTION_JOB_STATE, $state, false );
    }

    /**
     * Clear job state
     */
    public function clear_job_state() {
        delete_option( self::OPTION_JOB_STATE );
        $this->unschedule_cron();
    }

    /**
     * Schedule cron job
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
     * Get job type label
     *
     * @param string $job_type Job type.
     * @return string
     */
    public function get_job_type_label( $job_type ) {
        $labels = array(
            self::JOB_TRASH       => __( 'Moving to Trash', 'unattached-media-manager' ),
            self::JOB_RESTORE     => __( 'Restoring from Trash', 'unattached-media-manager' ),
            self::JOB_DELETE      => __( 'Permanently Deleting', 'unattached-media-manager' ),
            self::JOB_ATTACH      => __( 'Attaching Media', 'unattached-media-manager' ),
            self::JOB_REVERT      => __( 'Reverting Changes', 'unattached-media-manager' ),
            self::JOB_EMPTY_TRASH => __( 'Emptying Trash', 'unattached-media-manager' ),
        );

        return isset( $labels[ $job_type ] ) ? $labels[ $job_type ] : __( 'Processing', 'unattached-media-manager' );
    }

    /**
     * Get full job status for UI
     *
     * @return array
     */
    public function get_full_status() {
        $job = $this->get_job_state();
        $resource_monitor = UNMAM_Resource_Monitor::instance();

        $percentage = 0;
        if ( $job['total_items'] > 0 ) {
            $percentage = round( ( $job['processed_items'] / $job['total_items'] ) * 100, 1 );
        }

        // Get the processing mode stored with the job, fallback to global setting
        $job_processing_mode = isset( $job['meta']['processing_mode'] ) ? $job['meta']['processing_mode'] : '';
        if ( empty( $job_processing_mode ) ) {
            $job_processing_mode = Unattached_Media_Manager::get_setting( 'processing_mode' );
        }
        if ( empty( $job_processing_mode ) ) {
            $job_processing_mode = 'frontend'; // Default to frontend
        }

        return array(
            'job'              => $job,
            'job_type_label'   => $this->get_job_type_label( $job['job_type'] ),
            'percentage'       => $percentage,
            'processing_mode'  => $job_processing_mode,
            'is_cron_active'   => (bool) wp_next_scheduled( self::CRON_HOOK ),
            'next_cron_run'    => wp_next_scheduled( self::CRON_HOOK ),
            'resource_mode'    => $this->get_resource_mode(),
            'batch_size'       => $this->get_batch_size(),
            'resource_status'  => $resource_monitor->get_status(),
            'counts'           => array(
                'unused_count'     => UNMAM_Database::get_unused_count(),
                'trash_count'      => UNMAM_Database::get_trashed_count(),
                'unattached_count' => UNMAM_Database::get_used_but_unattached_count(),
            ),
        );
    }

    /**
     * Check if a job is currently active
     *
     * @return bool
     */
    public function is_job_active() {
        $job = $this->get_job_state();
        return in_array( $job['status'], array( self::STATUS_RUNNING, self::STATUS_PAUSED ), true );
    }

    /**
     * AJAX: Start a new job
     */
    public function ajax_start_job() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'unattached-media-manager' ) );
        }

        $job_type = isset( $_POST['job_type'] ) ? sanitize_text_field( wp_unslash( $_POST['job_type'] ) ) : '';
        $item_ids = isset( $_POST['item_ids'] ) ? array_map( 'intval', (array) $_POST['item_ids'] ) : array();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array is sanitized per-key below
        $meta     = isset( $_POST['meta'] ) ? map_deep( wp_unslash( $_POST['meta'] ), 'sanitize_text_field' ) : array();

        // Handle special case for empty trash
        if ( self::JOB_EMPTY_TRASH === $job_type ) {
            global $wpdb;
            $item_ids = $wpdb->get_col(
                "SELECT ID FROM {$wpdb->posts}
                WHERE post_type = 'attachment'
                AND post_status = 'trash'"
            );

            if ( empty( $item_ids ) ) {
                wp_send_json_error( __( 'Trash is already empty.', 'unattached-media-manager' ) );
            }
        }

        // Handle special case for attach all
        if ( self::JOB_ATTACH === $job_type && empty( $item_ids ) ) {
            $unattached = UNMAM_Database::get_used_but_unattached();
            $item_ids   = wp_list_pluck( $unattached, 'attachment_id' );

            if ( empty( $item_ids ) ) {
                wp_send_json_error( __( 'No unattached media found.', 'unattached-media-manager' ) );
            }

            $meta['scan_id'] = 'bulk_' . gmdate( 'Y-m-d_H-i-s' );
        }

        // Handle special case for trash all unused
        if ( self::JOB_TRASH === $job_type && empty( $item_ids ) ) {
            $unused = UNMAM_Database::get_all_unused_attachment_ids();
            $item_ids = $unused;

            if ( empty( $item_ids ) ) {
                wp_send_json_error( __( 'No unused media found.', 'unattached-media-manager' ) );
            }
        }

        // Handle special case for revert all active
        if ( self::JOB_REVERT === $job_type && empty( $item_ids ) ) {
            $active_ids = UNMAM_History::get_all_active_ids();
            $item_ids = $active_ids;

            if ( empty( $item_ids ) ) {
                wp_send_json_error( __( 'No active changes to revert.', 'unattached-media-manager' ) );
            }
        }

        if ( empty( $item_ids ) ) {
            wp_send_json_error( __( 'No items selected.', 'unattached-media-manager' ) );
        }

        // Get processing mode from request (or use the global setting)
        $processing_mode = isset( $_POST['processing_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['processing_mode'] ) ) : '';
        if ( empty( $processing_mode ) ) {
            $processing_mode = Unattached_Media_Manager::get_setting( 'processing_mode' );
        }
        if ( empty( $processing_mode ) ) {
            $processing_mode = 'frontend'; // Default to frontend
        }

        // Store processing mode in meta
        $meta['processing_mode'] = $processing_mode;

        $result = $this->create_job( $job_type, $item_ids, $meta );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array_merge(
            array( 'message' => __( 'Job started.', 'unattached-media-manager' ) ),
            $this->get_full_status()
        ) );
    }

    /**
     * AJAX: Stop current job
     */
    public function ajax_stop_job() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'unattached-media-manager' ) );
        }

        $this->stop_job();

        wp_send_json_success( array_merge(
            array( 'message' => __( 'Job stopped.', 'unattached-media-manager' ) ),
            $this->get_full_status()
        ) );
    }

    /**
     * AJAX: Pause current job
     */
    public function ajax_pause_job() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'unattached-media-manager' ) );
        }

        $this->pause_job();

        wp_send_json_success( array_merge(
            array( 'message' => __( 'Job paused.', 'unattached-media-manager' ) ),
            $this->get_full_status()
        ) );
    }

    /**
     * AJAX: Resume paused job
     */
    public function ajax_resume_job() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'unattached-media-manager' ) );
        }

        $this->resume_job();

        wp_send_json_success( array_merge(
            array( 'message' => __( 'Job resumed.', 'unattached-media-manager' ) ),
            $this->get_full_status()
        ) );
    }

    /**
     * AJAX: Get job status
     */
    public function ajax_get_job_status() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'unattached-media-manager' ) );
        }

        wp_send_json_success( $this->get_full_status() );
    }

    /**
     * AJAX: Process a single job batch (frontend-driven approach)
     *
     * This endpoint is called repeatedly by JavaScript to process batches.
     * Much more reliable than WP-Cron because the browser drives the process directly.
     */
    public function ajax_process_job_batch() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'unattached-media-manager' ) );
        }

        $job = $this->get_job_state();

        // Check if job is not running
        if ( self::STATUS_RUNNING !== $job['status'] ) {
            wp_send_json_success( array(
                'finished' => true,
                'reason'   => 'not_running',
                'status'   => $this->get_full_status(),
            ) );
        }

        // Check resource constraints
        $resource_monitor = UNMAM_Resource_Monitor::instance();
        $resource_monitor->start_batch();

        if ( $resource_monitor->should_pause() ) {
            wp_send_json_success( array(
                'finished' => false,
                'reason'   => 'resource_pause',
                'status'   => $this->get_full_status(),
            ) );
        }

        $batch_size    = $this->get_batch_size();
        $items         = $job['items'];
        $current_index = $job['current_index'];
        $processed     = 0;

        // Process batch
        for ( $i = 0; $i < $batch_size && ( $current_index + $i ) < count( $items ); $i++ ) {
            // Check if resources are still okay
            if ( $resource_monitor->should_pause() ) {
                break;
            }

            $item_id = $items[ $current_index + $i ];
            $result  = $this->process_single_item( $job['job_type'], $item_id, $job['meta'] );

            if ( is_wp_error( $result ) ) {
                $job['failed_items']++;
                $job['errors'][] = array(
                    'item_id' => $item_id,
                    'error'   => $result->get_error_message(),
                );
            } else {
                $job['successful_items']++;
            }

            $processed++;
        }

        // Update job state
        $job['current_index']   += $processed;
        $job['processed_items'] += $processed;
        $job['updated_at']       = current_time( 'mysql' );

        // Check if job is complete
        if ( $job['current_index'] >= count( $items ) ) {
            $job['status']       = self::STATUS_COMPLETED;
            $job['completed_at'] = current_time( 'mysql' );
            $this->unschedule_cron();

            $this->save_job_state( $job );
            $resource_monitor->end_batch( $processed );

            wp_send_json_success( array(
                'finished'  => true,
                'reason'    => 'job_completed',
                'processed' => $processed,
                'status'    => $this->get_full_status(),
            ) );
        }

        $this->save_job_state( $job );
        $resource_monitor->end_batch( $processed );

        // More work to do
        wp_send_json_success( array(
            'finished'  => false,
            'reason'    => 'batch_processed',
            'processed' => $processed,
            'status'    => $this->get_full_status(),
        ) );
    }
}
