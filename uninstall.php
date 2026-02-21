<?php
/**
 * Unattached Media Manager Uninstall
 *
 * Fired when the plugin is uninstalled.
 *
 * @package UnattachedMediaManager
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Clean up plugin data on uninstall
 *
 * This removes all data created by the plugin:
 * - Custom database tables
 * - Options
 * - Transients
 * - Scheduled cron events
 *
 * Note: We intentionally do NOT remove post_parent relationships
 * that were set by this plugin, as those are now part of the
 * WordPress media library's native attachment system.
 */

global $wpdb;

// Remove custom database tables (uses mui_ prefix for database compatibility)
$tables = array(
    $wpdb->prefix . 'unmam_media_references',
    $wpdb->prefix . 'unmam_scan_progress',
    $wpdb->prefix . 'unmam_attachment_history',
);

foreach ( $tables as $table ) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// Remove plugin options (both new and legacy)
$options = array(
    // Current options
    'unmam_settings',
    'unmam_scan_state',
    'unmam_scan_paused',
    'unmam_performance_history',
    'unmam_db_version',
    'unmam_job_state',
    // Legacy options (for users upgrading from old version)
    'aioms_settings',
    'mui_settings',
    'mui_scan_state',
    'mui_scan_paused',
    'mui_performance_history',
    'mui_db_version',
    'mui_job_state',
);

foreach ( $options as $option ) {
    delete_option( $option );
}

// Remove transients (both new and legacy)
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_unmam_%' OR option_name LIKE '_transient_timeout_unmam_%'"
);
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mui_%' OR option_name LIKE '_transient_timeout_mui_%'"
);

// Clear scheduled cron events (both new and legacy)
wp_clear_scheduled_hook( 'unmam_background_scan' );
wp_clear_scheduled_hook( 'unmam_process_batch' );
wp_clear_scheduled_hook( 'unmam_process_job' );
wp_clear_scheduled_hook( 'aioms_background_scan' );
wp_clear_scheduled_hook( 'mui_process_batch' );
wp_clear_scheduled_hook( 'mui_process_job' );

// Remove any post meta created by the plugin (both new and legacy prefixes)
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_unmam_%'"
);
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_mui_%'"
);

// For multisite, clean up each site
if ( is_multisite() ) {
    $sites = get_sites( array( 'fields' => 'ids' ) );

    foreach ( $sites as $site_id ) {
        switch_to_blog( $site_id );

        // Remove tables for this site
        $site_tables = array(
            $wpdb->prefix . 'unmam_media_references',
            $wpdb->prefix . 'unmam_scan_progress',
            $wpdb->prefix . 'unmam_attachment_history',
        );

        foreach ( $site_tables as $table ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        }

        // Remove options for this site
        foreach ( $options as $option ) {
            delete_option( $option );
        }

        // Remove transients for this site
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_unmam_%' OR option_name LIKE '_transient_timeout_unmam_%'"
        );
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mui_%' OR option_name LIKE '_transient_timeout_mui_%'"
        );

        // Clear cron events for this site
        wp_clear_scheduled_hook( 'unmam_background_scan' );
        wp_clear_scheduled_hook( 'unmam_process_batch' );
        wp_clear_scheduled_hook( 'unmam_process_job' );
        wp_clear_scheduled_hook( 'aioms_background_scan' );
        wp_clear_scheduled_hook( 'mui_process_batch' );
        wp_clear_scheduled_hook( 'mui_process_job' );

        // Remove post meta for this site
        $wpdb->query(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_unmam_%'"
        );
        $wpdb->query(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_mui_%'"
        );

        restore_current_blog();
    }
}
