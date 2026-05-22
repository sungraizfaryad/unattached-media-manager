<?php
/**
 * Admin class for All-in-One Media Solution
 *
 * @package AllInOneMediaSolution
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin class
 */
class UNMAM_Admin {

    /**
     * Single instance
     *
     * @var UNMAM_Admin|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return UNMAM_Admin
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
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        // Note: Scan status/start/pause/resume/stop handlers are in UNMAM_Background_Processor
        add_action( 'wp_ajax_unmam_get_statistics', array( $this, 'ajax_get_statistics' ) );
        add_action( 'wp_ajax_unmam_export_report', array( $this, 'ajax_export_report' ) );
        add_action( 'wp_ajax_unmam_attach_all_used', array( $this, 'ajax_attach_all_used' ) );
        add_action( 'wp_ajax_unmam_get_history', array( $this, 'ajax_get_history' ) );
        add_action( 'wp_ajax_unmam_revert_change', array( $this, 'ajax_revert_change' ) );
        add_action( 'wp_ajax_unmam_revert_bulk', array( $this, 'ajax_revert_bulk' ) );
        add_action( 'wp_ajax_unmam_attach_single', array( $this, 'ajax_attach_single' ) );
        // Single item operations (immediate, for single items)
        add_action( 'wp_ajax_unmam_trash_media', array( $this, 'ajax_trash_media' ) );
        add_action( 'wp_ajax_unmam_restore_media', array( $this, 'ajax_restore_media' ) );
        add_action( 'wp_ajax_unmam_delete_single', array( $this, 'ajax_delete_single' ) );
        // Bulk operations are now handled by the job queue - see UNMAM_Job_Queue class

        // Settings AJAX handler
        add_action( 'wp_ajax_unmam_save_processing_mode', array( $this, 'ajax_save_processing_mode' ) );

        // Add settings link
        add_filter( 'plugin_action_links_' . UNMAM_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_media_page(
            __( 'All-in-One Media Solution', 'unattached-media-manager' ),
            __( 'Media Solution', 'unattached-media-manager' ),
            'manage_options',
            'unattached-media-manager',
            array( $this, 'render_admin_page' )
        );
    }

    /**
     * Get the plugin page slug
     *
     * @return string
     */
    public static function get_page_slug() {
        return 'unattached-media-manager';
    }

    /**
     * Enqueue admin scripts
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_scripts( $hook ) {
        // Load on media pages and our plugin pages
        $allowed_hooks = array(
            'upload.php',
            'media_page_unattached-media-manager',
        );

        if ( ! in_array( $hook, $allowed_hooks, true ) && strpos( $hook, 'unattached-media-manager' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'unmam-admin',
            UNMAM_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            UNMAM_VERSION
        );

        wp_enqueue_script(
            'unmam-admin',
            UNMAM_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery', 'wp-util' ),
            UNMAM_VERSION,
            true
        );

        $settings = Unattached_Media_Manager::get_setting();
        $scan_status = UNMAM_Scanner::instance()->get_scan_status();
        $resource_monitor = UNMAM_Resource_Monitor::instance();
        $bg_processor = UNMAM_Background_Processor::instance();
        $job_queue = UNMAM_Job_Queue::instance();

        wp_localize_script( 'unmam-admin', 'unmamAdmin', array(
            'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'unmam_admin_nonce' ),
            'scanStatus'      => $scan_status,
            'backgroundState' => $bg_processor->get_scan_state(),
            'jobState'        => $job_queue->get_full_status(),
            'batchDelay'      => $resource_monitor->get_batch_delay(),
            'resourceMode'    => $resource_monitor->get_mode(),
            'processingMode'  => Unattached_Media_Manager::get_setting( 'processing_mode' ),
            'pollInterval'    => 3000, // Poll every 3 seconds
            'strings'         => array(
                'scanning'        => __( 'Scanning...', 'unattached-media-manager' ),
                'scanningBg'      => __( 'Scanning in Background', 'unattached-media-manager' ),
                'completed'       => __( 'Scan completed!', 'unattached-media-manager' ),
                'error'           => __( 'An error occurred.', 'unattached-media-manager' ),
                'confirmScan'     => __( 'This will re-scan your entire site. Continue?', 'unattached-media-manager' ),
                'noReferences'    => __( 'No references found', 'unattached-media-manager' ),
                'attaching'       => __( 'Attaching...', 'unattached-media-manager' ),
                'confirmAttach'   => __( 'This will attach all used media to the posts where they are found. Continue?', 'unattached-media-manager' ),
                /* translators: %d: number of media files attached */
                'attachComplete'  => __( 'Successfully attached %d media files.', 'unattached-media-manager' ),
                'resuming'        => __( 'Resuming scan...', 'unattached-media-manager' ),
                'paused'          => __( 'Scan Paused', 'unattached-media-manager' ),
                'pausing'         => __( 'Pausing...', 'unattached-media-manager' ),
                'stopping'        => __( 'Stopping...', 'unattached-media-manager' ),
                'startScan'       => __( 'Start Full Scan', 'unattached-media-manager' ),
                'indexUpToDate'   => __( 'Index Up to Date', 'unattached-media-manager' ),
                'notIndexed'      => __( 'Not Indexed', 'unattached-media-manager' ),
                /* translators: %s: name of item being scanned */
                'currentlyScanning' => __( 'Currently scanning: %s', 'unattached-media-manager' ),
                'reverting'       => __( 'Reverting...', 'unattached-media-manager' ),
                'revertSuccess'   => __( 'Successfully reverted.', 'unattached-media-manager' ),
                'revertError'     => __( 'Error reverting change.', 'unattached-media-manager' ),
                'confirmRevert'   => __( 'This will detach this media from its parent post. Continue?', 'unattached-media-manager' ),
                'confirmRevertBulk' => __( 'This will revert all selected changes. Continue?', 'unattached-media-manager' ),
                'noSelection'     => __( 'Please select at least one item.', 'unattached-media-manager' ),
                'trashing'        => __( 'Moving to trash...', 'unattached-media-manager' ),
                'trashSuccess'    => __( 'Moved to trash successfully.', 'unattached-media-manager' ),
                'trashError'      => __( 'Error moving to trash.', 'unattached-media-manager' ),
                'confirmTrash'    => __( 'Move this media file to trash?', 'unattached-media-manager' ),
                'confirmTrashBulk' => __( 'Move all selected media files to trash?', 'unattached-media-manager' ),
                'restoring'       => __( 'Restoring...', 'unattached-media-manager' ),
                'restoreSuccess'  => __( 'Restored successfully.', 'unattached-media-manager' ),
                'restoreError'    => __( 'Error restoring media.', 'unattached-media-manager' ),
                'confirmRestore'  => __( 'Restore this media file from trash?', 'unattached-media-manager' ),
                'confirmRestoreBulk' => __( 'Restore all selected media files from trash?', 'unattached-media-manager' ),
                'deleting'        => __( 'Deleting permanently...', 'unattached-media-manager' ),
                'deleteSuccess'   => __( 'Deleted permanently.', 'unattached-media-manager' ),
                'deleteError'     => __( 'Error deleting media.', 'unattached-media-manager' ),
                'confirmDelete'   => __( 'Permanently delete this media file? This cannot be undone!', 'unattached-media-manager' ),
                'confirmDeleteBulk' => __( 'Permanently delete all selected media files? This cannot be undone!', 'unattached-media-manager' ),
                'confirmEmptyTrash' => __( 'Permanently delete ALL trashed media files? This cannot be undone!', 'unattached-media-manager' ),
                'confirmTrashAllUnused' => __( 'Move ALL unused media files to trash? This will affect all unused media, not just what\'s visible on this page.', 'unattached-media-manager' ),
                'confirmRevertAll' => __( 'Revert ALL active attachment changes? This will detach all media that was attached by this plugin.', 'unattached-media-manager' ),
                'confirmAttachAll' => __( 'Attach ALL used but unattached media files? This will assign parent posts to all media files that are currently in use.', 'unattached-media-manager' ),
                'confirmAttachSelected' => __( 'Attach the selected media files to their suggested parent posts?', 'unattached-media-manager' ),
                'attachSuccess' => __( 'Media attached successfully.', 'unattached-media-manager' ),
                'attachError' => __( 'Error attaching media.', 'unattached-media-manager' ),
                // Job queue strings
                'jobStarted'      => __( 'Job started. Processing...', 'unattached-media-manager' ),
                'jobRunning'      => __( 'Processing', 'unattached-media-manager' ),
                'jobPaused'       => __( 'Job Paused', 'unattached-media-manager' ),
                'jobCompleted'    => __( 'Job Completed', 'unattached-media-manager' ),
                'jobCancelled'    => __( 'Job Cancelled', 'unattached-media-manager' ),
                'confirmStopJob'  => __( 'Are you sure you want to stop this operation? Progress will be saved.', 'unattached-media-manager' ),
                /* translators: %1$d: current item number, %2$d: total number of items */
                'processingItems' => __( 'Processing %1$d of %2$d items...', 'unattached-media-manager' ),
                /* translators: %d: number of items processed */
                'jobSuccess'      => __( 'Successfully processed %d items.', 'unattached-media-manager' ),
                /* translators: %d: number of items that failed */
                'jobErrors'       => __( '%d items failed.', 'unattached-media-manager' ),
                'stopProcess'     => __( 'Stop Process', 'unattached-media-manager' ),
                'pauseProcess'    => __( 'Pause', 'unattached-media-manager' ),
                'resumeProcess'   => __( 'Resume', 'unattached-media-manager' ),
                'refreshPage'     => __( 'Refresh Page', 'unattached-media-manager' ),
                'disabledDuringScan' => __( 'Disabled while scan is in progress', 'unattached-media-manager' ),
                'bulkDisabledDuringScan' => __( 'Bulk operations are disabled while a scan is in progress. Please wait for the scan to complete or cancel it.', 'unattached-media-manager' ),
                // Processing mode specific messages
                'jobNoteFrontend' => __( 'Keep this browser tab open. Processing will stop if you close it.', 'unattached-media-manager' ),
                'jobNoteBackground' => __( 'This process runs in the background. You can close this page and it will continue automatically.', 'unattached-media-manager' ),
                'scanNoteFrontend' => __( 'Keep this browser tab open while scanning. The scan will stop if you close it.', 'unattached-media-manager' ),
                'scanNoteBackground' => __( 'Scanning runs in the background using WordPress Cron. You can close your browser and the scan will continue automatically.', 'unattached-media-manager' ),
            ),
        ) );
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        // Determine current tab
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation, no action taken
        $current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

        // Handle settings save
        if ( 'settings' === $current_tab && isset( $_POST['unmam_save_settings'] ) && check_admin_referer( 'unmam_settings_nonce' ) ) {
            $this->save_settings();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'unattached-media-manager' ) . '</p></div>';
        }

        $statistics = UNMAM_Database::get_statistics();
        $scan_status = UNMAM_Scanner::instance()->get_scan_status();
        $bg_processor = UNMAM_Background_Processor::instance();
        $bg_state = $bg_processor->get_scan_state();
        $job_queue = UNMAM_Job_Queue::instance();
        $job_state = $job_queue->get_job_state();
        ?>
        <div class="wrap mui-admin-wrap">
            <h1><?php esc_html_e( 'All-in-One Media Solution', 'unattached-media-manager' ); ?></h1>

            <!-- Global Job Status Bar (visible when a job is running) -->
            <?php if ( in_array( $job_state['status'], array( 'running', 'paused' ), true ) ) : ?>
            <?php $this->render_job_status_bar( $job_state ); ?>
            <?php endif; ?>

            <!-- Tabs -->
            <?php
            $history_stats = UNMAM_History::get_stats();
            $unused_count = UNMAM_Database::get_unused_count();
            $trash_count = UNMAM_Database::get_trashed_count();
            $unattached_count = $statistics['used_but_unattached'] ?? 0;
            ?>
            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( admin_url( 'upload.php?page=unattached-media-manager&tab=dashboard' ) ); ?>"
                   class="nav-tab <?php echo 'dashboard' === $current_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Dashboard', 'unattached-media-manager' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'upload.php?page=unattached-media-manager&tab=unattached' ) ); ?>"
                   class="nav-tab <?php echo 'unattached' === $current_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Unattached Media', 'unattached-media-manager' ); ?>
                    <?php if ( $unattached_count > 0 ) : ?>
                        <span class="mui-tab-badge mui-badge-info"><?php echo esc_html( $unattached_count ); ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'upload.php?page=unattached-media-manager&tab=unused' ) ); ?>"
                   class="nav-tab <?php echo 'unused' === $current_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Unused Media', 'unattached-media-manager' ); ?>
                    <?php if ( $unused_count > 0 ) : ?>
                        <span class="mui-tab-badge mui-badge-warning"><?php echo esc_html( $unused_count ); ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'upload.php?page=unattached-media-manager&tab=history' ) ); ?>"
                   class="nav-tab <?php echo 'history' === $current_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Change History', 'unattached-media-manager' ); ?>
                    <?php if ( $history_stats['active_changes'] > 0 ) : ?>
                        <span class="mui-tab-badge"><?php echo esc_html( $history_stats['active_changes'] ); ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'upload.php?page=unattached-media-manager&tab=settings' ) ); ?>"
                   class="nav-tab <?php echo 'settings' === $current_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Settings', 'unattached-media-manager' ); ?>
                </a>
            </nav>

            <div class="mui-tab-content" style="margin-top: 20px;">
            <?php if ( 'settings' === $current_tab ) : ?>
                <?php $this->render_settings_content(); ?>
            <?php elseif ( 'history' === $current_tab ) : ?>
                <?php $this->render_history_content(); ?>
            <?php elseif ( 'unused' === $current_tab ) : ?>
                <?php $this->render_unused_content(); ?>
            <?php elseif ( 'unattached' === $current_tab ) : ?>
                <?php $this->render_unattached_content(); ?>
            <?php else : ?>
                <!-- Statistics Cards -->
                <div class="mui-stats-grid">
                <div class="mui-stat-card">
                    <span class="mui-stat-number"><?php echo esc_html( number_format( $statistics['total_attachments'] ) ); ?></span>
                    <span class="mui-stat-label"><?php esc_html_e( 'Total Media Files', 'unattached-media-manager' ); ?></span>
                </div>
                <div class="mui-stat-card mui-stat-success">
                    <span class="mui-stat-number"><?php echo esc_html( number_format( $statistics['referenced_attachments'] ) ); ?></span>
                    <span class="mui-stat-label"><?php esc_html_e( 'In Use', 'unattached-media-manager' ); ?></span>
                </div>
                <div class="mui-stat-card mui-stat-warning">
                    <span class="mui-stat-number"><?php echo esc_html( number_format( $statistics['unused_attachments'] ) ); ?></span>
                    <span class="mui-stat-label"><?php esc_html_e( 'Potentially Unused', 'unattached-media-manager' ); ?></span>
                </div>
                <div class="mui-stat-card mui-stat-info" id="mui-used-unattached-card">
                    <span class="mui-stat-number"><?php echo esc_html( number_format( $statistics['used_but_unattached'] ?? 0 ) ); ?></span>
                    <span class="mui-stat-label"><?php esc_html_e( 'Used but Unattached', 'unattached-media-manager' ); ?></span>
                </div>
                <div class="mui-stat-card">
                    <span class="mui-stat-number"><?php echo esc_html( number_format( $statistics['total_references'] ) ); ?></span>
                    <span class="mui-stat-label"><?php esc_html_e( 'Total References', 'unattached-media-manager' ); ?></span>
                </div>
            </div>

            <!-- Used but Unattached Action Panel (only show when not scanning) -->
            <?php $used_but_unattached = $statistics['used_but_unattached'] ?? 0; ?>
            <?php $scan_in_progress = in_array( $bg_state['status'], array( 'running', 'paused' ), true ); ?>
            <?php if ( $used_but_unattached > 0 && ! $scan_in_progress ) : ?>
            <div class="mui-panel mui-attach-panel" id="mui-attach-panel">
                <h2><?php esc_html_e( 'Fix Unattached Media', 'unattached-media-manager' ); ?></h2>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %d: number of media files */
                        esc_html__( 'Found %d media files that are in use but marked as "Unattached" in WordPress.', 'unattached-media-manager' ),
                        intval( $used_but_unattached )
                    );
                    ?>
                </p>
                <div class="mui-attach-actions" style="margin-top: 10px;">
                    <a href="<?php echo esc_url( admin_url( 'upload.php?page=unattached-media-manager&tab=unattached' ) ); ?>" class="button button-primary">
                        <?php esc_html_e( 'View & Manage Unattached Media', 'unattached-media-manager' ); ?>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Scan Controls -->
            <div class="mui-panel">
                <h2><?php esc_html_e( 'Media Scan', 'unattached-media-manager' ); ?></h2>
                <?php
                $processing_mode = Unattached_Media_Manager::get_setting( 'processing_mode' );
                if ( 'background' === $processing_mode ) :
                ?>
                <p class="description" style="margin-bottom: 15px;">
                    <span class="dashicons dashicons-info" style="color: #72aee6; vertical-align: middle;"></span>
                    <?php esc_html_e( 'Scanning runs in the background using WordPress Cron. You can close your browser and the scan will continue automatically.', 'unattached-media-manager' ); ?>
                </p>
                <?php else : ?>
                <p class="description" style="margin-bottom: 15px;">
                    <span class="dashicons dashicons-warning" style="color: #dba617; vertical-align: middle;"></span>
                    <?php esc_html_e( 'Keep this browser tab open while scanning. The scan will stop if you close it.', 'unattached-media-manager' ); ?>
                </p>
                <?php endif; ?>

                <div class="mui-scan-status" id="mui-scan-status">
                    <?php if ( 'completed' === $bg_state['status'] ) : ?>
                        <span class="mui-status-badge mui-status-success">
                            <?php esc_html_e( 'Index Up to Date', 'unattached-media-manager' ); ?>
                        </span>
                    <?php elseif ( 'running' === $bg_state['status'] ) : ?>
                        <span class="mui-status-badge mui-status-running">
                            <?php esc_html_e( 'Scanning in Background', 'unattached-media-manager' ); ?>
                            <span class="mui-pulse"></span>
                        </span>
                    <?php elseif ( 'paused' === $bg_state['status'] ) : ?>
                        <span class="mui-status-badge mui-status-warning">
                            <?php esc_html_e( 'Scan Paused', 'unattached-media-manager' ); ?>
                        </span>
                    <?php else : ?>
                        <span class="mui-status-badge mui-status-pending">
                            <?php esc_html_e( 'Not Indexed', 'unattached-media-manager' ); ?>
                        </span>
                    <?php endif; ?>

                    <?php
                    // Only show progress if scan is running, paused, or completed (not idle)
                    $show_progress = ( 'idle' !== $bg_state['status'] && $scan_status['overall']['percentage'] > 0 ) || 'completed' === $bg_state['status'];
                    ?>
                    <?php if ( $show_progress ) : ?>
                        <div class="mui-progress-bar">
                            <div class="mui-progress-fill" id="mui-progress-fill" style="width: <?php echo esc_attr( $scan_status['overall']['percentage'] ); ?>%"></div>
                        </div>
                        <span class="mui-progress-text" id="mui-progress-text">
                            <?php echo esc_html( $scan_status['overall']['percentage'] ); ?>%
                        </span>
                    <?php else : ?>
                        <div class="mui-progress-bar" style="display: none;">
                            <div class="mui-progress-fill" id="mui-progress-fill" style="width: 0%"></div>
                        </div>
                        <span class="mui-progress-text" id="mui-progress-text" style="display: none;">0%</span>
                    <?php endif; ?>

                    <div class="mui-scan-info" id="mui-scan-info" style="margin-top: 10px; font-size: 12px; color: #666;">
                        <?php if ( 'running' === $bg_state['status'] ) : ?>
                            <?php
                            printf(
                                /* translators: %s: scan type (posts, options, widgets) */
                                esc_html__( 'Currently scanning: %s', 'unattached-media-manager' ),
                                esc_html( ucfirst( $bg_state['current_type'] ?? 'posts' ) )
                            );
                            ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mui-scan-actions" style="margin-top: 15px;">
                    <?php if ( 'running' === $bg_state['status'] ) : ?>
                        <button type="button" class="button button-secondary" id="mui-pause-scan">
                            <?php esc_html_e( 'Pause Scan', 'unattached-media-manager' ); ?>
                        </button>
                        <button type="button" class="button button-link-delete" id="mui-stop-scan">
                            <?php esc_html_e( 'Cancel Scan', 'unattached-media-manager' ); ?>
                        </button>
                    <?php elseif ( 'paused' === $bg_state['status'] ) : ?>
                        <button type="button" class="button button-primary" id="mui-resume-scan">
                            <?php esc_html_e( 'Resume Scan', 'unattached-media-manager' ); ?>
                        </button>
                        <button type="button" class="button button-link-delete" id="mui-stop-scan">
                            <?php esc_html_e( 'Cancel Scan', 'unattached-media-manager' ); ?>
                        </button>
                    <?php else : ?>
                        <button type="button" class="button button-primary" id="mui-start-scan">
                            <?php esc_html_e( 'Start Full Scan', 'unattached-media-manager' ); ?>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="button" id="mui-export-report">
                        <?php esc_html_e( 'Export Report (CSV)', 'unattached-media-manager' ); ?>
                    </button>
                </div>
            </div>

            <!-- References by Context -->
            <?php if ( ! empty( $statistics['by_context'] ) ) : ?>
            <div class="mui-panel">
                <h2><?php esc_html_e( 'References by Context', 'unattached-media-manager' ); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Context', 'unattached-media-manager' ); ?></th>
                            <th><?php esc_html_e( 'Count', 'unattached-media-manager' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $statistics['by_context'] as $context ) : ?>
                        <tr>
                            <td><?php echo esc_html( $this->format_context_type( $context['context_type'] ) ); ?></td>
                            <td><?php echo esc_html( number_format( $context['count'] ) ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Unused Media Preview -->
            <div class="mui-panel">
                <h2><?php esc_html_e( 'Potentially Unused Media', 'unattached-media-manager' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'These media files have no detected references. Always verify before deleting.', 'unattached-media-manager' ); ?>
                </p>

                <?php
                $unused = UNMAM_Database::get_unused_attachments( array( 'per_page' => 20 ) );
                if ( ! empty( $unused ) ) :
                ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'ID', 'unattached-media-manager' ); ?></th>
                            <th><?php esc_html_e( 'Title', 'unattached-media-manager' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'unattached-media-manager' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $unused as $attachment ) : ?>
                        <tr>
                            <td><?php echo esc_html( $attachment['ID'] ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( get_edit_post_link( $attachment['ID'] ) ); ?>">
                                    <?php echo esc_html( $attachment['post_title'] ?: __( '(No title)', 'unattached-media-manager' ) ); ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( get_edit_post_link( $attachment['ID'] ) ); ?>" class="button button-small">
                                    <?php esc_html_e( 'View', 'unattached-media-manager' ); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p>
                    <a href="<?php echo esc_url( admin_url( 'upload.php?detached=1' ) ); ?>">
                        <?php esc_html_e( 'View all unattached media →', 'unattached-media-manager' ); ?>
                    </a>
                </p>
                <?php else : ?>
                <p><?php esc_html_e( 'No unused media found. Run a scan to detect unused files.', 'unattached-media-manager' ); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            </div><!-- .mui-tab-content -->
        </div>
        <?php
    }

    /**
     * Render settings content (called from render_admin_page)
     */
    private function render_settings_content() {
        $settings = Unattached_Media_Manager::get_setting();
        ?>
            <form method="post" action="">
                <?php wp_nonce_field( 'unmam_settings_nonce' ); ?>

                <h2 class="title"><?php esc_html_e( 'Resource Management', 'unattached-media-manager' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Control how much server resources the scanner uses. This is important for shared hosting or sites with limited resources.', 'unattached-media-manager' ); ?></p>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Resource Mode', 'unattached-media-manager' ); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="mui_resource_mode" value="auto" <?php checked( ( $settings['resource_mode'] ?? 'auto' ), 'auto' ); ?>>
                                    <strong><?php esc_html_e( 'Auto (Recommended)', 'unattached-media-manager' ); ?></strong>
                                    <p class="description" style="margin-left: 25px; margin-top: 2px;">
                                        <?php esc_html_e( 'Automatically adjusts batch size and delays based on your server\'s available memory and time limits. Best for most sites.', 'unattached-media-manager' ); ?>
                                    </p>
                                </label>
                                <br>
                                <label>
                                    <input type="radio" name="mui_resource_mode" value="low" <?php checked( ( $settings['resource_mode'] ?? 'auto' ), 'low' ); ?>>
                                    <strong><?php esc_html_e( 'Low Resources', 'unattached-media-manager' ); ?></strong>
                                    <p class="description" style="margin-left: 25px; margin-top: 2px;">
                                        <?php esc_html_e( 'Uses smaller batches (10-25 items) with longer delays. Best for shared hosting or sites experiencing timeouts.', 'unattached-media-manager' ); ?>
                                    </p>
                                </label>
                                <br>
                                <label>
                                    <input type="radio" name="mui_resource_mode" value="high" <?php checked( ( $settings['resource_mode'] ?? 'auto' ), 'high' ); ?>>
                                    <strong><?php esc_html_e( 'High Performance', 'unattached-media-manager' ); ?></strong>
                                    <p class="description" style="margin-left: 25px; margin-top: 2px;">
                                        <?php esc_html_e( 'Uses larger batches (50-200 items) with minimal delays. Only use on dedicated servers with ample resources.', 'unattached-media-manager' ); ?>
                                    </p>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Processing Mode', 'unattached-media-manager' ); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="mui_processing_mode" value="frontend" <?php checked( ( $settings['processing_mode'] ?? 'frontend' ), 'frontend' ); ?>>
                                    <strong><?php esc_html_e( 'Browser-Driven (Recommended)', 'unattached-media-manager' ); ?></strong>
                                    <p class="description" style="margin-left: 25px; margin-top: 2px;">
                                        <?php esc_html_e( 'Fast and reliable processing. Progress is shown in real-time. Requires keeping the browser tab open until operations complete.', 'unattached-media-manager' ); ?>
                                    </p>
                                </label>
                                <br>
                                <label>
                                    <input type="radio" name="mui_processing_mode" value="background" <?php checked( ( $settings['processing_mode'] ?? 'frontend' ), 'background' ); ?>>
                                    <strong><?php esc_html_e( 'Background (WP-Cron)', 'unattached-media-manager' ); ?></strong>
                                    <p class="description" style="margin-left: 25px; margin-top: 2px;">
                                        <?php esc_html_e( 'Processing continues even after closing the browser. Relies on site visitor traffic to trigger WP-Cron. May be slower on low-traffic sites.', 'unattached-media-manager' ); ?>
                                    </p>
                                </label>
                            </fieldset>
                            <p class="description" style="margin-top: 10px; color: #666;">
                                <strong><?php esc_html_e( 'Tip:', 'unattached-media-manager' ); ?></strong>
                                <?php esc_html_e( 'If you have a real server cron job configured (instead of WP-Cron), background mode will work more reliably.', 'unattached-media-manager' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php
                // Show current server info
                $resource_monitor = UNMAM_Resource_Monitor::instance();
                $server_info = $resource_monitor->get_server_info();
                $resource_status = $resource_monitor->get_status();
                ?>
                <div class="mui-server-info" style="background: #f0f0f1; padding: 15px; border-radius: 4px; margin: 20px 0;">
                    <h3 style="margin-top: 0;"><?php esc_html_e( 'Server Information', 'unattached-media-manager' ); ?></h3>
                    <table class="widefat" style="background: #fff;">
                        <tr>
                            <td><strong><?php esc_html_e( 'PHP Memory Limit', 'unattached-media-manager' ); ?></strong></td>
                            <td><?php echo esc_html( $server_info['memory_limit'] ); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e( 'Current Memory Usage', 'unattached-media-manager' ); ?></strong></td>
                            <td><?php echo esc_html( $resource_status['memory_used'] ); ?> (<?php echo esc_html( $resource_status['memory_percent'] ); ?>%)</td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e( 'Max Execution Time', 'unattached-media-manager' ); ?></strong></td>
                            <td><?php echo esc_html( $server_info['max_execution_time'] ); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e( 'Recommended Batch Size', 'unattached-media-manager' ); ?></strong></td>
                            <td><?php echo esc_html( $resource_status['recommended_batch'] ); ?> <?php esc_html_e( 'items', 'unattached-media-manager' ); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e( 'PHP Version', 'unattached-media-manager' ); ?></strong></td>
                            <td><?php echo esc_html( $server_info['php_version'] ); ?></td>
                        </tr>
                    </table>
                </div>

                <h2 class="title"><?php esc_html_e( 'Attachment Settings', 'unattached-media-manager' ); ?></h2>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Auto-Attach Media', 'unattached-media-manager' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="mui_auto_attach" value="1" <?php checked( $settings['auto_attach'] ?? false ); ?>>
                                <?php esc_html_e( 'Automatically attach media during scanning (not recommended)', 'unattached-media-manager' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'When enabled, unattached media will be automatically attached during scanning. We recommend keeping this OFF and using the manual "Attach All" button instead.', 'unattached-media-manager' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e( 'Scan Sources', 'unattached-media-manager' ); ?></h2>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Content to Scan', 'unattached-media-manager' ); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="mui_scan_post_content" value="1" <?php checked( $settings['scan_post_content'] ?? true ); ?>>
                                    <?php esc_html_e( 'Post Content', 'unattached-media-manager' ); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="mui_scan_featured_images" value="1" <?php checked( $settings['scan_featured_images'] ?? true ); ?>>
                                    <?php esc_html_e( 'Featured Images', 'unattached-media-manager' ); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="mui_scan_gutenberg" value="1" <?php checked( $settings['scan_gutenberg'] ?? true ); ?>>
                                    <?php esc_html_e( 'Gutenberg Blocks', 'unattached-media-manager' ); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="mui_scan_acf_fields" value="1" <?php checked( $settings['scan_acf_fields'] ?? true ); ?>>
                                    <?php esc_html_e( 'ACF Fields', 'unattached-media-manager' ); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="mui_scan_widgets" value="1" <?php checked( $settings['scan_widgets'] ?? true ); ?>>
                                    <?php esc_html_e( 'Widgets', 'unattached-media-manager' ); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="mui_scan_options" value="1" <?php checked( $settings['scan_options'] ?? true ); ?>>
                                    <?php esc_html_e( 'Theme Options & Settings', 'unattached-media-manager' ); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="mui_save_settings" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'unattached-media-manager' ); ?>">
                </p>
            </form>
        <?php
    }

    /**
     * Save settings
     *
     * Note: Nonce verification is done in render_admin_page() before calling this method.
     */
    private function save_settings() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_admin_page()
        $resource_mode = isset( $_POST['unmam_resource_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['unmam_resource_mode'] ) ) : 'auto';
        if ( ! in_array( $resource_mode, array( 'auto', 'low', 'high' ), true ) ) {
            $resource_mode = 'auto';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in render_admin_page()
        $processing_mode = isset( $_POST['unmam_processing_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['unmam_processing_mode'] ) ) : 'frontend';
        if ( ! in_array( $processing_mode, array( 'frontend', 'background' ), true ) ) {
            $processing_mode = 'frontend';
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in render_admin_page()
        $settings = array(
            'batch_size'           => isset( $_POST['unmam_batch_size'] ) ? absint( $_POST['unmam_batch_size'] ) : 50,
            'auto_attach'          => isset( $_POST['unmam_auto_attach'] ),
            'scan_post_content'    => isset( $_POST['unmam_scan_post_content'] ),
            'scan_featured_images' => isset( $_POST['unmam_scan_featured_images'] ),
            'scan_gutenberg'       => isset( $_POST['unmam_scan_gutenberg'] ),
            'scan_acf_fields'      => isset( $_POST['unmam_scan_acf_fields'] ),
            'scan_widgets'         => isset( $_POST['unmam_scan_widgets'] ),
            'scan_options'         => isset( $_POST['unmam_scan_options'] ),
            'excluded_post_types'  => array( 'revision', 'nav_menu_item' ),
            'resource_mode'        => $resource_mode,
            'processing_mode'      => $processing_mode,
        );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        update_option( 'unmam_settings', $settings );

        add_settings_error( 'unmam_settings', 'settings_saved', __( 'Settings saved.', 'unattached-media-manager' ), 'success' );
    }

    /**
     * Format context type for display
     *
     * @param string $context_type Context type.
     * @return string
     */
    private function format_context_type( $context_type ) {
        $labels = array(
            'featured_image' => __( 'Featured Images', 'unattached-media-manager' ),
            'post_content'   => __( 'Post Content', 'unattached-media-manager' ),
            'block'          => __( 'Gutenberg Blocks', 'unattached-media-manager' ),
            'acf'            => __( 'ACF Fields', 'unattached-media-manager' ),
            'postmeta'       => __( 'Post Meta', 'unattached-media-manager' ),
            'shortcode'      => __( 'Shortcodes', 'unattached-media-manager' ),
            'option'         => __( 'Options', 'unattached-media-manager' ),
            'theme_mod'      => __( 'Theme Settings', 'unattached-media-manager' ),
            'widget'         => __( 'Widgets', 'unattached-media-manager' ),
        );

        return isset( $labels[ $context_type ] ) ? $labels[ $context_type ] : ucfirst( str_replace( '_', ' ', $context_type ) );
    }

    /**
     * Add settings link to plugins page
     *
     * @param array $links Existing links.
     * @return array
     */
    public function add_settings_link( $links ) {
        $dashboard_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'upload.php?page=unattached-media-manager' ),
            __( 'Dashboard', 'unattached-media-manager' )
        );
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'upload.php?page=unattached-media-manager&tab=settings' ),
            __( 'Settings', 'unattached-media-manager' )
        );
        array_unshift( $links, $settings_link );
        array_unshift( $links, $dashboard_link );
        return $links;
    }

    /**
     * AJAX: Get statistics
     */
    public function ajax_get_statistics() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $stats = UNMAM_Database::get_statistics();
        wp_send_json_success( $stats );
    }

    /**
     * AJAX: Export report
     */
    public function ajax_export_report() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $manager = UNMAM_Attachment_Manager::instance();
        $data    = $manager->export_report();

        // Generate CSV
        $csv = '';
        foreach ( $data as $row ) {
            $csv .= implode( ',', array_map( function( $cell ) {
                return '"' . str_replace( '"', '""', $cell ) . '"';
            }, $row ) ) . "\n";
        }

        wp_send_json_success( array(
            'csv'      => $csv,
            'filename' => 'media-usage-report-' . gmdate( 'Y-m-d' ) . '.csv',
        ) );
    }

    /**
     * AJAX: Attach all used but unattached media
     */
    public function ajax_attach_all_used() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $result = UNMAM_Database::attach_all_used_media();

        wp_send_json_success( array(
            'attached' => $result['attached'],
            'total'    => $result['total'],
            'scan_id'  => $result['scan_id'],
            'message'  => sprintf(
                /* translators: %d: number of attached media files */
                __( 'Successfully attached %d media files.', 'unattached-media-manager' ),
                $result['attached']
            ),
        ) );
    }

    /**
     * AJAX: Attach a single media item
     */
    public function ajax_attach_single() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;
        $parent_id     = isset( $_POST['parent_id'] ) ? intval( $_POST['parent_id'] ) : 0;

        if ( ! $attachment_id ) {
            wp_send_json_error( __( 'Invalid attachment ID.', 'unattached-media-manager' ) );
        }

        if ( ! $parent_id ) {
            wp_send_json_error( __( 'Invalid parent ID.', 'unattached-media-manager' ) );
        }

        $result = UNMAM_Database::attach_media_with_history(
            $attachment_id,
            $parent_id,
            'manual_attach',
            __( 'Manual Attachment', 'unattached-media-manager' ),
            'manual_' . gmdate( 'Y-m-d_H-i-s' )
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        // Get updated statistics
        $statistics = UNMAM_Database::get_statistics();

        wp_send_json_success( array(
            'message'           => __( 'Media attached successfully.', 'unattached-media-manager' ),
            'unattached_count'  => $statistics['used_but_unattached'] ?? 0,
        ) );
    }

    /**
     * Render history tab content
     */
    private function render_history_content() {
        $stats = UNMAM_History::get_stats();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination, no action taken
        $current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $per_page = 20;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filter display, no action taken
        $filter = isset( $_GET['filter'] ) ? sanitize_text_field( wp_unslash( $_GET['filter'] ) ) : 'active';

        $history_args = array(
            'per_page' => $per_page,
            'page'     => $current_page,
            'reverted' => 'all' === $filter ? null : ( 'reverted' === $filter ? 1 : 0 ),
        );

        $history = UNMAM_History::get_records( $history_args );
        $total_pages = ceil( $history['total'] / $per_page );
        ?>

        <!-- History Stats -->
        <div class="mui-stats-grid mui-stats-small">
            <div class="mui-stat-card">
                <span class="mui-stat-number"><?php echo esc_html( number_format( $stats['total_changes'] ) ); ?></span>
                <span class="mui-stat-label"><?php esc_html_e( 'Total Changes', 'unattached-media-manager' ); ?></span>
            </div>
            <div class="mui-stat-card mui-stat-info">
                <span class="mui-stat-number"><?php echo esc_html( number_format( $stats['active_changes'] ) ); ?></span>
                <span class="mui-stat-label"><?php esc_html_e( 'Active Changes', 'unattached-media-manager' ); ?></span>
            </div>
            <div class="mui-stat-card mui-stat-warning">
                <span class="mui-stat-number"><?php echo esc_html( number_format( $stats['reverted_changes'] ) ); ?></span>
                <span class="mui-stat-label"><?php esc_html_e( 'Reverted', 'unattached-media-manager' ); ?></span>
            </div>
            <div class="mui-stat-card">
                <span class="mui-stat-number"><?php echo esc_html( number_format( $stats['unique_attachments'] ) ); ?></span>
                <span class="mui-stat-label"><?php esc_html_e( 'Unique Media', 'unattached-media-manager' ); ?></span>
            </div>
        </div>

        <!-- History Panel -->
        <div class="mui-panel">
            <div class="mui-history-header">
                <h2><?php esc_html_e( 'Attachment Change History', 'unattached-media-manager' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'This shows all media files that were attached by this plugin. You can revert any change to restore the original unattached state.', 'unattached-media-manager' ); ?>
                </p>
            </div>

            <!-- Filters -->
            <div class="mui-history-filters" style="margin-bottom: 15px;">
                <ul class="subsubsub">
                    <li>
                        <a href="<?php echo esc_url( add_query_arg( 'filter', 'active' ) ); ?>" <?php echo 'active' === $filter ? 'class="current"' : ''; ?>>
                            <?php esc_html_e( 'Active', 'unattached-media-manager' ); ?>
                            <span class="count">(<?php echo esc_html( $stats['active_changes'] ); ?>)</span>
                        </a> |
                    </li>
                    <li>
                        <a href="<?php echo esc_url( add_query_arg( 'filter', 'reverted' ) ); ?>" <?php echo 'reverted' === $filter ? 'class="current"' : ''; ?>>
                            <?php esc_html_e( 'Reverted', 'unattached-media-manager' ); ?>
                            <span class="count">(<?php echo esc_html( $stats['reverted_changes'] ); ?>)</span>
                        </a> |
                    </li>
                    <li>
                        <a href="<?php echo esc_url( add_query_arg( 'filter', 'all' ) ); ?>" <?php echo 'all' === $filter ? 'class="current"' : ''; ?>>
                            <?php esc_html_e( 'All', 'unattached-media-manager' ); ?>
                            <span class="count">(<?php echo esc_html( $stats['total_changes'] ); ?>)</span>
                        </a>
                    </li>
                </ul>

                <?php if ( 'active' === $filter && $stats['active_changes'] > 0 ) : ?>
                <div class="alignright" style="margin-top: 5px;">
                    <button type="button" class="button" id="mui-revert-all-active">
                        <?php
                        printf(
                            /* translators: %d: number of changes */
                            esc_html__( 'Revert All %d Active', 'unattached-media-manager' ),
                            intval( $stats['active_changes'] )
                        );
                        ?>
                    </button>
                    <button type="button" class="button" id="mui-revert-selected" disabled>
                        <?php esc_html_e( 'Revert Selected', 'unattached-media-manager' ); ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <div style="clear: both;"></div>

            <?php if ( ! empty( $history['items'] ) ) : ?>
            <form id="mui-history-form">
                <table class="wp-list-table widefat fixed striped" id="mui-history-table">
                    <thead>
                        <tr>
                            <?php if ( 'active' === $filter ) : ?>
                            <th class="check-column">
                                <input type="checkbox" id="mui-select-all">
                            </th>
                            <?php endif; ?>
                            <th style="width: 80px;"><?php esc_html_e( 'Thumbnail', 'unattached-media-manager' ); ?></th>
                            <th><?php esc_html_e( 'Media', 'unattached-media-manager' ); ?></th>
                            <th><?php esc_html_e( 'Attached To', 'unattached-media-manager' ); ?></th>
                            <th><?php esc_html_e( 'Context', 'unattached-media-manager' ); ?></th>
                            <th><?php esc_html_e( 'Changed', 'unattached-media-manager' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'unattached-media-manager' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'unattached-media-manager' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $history['items'] as $item ) : ?>
                        <tr data-history-id="<?php echo esc_attr( $item->id ); ?>">
                            <?php if ( 'active' === $filter ) : ?>
                            <th class="check-column">
                                <?php if ( ! $item->reverted ) : ?>
                                <input type="checkbox" class="mui-history-checkbox" value="<?php echo esc_attr( $item->id ); ?>">
                                <?php endif; ?>
                            </th>
                            <?php endif; ?>
                            <td>
                                <?php if ( $item->thumbnail_url ) : ?>
                                    <img src="<?php echo esc_url( $item->thumbnail_url ); ?>" alt="" style="width: 60px; height: 60px; object-fit: cover;">
                                <?php else : ?>
                                    <span class="dashicons dashicons-media-default" style="font-size: 40px; width: 60px; height: 60px; line-height: 60px; text-align: center; color: #999;"></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong>
                                    <a href="<?php echo esc_url( get_edit_post_link( $item->attachment_id ) ); ?>">
                                        <?php
                                        /* translators: %d: attachment ID */
                                        echo esc_html( $item->attachment_title ?: sprintf( __( 'Media #%d', 'unattached-media-manager' ), $item->attachment_id ) );
                                        ?>
                                    </a>
                                </strong>
                                <br>
                                <span class="description">ID: <?php echo esc_html( $item->attachment_id ); ?></span>
                            </td>
                            <td>
                                <?php if ( $item->parent_title ) : ?>
                                    <a href="<?php echo esc_url( get_edit_post_link( $item->new_parent_id ) ); ?>">
                                        <?php echo esc_html( $item->parent_title ); ?>
                                    </a>
                                    <br>
                                    <span class="description"><?php echo esc_html( ucfirst( $item->parent_post_type ?? 'post' ) ); ?> #<?php echo esc_html( $item->new_parent_id ); ?></span>
                                <?php else : ?>
                                    <span class="description"><?php esc_html_e( 'Post deleted or unavailable', 'unattached-media-manager' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo esc_html( $item->context_label ?: ucfirst( str_replace( '_', ' ', $item->context_type ) ) ); ?>
                            </td>
                            <td>
                                <?php
                                $changed_time = strtotime( $item->changed_at );
                                echo esc_html( human_time_diff( $changed_time, time() ) . ' ' . __( 'ago', 'unattached-media-manager' ) );
                                ?>
                                <br>
                                <span class="description"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $changed_time ) ); ?></span>
                            </td>
                            <td>
                                <?php if ( $item->reverted ) : ?>
                                    <span class="mui-status-badge mui-status-warning"><?php esc_html_e( 'Reverted', 'unattached-media-manager' ); ?></span>
                                    <?php if ( $item->reverted_at ) : ?>
                                    <br>
                                    <span class="description">
                                        <?php
                                        $reverted_time = strtotime( $item->reverted_at );
                                        echo esc_html( human_time_diff( $reverted_time, time() ) . ' ' . __( 'ago', 'unattached-media-manager' ) );
                                        ?>
                                    </span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="mui-status-badge mui-status-success"><?php esc_html_e( 'Active', 'unattached-media-manager' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( ! $item->reverted ) : ?>
                                <button type="button" class="button button-small mui-revert-single" data-id="<?php echo esc_attr( $item->id ); ?>">
                                    <?php esc_html_e( 'Revert', 'unattached-media-manager' ); ?>
                                </button>
                                <?php else : ?>
                                <span class="description"><?php esc_html_e( 'Already reverted', 'unattached-media-manager' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <!-- Pagination -->
            <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php
                        printf(
                            /* translators: %s: number of items */
                            esc_html( _n( '%s item', '%s items', $history['total'], 'unattached-media-manager' ) ),
                            number_format( $history['total'] )
                        );
                        ?>
                    </span>
                    <span class="pagination-links">
                        <?php
                        echo wp_kses_post( paginate_links( array(
                            'base'      => add_query_arg( 'paged', '%#%' ),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $total_pages,
                            'current'   => $current_page,
                        ) ) );
                        ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>

            <?php else : ?>
            <p class="description" style="padding: 20px; text-align: center;">
                <?php
                if ( 'reverted' === $filter ) {
                    esc_html_e( 'No reverted changes found.', 'unattached-media-manager' );
                } elseif ( 'all' === $filter ) {
                    esc_html_e( 'No changes have been made yet. Run a scan and use "Attach All" to start tracking changes.', 'unattached-media-manager' );
                } else {
                    esc_html_e( 'No active changes. All changes have been reverted or no attachments have been made yet.', 'unattached-media-manager' );
                }
                ?>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX: Get history records
     */
    public function ajax_get_history() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $args = array(
            'per_page' => isset( $_POST['per_page'] ) ? intval( $_POST['per_page'] ) : 20,
            'page'     => isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1,
            'reverted' => isset( $_POST['reverted'] ) ? intval( $_POST['reverted'] ) : null,
        );

        $history = UNMAM_History::get_records( $args );
        $stats = UNMAM_History::get_stats();

        wp_send_json_success( array(
            'items' => $history['items'],
            'total' => $history['total'],
            'stats' => $stats,
        ) );
    }

    /**
     * AJAX: Revert a single change
     */
    public function ajax_revert_change() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $history_id = isset( $_POST['history_id'] ) ? intval( $_POST['history_id'] ) : 0;

        if ( ! $history_id ) {
            wp_send_json_error( __( 'Invalid history ID.', 'unattached-media-manager' ) );
        }

        $result = UNMAM_History::revert_change( $history_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'message'          => __( 'Successfully reverted.', 'unattached-media-manager' ),
            'stats'            => UNMAM_History::get_stats(),
            'unattached_count' => UNMAM_Database::get_used_but_unattached_count(),
        ) );
    }

    /**
     * AJAX: Revert multiple changes
     */
    public function ajax_revert_bulk() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $history_ids = isset( $_POST['history_ids'] ) ? array_map( 'intval', (array) $_POST['history_ids'] ) : array();

        if ( empty( $history_ids ) ) {
            wp_send_json_error( __( 'No items selected.', 'unattached-media-manager' ) );
        }

        $result = UNMAM_History::revert_changes( $history_ids );

        wp_send_json_success( array(
            'success' => $result['success'],
            'errors'  => $result['errors'],
            'message' => sprintf(
                /* translators: 1: success count, 2: error count */
                __( 'Reverted %1$d items. %2$d errors.', 'unattached-media-manager' ),
                $result['success'],
                $result['errors']
            ),
            'stats'   => UNMAM_History::get_stats(),
        ) );
    }

    /**
     * Render unused media tab content
     */
    private function render_unused_content() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination, no action taken
        $current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $per_page = 20;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- View toggle, no action taken
        $view = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'unused';

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filter params, no action taken
        $filter_s         = isset( $_GET['mui_s'] ) ? sanitize_text_field( wp_unslash( $_GET['mui_s'] ) ) : '';
        $filter_mime      = isset( $_GET['mui_mime'] ) ? sanitize_text_field( wp_unslash( $_GET['mui_mime'] ) ) : '';
        $filter_date_from = isset( $_GET['mui_date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['mui_date_from'] ) ) : '';
        $filter_date_to   = isset( $_GET['mui_date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['mui_date_to'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // Validate date format (YYYY-MM-DD) — drop invalid values silently
        if ( $filter_date_from && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filter_date_from ) ) {
            $filter_date_from = '';
        }
        if ( $filter_date_to && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filter_date_to ) ) {
            $filter_date_to = '';
        }

        $has_filters = ( '' !== $filter_s || '' !== $filter_mime || '' !== $filter_date_from || '' !== $filter_date_to );

        $unused_count = UNMAM_Database::get_unused_count();
        $trash_count = UNMAM_Database::get_trashed_count();

        if ( 'trash' === $view ) {
            $media = UNMAM_Database::get_trashed_attachments( array(
                'per_page' => $per_page,
                'page'     => $current_page,
            ) );
        } else {
            $media = UNMAM_Database::get_unused_attachments_detailed( array(
                'per_page'  => $per_page,
                'page'      => $current_page,
                's'         => $filter_s,
                'mime_type' => $filter_mime,
                'date_from' => $filter_date_from,
                'date_to'   => $filter_date_to,
            ) );
        }

        $total_pages = ceil( $media['total'] / $per_page );
        ?>

        <!-- Stats Cards -->
        <div class="mui-stats-grid mui-stats-small">
            <div class="mui-stat-card mui-stat-warning">
                <span class="mui-stat-number"><?php echo esc_html( number_format( $unused_count ) ); ?></span>
                <span class="mui-stat-label"><?php esc_html_e( 'Unused Media', 'unattached-media-manager' ); ?></span>
            </div>
            <div class="mui-stat-card">
                <span class="mui-stat-number"><?php echo esc_html( number_format( $trash_count ) ); ?></span>
                <span class="mui-stat-label"><?php esc_html_e( 'In Trash', 'unattached-media-manager' ); ?></span>
            </div>
        </div>

        <!-- Warning Notice -->
        <div class="mui-notice mui-notice-warning" style="margin-bottom: 20px;">
            <strong><?php esc_html_e( 'Warning:', 'unattached-media-manager' ); ?></strong>
            <?php esc_html_e( 'Before deleting media, ensure a scan has been completed. Media files may be used in ways not yet detected (external sites, custom code, etc.). When in doubt, move to trash first - you can restore later if needed.', 'unattached-media-manager' ); ?>
        </div>

        <!-- Unused Media Panel -->
        <div class="mui-panel">
            <div class="mui-unused-header">
                <h2><?php echo 'trash' === $view ? esc_html__( 'Trashed Media', 'unattached-media-manager' ) : esc_html__( 'Unused Media Files', 'unattached-media-manager' ); ?></h2>
                <p class="description">
                    <?php
                    if ( 'trash' === $view ) {
                        esc_html_e( 'These media files are in the trash. You can restore them or delete permanently.', 'unattached-media-manager' );
                    } else {
                        esc_html_e( 'These media files have no detected references in your site content. Review carefully before deleting.', 'unattached-media-manager' );
                    }
                    ?>
                </p>
            </div>

            <!-- Filters/Views -->
            <div class="mui-unused-filters" style="margin-bottom: 15px;">
                <ul class="subsubsub">
                    <li>
                        <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'unused', 'paged' => false ) ) ); ?>" <?php echo 'unused' === $view ? 'class="current"' : ''; ?>>
                            <?php esc_html_e( 'Unused', 'unattached-media-manager' ); ?>
                            <span class="count">(<?php echo esc_html( $unused_count ); ?>)</span>
                        </a> |
                    </li>
                    <li>
                        <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'trash', 'paged' => false ) ) ); ?>" <?php echo 'trash' === $view ? 'class="current"' : ''; ?>>
                            <?php esc_html_e( 'Trash', 'unattached-media-manager' ); ?>
                            <span class="count">(<?php echo esc_html( $trash_count ); ?>)</span>
                        </a>
                    </li>
                </ul>

                <div class="alignright" style="margin-top: 5px;">
                    <?php if ( 'trash' === $view && $trash_count > 0 ) : ?>
                        <button type="button" class="button button-link-delete" id="mui-empty-trash">
                            <?php esc_html_e( 'Empty Trash', 'unattached-media-manager' ); ?>
                        </button>
                        <button type="button" class="button" id="mui-restore-selected" disabled>
                            <?php esc_html_e( 'Restore Selected', 'unattached-media-manager' ); ?>
                        </button>
                        <button type="button" class="button button-link-delete" id="mui-delete-selected-permanently" disabled>
                            <?php esc_html_e( 'Delete Permanently', 'unattached-media-manager' ); ?>
                        </button>
                    <?php elseif ( 'unused' === $view && $unused_count > 0 ) : ?>
                        <?php if ( ! $has_filters ) : ?>
                            <button type="button" class="button button-link-delete" id="mui-trash-all-unused">
                                <?php
                                printf(
                                    /* translators: %d: number of media files */
                                    esc_html__( 'Trash All %d Unused', 'unattached-media-manager' ),
                                    intval( $unused_count )
                                );
                                ?>
                            </button>
                        <?php endif; ?>
                        <button type="button" class="button button-link-delete" id="mui-trash-selected" disabled>
                            <?php esc_html_e( 'Trash Selected', 'unattached-media-manager' ); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div style="clear: both;"></div>

            <?php if ( 'unused' === $view ) : ?>
            <!-- Filter form (Unused view only) -->
            <form method="get" class="mui-unused-filter-form" style="margin: 10px 0 15px; padding: 12px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px;">
                <input type="hidden" name="page" value="unattached-media-manager">
                <input type="hidden" name="tab" value="unused">
                <input type="hidden" name="view" value="unused">

                <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end;">
                    <div>
                        <label for="mui-filter-s" style="display: block; font-weight: 600; font-size: 12px; margin-bottom: 3px;">
                            <?php esc_html_e( 'Filename / Title', 'unattached-media-manager' ); ?>
                        </label>
                        <input type="search" id="mui-filter-s" name="mui_s"
                               value="<?php echo esc_attr( $filter_s ); ?>"
                               placeholder="<?php esc_attr_e( 'e.g. logo.png', 'unattached-media-manager' ); ?>"
                               style="width: 200px;">
                    </div>

                    <div>
                        <label for="mui-filter-mime" style="display: block; font-weight: 600; font-size: 12px; margin-bottom: 3px;">
                            <?php esc_html_e( 'Media Type', 'unattached-media-manager' ); ?>
                        </label>
                        <select id="mui-filter-mime" name="mui_mime">
                            <option value=""><?php esc_html_e( 'All types', 'unattached-media-manager' ); ?></option>
                            <option value="image" <?php selected( $filter_mime, 'image' ); ?>><?php esc_html_e( 'Images', 'unattached-media-manager' ); ?></option>
                            <option value="video" <?php selected( $filter_mime, 'video' ); ?>><?php esc_html_e( 'Videos', 'unattached-media-manager' ); ?></option>
                            <option value="audio" <?php selected( $filter_mime, 'audio' ); ?>><?php esc_html_e( 'Audio', 'unattached-media-manager' ); ?></option>
                            <option value="application/pdf" <?php selected( $filter_mime, 'application/pdf' ); ?>><?php esc_html_e( 'PDF', 'unattached-media-manager' ); ?></option>
                            <option value="application" <?php selected( $filter_mime, 'application' ); ?>><?php esc_html_e( 'Documents / other', 'unattached-media-manager' ); ?></option>
                            <option value="image/jpeg" <?php selected( $filter_mime, 'image/jpeg' ); ?>>&nbsp;&nbsp;<?php esc_html_e( '— JPEG only', 'unattached-media-manager' ); ?></option>
                            <option value="image/png" <?php selected( $filter_mime, 'image/png' ); ?>>&nbsp;&nbsp;<?php esc_html_e( '— PNG only', 'unattached-media-manager' ); ?></option>
                            <option value="image/gif" <?php selected( $filter_mime, 'image/gif' ); ?>>&nbsp;&nbsp;<?php esc_html_e( '— GIF only', 'unattached-media-manager' ); ?></option>
                            <option value="image/webp" <?php selected( $filter_mime, 'image/webp' ); ?>>&nbsp;&nbsp;<?php esc_html_e( '— WebP only', 'unattached-media-manager' ); ?></option>
                            <option value="image/svg+xml" <?php selected( $filter_mime, 'image/svg+xml' ); ?>>&nbsp;&nbsp;<?php esc_html_e( '— SVG only', 'unattached-media-manager' ); ?></option>
                        </select>
                    </div>

                    <div>
                        <label for="mui-filter-date-from" style="display: block; font-weight: 600; font-size: 12px; margin-bottom: 3px;">
                            <?php esc_html_e( 'Uploaded from', 'unattached-media-manager' ); ?>
                        </label>
                        <input type="date" id="mui-filter-date-from" name="mui_date_from"
                               value="<?php echo esc_attr( $filter_date_from ); ?>">
                    </div>

                    <div>
                        <label for="mui-filter-date-to" style="display: block; font-weight: 600; font-size: 12px; margin-bottom: 3px;">
                            <?php esc_html_e( 'Uploaded to', 'unattached-media-manager' ); ?>
                        </label>
                        <input type="date" id="mui-filter-date-to" name="mui_date_to"
                               value="<?php echo esc_attr( $filter_date_to ); ?>">
                    </div>

                    <div>
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e( 'Filter', 'unattached-media-manager' ); ?>
                        </button>
                        <?php if ( $has_filters ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'upload.php?page=unattached-media-manager&tab=unused&view=unused' ) ); ?>" class="button">
                                <?php esc_html_e( 'Clear', 'unattached-media-manager' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ( $has_filters ) : ?>
                    <p style="margin: 10px 0 0; color: #50575e; font-size: 13px;">
                        <?php
                        printf(
                            /* translators: %d: number of matching media files */
                            esc_html( _n( 'Showing %d matching unused file (of %d total).', 'Showing %d matching unused files (of %d total).', intval( $media['total'] ), 'unattached-media-manager' ) ),
                            intval( $media['total'] ),
                            intval( $unused_count )
                        );
                        ?>
                    </p>
                <?php endif; ?>
            </form>
            <?php endif; ?>

            <?php if ( ! empty( $media['items'] ) ) : ?>
            <form id="mui-unused-form">
                <table class="wp-list-table widefat fixed striped" id="mui-unused-table">
                    <thead>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" id="mui-unused-select-all">
                            </th>
                            <th style="width: 80px;"><?php esc_html_e( 'Thumbnail', 'unattached-media-manager' ); ?></th>
                            <th><?php esc_html_e( 'File', 'unattached-media-manager' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'unattached-media-manager' ); ?></th>
                            <?php if ( 'unused' === $view ) : ?>
                            <th><?php esc_html_e( 'Size', 'unattached-media-manager' ); ?></th>
                            <?php endif; ?>
                            <th><?php esc_html_e( 'Date', 'unattached-media-manager' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'unattached-media-manager' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $media['items'] as $item ) : ?>
                        <tr data-attachment-id="<?php echo esc_attr( $item->ID ); ?>">
                            <th class="check-column">
                                <input type="checkbox" class="mui-unused-checkbox" value="<?php echo esc_attr( $item->ID ); ?>">
                            </th>
                            <td>
                                <?php if ( $item->thumbnail_url ) : ?>
                                    <img src="<?php echo esc_url( $item->thumbnail_url ); ?>" alt="" style="width: 60px; height: 60px; object-fit: cover;">
                                <?php else : ?>
                                    <span class="dashicons dashicons-media-default" style="font-size: 40px; width: 60px; height: 60px; line-height: 60px; text-align: center; color: #999;"></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong>
                                    <a href="<?php echo esc_url( get_edit_post_link( $item->ID ) ); ?>">
                                        <?php echo esc_html( $item->post_title ?: $item->filename ); ?>
                                    </a>
                                </strong>
                                <br>
                                <span class="description"><?php echo esc_html( $item->filename ); ?></span>
                            </td>
                            <td>
                                <span class="description"><?php echo esc_html( $item->post_mime_type ); ?></span>
                            </td>
                            <?php if ( 'unused' === $view ) : ?>
                            <td>
                                <span class="description"><?php echo esc_html( $item->file_size ); ?></span>
                            </td>
                            <?php endif; ?>
                            <td>
                                <?php
                                $upload_date = strtotime( $item->post_date );
                                echo esc_html( wp_date( get_option( 'date_format' ), $upload_date ) );
                                ?>
                            </td>
                            <td>
                                <?php if ( 'trash' === $view ) : ?>
                                    <button type="button" class="button button-small mui-restore-single" data-id="<?php echo esc_attr( $item->ID ); ?>">
                                        <?php esc_html_e( 'Restore', 'unattached-media-manager' ); ?>
                                    </button>
                                    <button type="button" class="button button-small button-link-delete mui-delete-single" data-id="<?php echo esc_attr( $item->ID ); ?>">
                                        <?php esc_html_e( 'Delete', 'unattached-media-manager' ); ?>
                                    </button>
                                <?php else : ?>
                                    <a href="<?php echo esc_url( get_edit_post_link( $item->ID ) ); ?>" class="button button-small">
                                        <?php esc_html_e( 'View', 'unattached-media-manager' ); ?>
                                    </a>
                                    <button type="button" class="button button-small button-link-delete mui-trash-single" data-id="<?php echo esc_attr( $item->ID ); ?>">
                                        <?php esc_html_e( 'Trash', 'unattached-media-manager' ); ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <!-- Pagination -->
            <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php
                        printf(
                            /* translators: %s: number of items */
                            esc_html( _n( '%s item', '%s items', $media['total'], 'unattached-media-manager' ) ),
                            number_format( $media['total'] )
                        );
                        ?>
                    </span>
                    <span class="pagination-links">
                        <?php
                        echo wp_kses_post( paginate_links( array(
                            'base'      => add_query_arg( 'paged', '%#%' ),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $total_pages,
                            'current'   => $current_page,
                        ) ) );
                        ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>

            <?php else : ?>
            <p class="description" style="padding: 20px; text-align: center;">
                <?php
                if ( 'trash' === $view ) {
                    esc_html_e( 'Trash is empty.', 'unattached-media-manager' );
                } elseif ( $has_filters ) {
                    esc_html_e( 'No unused media matches the current filters. Try a broader filename, different media type, or wider date range.', 'unattached-media-manager' );
                } else {
                    esc_html_e( 'No unused media found. Run a full scan first to detect unused files.', 'unattached-media-manager' );
                }
                ?>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render unattached media tab content
     */
    private function render_unattached_content() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination, no action taken
        $current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $per_page     = 20;

        $media       = UNMAM_Database::get_used_but_unattached_detailed( array(
            'per_page' => $per_page,
            'page'     => $current_page,
        ) );
        $total_count = $media['total'];
        $total_pages = ceil( $total_count / $per_page );
        ?>

        <!-- Stats Cards -->
        <div class="mui-stats-grid mui-stats-small">
            <div class="mui-stat-card mui-stat-info">
                <span class="mui-stat-number"><?php echo esc_html( number_format( $total_count ) ); ?></span>
                <span class="mui-stat-label"><?php esc_html_e( 'Used but Unattached', 'unattached-media-manager' ); ?></span>
            </div>
        </div>

        <!-- Info Notice -->
        <div class="mui-notice mui-notice-info" style="margin-bottom: 20px;">
            <strong><?php esc_html_e( 'What is this?', 'unattached-media-manager' ); ?></strong>
            <?php esc_html_e( 'These media files are actively used in your content but are marked as "Unattached" in WordPress. Attaching them will organize your media library and make WordPress\'s native "Unattached" filter more accurate.', 'unattached-media-manager' ); ?>
        </div>

        <!-- Unattached Media Panel -->
        <div class="mui-panel">
            <div class="mui-unattached-header">
                <h2><?php esc_html_e( 'Used but Unattached Media', 'unattached-media-manager' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'These media files are found in your content but have no parent post assigned. You can attach them individually or all at once.', 'unattached-media-manager' ); ?>
                </p>
            </div>

            <!-- Actions -->
            <div class="mui-unattached-filters" style="margin-bottom: 15px;">
                <div class="alignright" style="margin-top: 5px;">
                    <?php if ( $total_count > 0 ) : ?>
                        <button type="button" class="button button-primary" id="mui-attach-all-unattached">
                            <?php
                            printf(
                                /* translators: %d: number of media files */
                                esc_html__( 'Attach All %d', 'unattached-media-manager' ),
                                intval( $total_count )
                            );
                            ?>
                        </button>
                        <button type="button" class="button" id="mui-attach-selected" disabled>
                            <?php esc_html_e( 'Attach Selected', 'unattached-media-manager' ); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <div style="clear: both;"></div>

            <?php if ( ! empty( $media['items'] ) ) : ?>
            <form id="mui-unattached-form">
                <table class="wp-list-table widefat fixed striped" id="mui-unattached-table">
                    <thead>
                        <tr>
                            <th class="check-column">
                                <input type="checkbox" id="mui-unattached-select-all">
                            </th>
                            <th style="width: 80px;"><?php esc_html_e( 'Thumbnail', 'unattached-media-manager' ); ?></th>
                            <th><?php esc_html_e( 'File', 'unattached-media-manager' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'unattached-media-manager' ); ?></th>
                            <th><?php esc_html_e( 'References', 'unattached-media-manager' ); ?></th>
                            <th><?php esc_html_e( 'Suggested Parent', 'unattached-media-manager' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'unattached-media-manager' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $media['items'] as $item ) : ?>
                        <tr data-attachment-id="<?php echo esc_attr( $item->ID ); ?>">
                            <th class="check-column">
                                <input type="checkbox" class="mui-unattached-checkbox" value="<?php echo esc_attr( $item->ID ); ?>">
                            </th>
                            <td>
                                <?php if ( $item->thumbnail_url ) : ?>
                                    <img src="<?php echo esc_url( $item->thumbnail_url ); ?>" alt="" style="width: 60px; height: 60px; object-fit: cover;">
                                <?php else : ?>
                                    <span class="dashicons dashicons-media-default" style="font-size: 40px; width: 60px; height: 60px; line-height: 60px; text-align: center; color: #999;"></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong>
                                    <a href="<?php echo esc_url( get_edit_post_link( $item->ID ) ); ?>">
                                        <?php echo esc_html( $item->post_title ?: $item->filename ); ?>
                                    </a>
                                </strong>
                                <br>
                                <span class="description"><?php echo esc_html( $item->filename ); ?></span>
                            </td>
                            <td>
                                <span class="description"><?php echo esc_html( $item->post_mime_type ); ?></span>
                            </td>
                            <td>
                                <span class="mui-reference-count"><?php echo esc_html( $item->reference_count ); ?></span>
                                <?php esc_html_e( 'references', 'unattached-media-manager' ); ?>
                            </td>
                            <td>
                                <?php if ( ! empty( $item->parent_title ) ) : ?>
                                    <a href="<?php echo esc_url( $item->parent_edit_url ); ?>">
                                        <?php echo esc_html( $item->parent_title ); ?>
                                    </a>
                                    <br>
                                    <span class="description"><?php echo esc_html( ucfirst( $item->parent_post_type ) ); ?> #<?php echo esc_html( $item->suggested_parent_id ); ?></span>
                                <?php else : ?>
                                    <span class="description"><?php esc_html_e( 'Unknown', 'unattached-media-manager' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="button button-small mui-attach-single" data-id="<?php echo esc_attr( $item->ID ); ?>" data-parent="<?php echo esc_attr( $item->suggested_parent_id ); ?>">
                                    <?php esc_html_e( 'Attach', 'unattached-media-manager' ); ?>
                                </button>
                                <a href="<?php echo esc_url( get_edit_post_link( $item->ID ) ); ?>" class="button button-small">
                                    <?php esc_html_e( 'View', 'unattached-media-manager' ); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <!-- Pagination -->
            <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php
                        printf(
                            /* translators: %s: number of items */
                            esc_html( _n( '%s item', '%s items', $media['total'], 'unattached-media-manager' ) ),
                            number_format( $media['total'] )
                        );
                        ?>
                    </span>
                    <span class="pagination-links">
                        <?php
                        echo wp_kses_post( paginate_links( array(
                            'base'      => add_query_arg( 'paged', '%#%' ),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $total_pages,
                            'current'   => $current_page,
                        ) ) );
                        ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>

            <?php else : ?>
            <p class="description" style="padding: 20px; text-align: center;">
                <?php esc_html_e( 'No unattached media found that is currently in use. Run a full scan to detect media usage.', 'unattached-media-manager' ); ?>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX: Trash single media
     */
    public function ajax_trash_media() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'delete_posts' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;

        if ( ! $attachment_id ) {
            wp_send_json_error( __( 'Invalid attachment ID.', 'unattached-media-manager' ) );
        }

        $result = UNMAM_Database::trash_attachment( $attachment_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'message'      => __( 'Moved to trash.', 'unattached-media-manager' ),
            'unused_count' => UNMAM_Database::get_unused_count(),
            'trash_count'  => UNMAM_Database::get_trashed_count(),
        ) );
    }

    /**
     * AJAX: Trash multiple media
     */
    public function ajax_trash_media_bulk() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'delete_posts' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $attachment_ids = isset( $_POST['attachment_ids'] ) ? array_map( 'intval', (array) $_POST['attachment_ids'] ) : array();

        if ( empty( $attachment_ids ) ) {
            wp_send_json_error( __( 'No items selected.', 'unattached-media-manager' ) );
        }

        $result = UNMAM_Database::trash_attachments( $attachment_ids );

        wp_send_json_success( array(
            'success'      => $result['success'],
            'errors'       => $result['errors'],
            'message'      => sprintf(
                /* translators: 1: success count, 2: error count */
                __( 'Moved %1$d items to trash. %2$d errors.', 'unattached-media-manager' ),
                $result['success'],
                $result['errors']
            ),
            'unused_count' => UNMAM_Database::get_unused_count(),
            'trash_count'  => UNMAM_Database::get_trashed_count(),
        ) );
    }

    /**
     * AJAX: Restore single media
     */
    public function ajax_restore_media() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'delete_posts' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;

        if ( ! $attachment_id ) {
            wp_send_json_error( __( 'Invalid attachment ID.', 'unattached-media-manager' ) );
        }

        $result = UNMAM_Database::restore_attachment( $attachment_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'message'      => __( 'Restored successfully.', 'unattached-media-manager' ),
            'unused_count' => UNMAM_Database::get_unused_count(),
            'trash_count'  => UNMAM_Database::get_trashed_count(),
        ) );
    }

    /**
     * AJAX: Restore multiple media
     */
    public function ajax_restore_media_bulk() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'delete_posts' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $attachment_ids = isset( $_POST['attachment_ids'] ) ? array_map( 'intval', (array) $_POST['attachment_ids'] ) : array();

        if ( empty( $attachment_ids ) ) {
            wp_send_json_error( __( 'No items selected.', 'unattached-media-manager' ) );
        }

        $result = UNMAM_Database::restore_attachments( $attachment_ids );

        wp_send_json_success( array(
            'success'      => $result['success'],
            'errors'       => $result['errors'],
            'message'      => sprintf(
                /* translators: 1: success count, 2: error count */
                __( 'Restored %1$d items. %2$d errors.', 'unattached-media-manager' ),
                $result['success'],
                $result['errors']
            ),
            'unused_count' => UNMAM_Database::get_unused_count(),
            'trash_count'  => UNMAM_Database::get_trashed_count(),
        ) );
    }

    /**
     * AJAX: Delete media permanently
     */
    public function ajax_delete_media_permanently() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'delete_posts' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $attachment_ids = isset( $_POST['attachment_ids'] ) ? array_map( 'intval', (array) $_POST['attachment_ids'] ) : array();

        if ( empty( $attachment_ids ) ) {
            wp_send_json_error( __( 'No items selected.', 'unattached-media-manager' ) );
        }

        $result = UNMAM_Database::delete_attachments_permanently( $attachment_ids );

        wp_send_json_success( array(
            'success'      => $result['success'],
            'errors'       => $result['errors'],
            'message'      => sprintf(
                /* translators: 1: success count, 2: error count */
                __( 'Deleted %1$d items permanently. %2$d errors.', 'unattached-media-manager' ),
                $result['success'],
                $result['errors']
            ),
            'unused_count' => UNMAM_Database::get_unused_count(),
            'trash_count'  => UNMAM_Database::get_trashed_count(),
        ) );
    }

    /**
     * AJAX: Delete single media permanently
     */
    public function ajax_delete_single() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'delete_posts' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;

        if ( ! $attachment_id ) {
            wp_send_json_error( __( 'Invalid attachment ID.', 'unattached-media-manager' ) );
        }

        $result = UNMAM_Database::delete_attachment_permanently( $attachment_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'message'      => __( 'Deleted permanently.', 'unattached-media-manager' ),
            'unused_count' => UNMAM_Database::get_unused_count(),
            'trash_count'  => UNMAM_Database::get_trashed_count(),
        ) );
    }

    /**
     * AJAX handler to save processing mode setting
     */
    public function ajax_save_processing_mode() {
        check_ajax_referer( 'unmam_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $processing_mode = isset( $_POST['processing_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['processing_mode'] ) ) : 'frontend';

        if ( ! in_array( $processing_mode, array( 'frontend', 'background' ), true ) ) {
            $processing_mode = 'frontend';
        }

        Unattached_Media_Manager::update_setting( 'processing_mode', $processing_mode );

        wp_send_json_success( array(
            'message'         => __( 'Processing mode saved.', 'unattached-media-manager' ),
            'processing_mode' => $processing_mode,
        ) );
    }

    /**
     * Render the global job status bar
     *
     * @param array $job_state Current job state.
     */
    private function render_job_status_bar( $job_state ) {
        $job_queue = UNMAM_Job_Queue::instance();
        $percentage = 0;
        if ( $job_state['total_items'] > 0 ) {
            $percentage = round( ( $job_state['processed_items'] / $job_state['total_items'] ) * 100, 1 );
        }
        $job_type_label = $job_queue->get_job_type_label( $job_state['job_type'] );
        ?>
        <div class="mui-job-status-bar" id="mui-job-status-bar">
            <div class="mui-job-status-inner">
                <div class="mui-job-info">
                    <?php if ( 'running' === $job_state['status'] ) : ?>
                        <span class="mui-status-badge mui-status-running">
                            <?php echo esc_html( $job_type_label ); ?>
                            <span class="mui-pulse"></span>
                        </span>
                    <?php else : ?>
                        <span class="mui-status-badge mui-status-warning">
                            <?php esc_html_e( 'Paused', 'unattached-media-manager' ); ?>: <?php echo esc_html( $job_type_label ); ?>
                        </span>
                    <?php endif; ?>
                    <span class="mui-job-progress-text" id="mui-job-progress-text">
                        <?php
                        printf(
                            /* translators: 1: processed count, 2: total count */
                            esc_html__( '%1$d of %2$d items', 'unattached-media-manager' ),
                            intval( $job_state['processed_items'] ),
                            intval( $job_state['total_items'] )
                        );
                        ?>
                    </span>
                </div>
                <div class="mui-job-progress-bar">
                    <div class="mui-progress-fill" id="mui-job-progress-fill" style="width: <?php echo esc_attr( $percentage ); ?>%;"></div>
                </div>
                <div class="mui-job-actions">
                    <?php if ( 'running' === $job_state['status'] ) : ?>
                        <button type="button" class="button button-small" id="mui-pause-job">
                            <?php esc_html_e( 'Pause', 'unattached-media-manager' ); ?>
                        </button>
                    <?php else : ?>
                        <button type="button" class="button button-small button-primary" id="mui-resume-job">
                            <?php esc_html_e( 'Resume', 'unattached-media-manager' ); ?>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="button button-small button-link-delete" id="mui-stop-job">
                        <?php esc_html_e( 'Stop', 'unattached-media-manager' ); ?>
                    </button>
                </div>
            </div>
            <?php
            $processing_mode = Unattached_Media_Manager::get_setting( 'processing_mode' );
            if ( 'background' === $processing_mode ) :
            ?>
            <p class="mui-job-note">
                <span class="dashicons dashicons-info"></span>
                <?php esc_html_e( 'This process runs in the background. You can close this page and it will continue automatically.', 'unattached-media-manager' ); ?>
            </p>
            <?php else : ?>
            <p class="mui-job-note">
                <span class="dashicons dashicons-warning" style="color: #dba617;"></span>
                <?php esc_html_e( 'Keep this browser tab open. Processing will stop if you close it.', 'unattached-media-manager' ); ?>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }
}
