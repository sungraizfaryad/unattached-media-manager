<?php
/**
 * History Manager for Media Usage Inspector
 *
 * Tracks all attachment changes made by the plugin for backup/revert functionality.
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * History Manager class
 */
class UNMAM_History {

    /**
     * Table name for history records
     *
     * @var string
     */
    private static $table_name;

    /**
     * Initialize the history manager
     */
    public static function init() {
        global $wpdb;
        self::$table_name = $wpdb->prefix . 'unmam_attachment_history';
    }

    /**
     * Get the history table name
     *
     * @return string
     */
    public static function get_table_name() {
        global $wpdb;
        if ( empty( self::$table_name ) ) {
            self::$table_name = $wpdb->prefix . 'unmam_attachment_history';
        }
        return self::$table_name;
    }

    /**
     * Create the history table
     */
    public static function create_table() {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL,
            old_parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
            new_parent_id bigint(20) unsigned NOT NULL,
            source_type varchar(50) NOT NULL DEFAULT 'post',
            context_type varchar(100) NOT NULL DEFAULT '',
            context_label varchar(255) NOT NULL DEFAULT '',
            changed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reverted_at datetime DEFAULT NULL,
            reverted tinyint(1) NOT NULL DEFAULT 0,
            scan_id varchar(50) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY attachment_id (attachment_id),
            KEY new_parent_id (new_parent_id),
            KEY reverted (reverted),
            KEY changed_at (changed_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Log an attachment change
     *
     * @param int    $attachment_id  The attachment ID.
     * @param int    $old_parent_id  The old parent ID (usually 0).
     * @param int    $new_parent_id  The new parent ID.
     * @param string $source_type    The source type (post, term, option, widget).
     * @param string $context_type   The context type (content, acf, elementor, etc.).
     * @param string $context_label  Human-readable label for the context.
     * @param string $scan_id        Optional scan ID for grouping changes.
     * @return int|false The history record ID or false on failure.
     */
    public static function log_change( $attachment_id, $old_parent_id, $new_parent_id, $source_type = 'post', $context_type = '', $context_label = '', $scan_id = null ) {
        global $wpdb;

        // Don't log if attachment or new parent is invalid
        if ( ! $attachment_id || ! $new_parent_id ) {
            return false;
        }

        // Don't log if old and new parent are the same
        if ( $old_parent_id === $new_parent_id ) {
            return false;
        }

        $result = $wpdb->insert(
            self::get_table_name(),
            array(
                'attachment_id'  => $attachment_id,
                'old_parent_id'  => $old_parent_id,
                'new_parent_id'  => $new_parent_id,
                'source_type'    => $source_type,
                'context_type'   => $context_type,
                'context_label'  => $context_label,
                'changed_at'     => current_time( 'mysql' ),
                'scan_id'        => $scan_id,
            ),
            array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( $result ) {
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Revert a single attachment change
     *
     * @param int $history_id The history record ID.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public static function revert_change( $history_id ) {
        global $wpdb;

        $record = self::get_record( $history_id );

        if ( ! $record ) {
            return new WP_Error( 'not_found', __( 'History record not found.', 'unattached-media-manager' ) );
        }

        if ( $record->reverted ) {
            return new WP_Error( 'already_reverted', __( 'This change has already been reverted.', 'unattached-media-manager' ) );
        }

        // Check if attachment still exists
        $attachment = get_post( $record->attachment_id );
        if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
            return new WP_Error( 'attachment_not_found', __( 'Attachment no longer exists.', 'unattached-media-manager' ) );
        }

        // Check if current parent matches what we set it to
        if ( (int) $attachment->post_parent !== (int) $record->new_parent_id ) {
            return new WP_Error(
                'parent_changed',
                sprintf(
                    /* translators: %d: current parent ID */
                    __( 'Attachment parent has been changed since our modification. Current parent: %d', 'unattached-media-manager' ),
                    $attachment->post_parent
                )
            );
        }

        // Revert the parent
        $update_result = wp_update_post(
            array(
                'ID'          => $record->attachment_id,
                'post_parent' => $record->old_parent_id,
            ),
            true
        );

        if ( is_wp_error( $update_result ) ) {
            return $update_result;
        }

        // Mark as reverted
        $wpdb->update(
            self::get_table_name(),
            array(
                'reverted'    => 1,
                'reverted_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $history_id ),
            array( '%d', '%s' ),
            array( '%d' )
        );

        return true;
    }

    /**
     * Revert multiple attachment changes
     *
     * @param array $history_ids Array of history record IDs.
     * @return array Results with success and error counts.
     */
    public static function revert_changes( $history_ids ) {
        $results = array(
            'success' => 0,
            'errors'  => 0,
            'details' => array(),
        );

        foreach ( $history_ids as $history_id ) {
            $result = self::revert_change( $history_id );

            if ( is_wp_error( $result ) ) {
                $results['errors']++;
                $results['details'][ $history_id ] = $result->get_error_message();
            } else {
                $results['success']++;
                $results['details'][ $history_id ] = true;
            }
        }

        return $results;
    }

    /**
     * Revert all changes from a specific scan
     *
     * @param string $scan_id The scan ID.
     * @return array Results with success and error counts.
     */
    public static function revert_scan( $scan_id ) {
        global $wpdb;

        $table = self::get_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safely generated
        $history_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE scan_id = %s AND reverted = 0",
                $scan_id
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( empty( $history_ids ) ) {
            return array(
                'success' => 0,
                'errors'  => 0,
                'details' => array(),
            );
        }

        return self::revert_changes( $history_ids );
    }

    /**
     * Get a single history record
     *
     * @param int $history_id The history record ID.
     * @return object|null The record or null if not found.
     */
    public static function get_record( $history_id ) {
        global $wpdb;

        $table = self::get_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safely generated
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $history_id
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Get history records with pagination
     *
     * @param array $args Query arguments.
     * @return array Array with 'items' and 'total' keys.
     */
    public static function get_records( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page'      => 20,
            'page'          => 1,
            'orderby'       => 'changed_at',
            'order'         => 'DESC',
            'reverted'      => null, // null = all, 0 = not reverted, 1 = reverted
            'attachment_id' => null,
            'scan_id'       => null,
            'search'        => '',
        );

        $args = wp_parse_args( $args, $defaults );

        $where = array( '1=1' );
        $values = array();

        if ( null !== $args['reverted'] ) {
            $where[] = 'h.reverted = %d';
            $values[] = (int) $args['reverted'];
        }

        if ( null !== $args['attachment_id'] ) {
            $where[] = 'h.attachment_id = %d';
            $values[] = (int) $args['attachment_id'];
        }

        if ( null !== $args['scan_id'] ) {
            $where[] = 'h.scan_id = %s';
            $values[] = $args['scan_id'];
        }

        if ( ! empty( $args['search'] ) ) {
            $where[] = '(h.context_label LIKE %s OR p_attach.post_title LIKE %s)';
            $search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $values[] = $search_term;
            $values[] = $search_term;
        }

        $where_clause = implode( ' AND ', $where );

        // Allowed columns for ordering
        $allowed_orderby = array( 'id', 'attachment_id', 'changed_at', 'reverted_at', 'context_type' );
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'changed_at';
        $order = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

        $offset = ( $args['page'] - 1 ) * $args['per_page'];

        // Get total count
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safely generated, where clause is built with safe values
        $count_sql = "SELECT COUNT(*) FROM " . self::get_table_name() . " h
                      LEFT JOIN {$wpdb->posts} p_attach ON h.attachment_id = p_attach.ID
                      WHERE {$where_clause}";

        if ( ! empty( $values ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query is properly prepared above
            $count_sql = $wpdb->prepare( $count_sql, $values );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query is properly prepared above
        $total = (int) $wpdb->get_var( $count_sql );

        // Get items
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and order columns are safely generated
        $sql = "SELECT h.*,
                       p_attach.post_title AS attachment_title,
                       p_attach.guid AS attachment_url,
                       p_parent.post_title AS parent_title,
                       p_parent.post_type AS parent_post_type
                FROM " . self::get_table_name() . " h
                LEFT JOIN {$wpdb->posts} p_attach ON h.attachment_id = p_attach.ID
                LEFT JOIN {$wpdb->posts} p_parent ON h.new_parent_id = p_parent.ID
                WHERE {$where_clause}
                ORDER BY h.{$orderby} {$order}
                LIMIT %d OFFSET %d";

        $values[] = $args['per_page'];
        $values[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query contains properly prepared placeholders
        $items = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );

        // Add thumbnail URLs
        foreach ( $items as &$item ) {
            $item->thumbnail_url = wp_get_attachment_image_url( $item->attachment_id, 'thumbnail' );
            if ( ! $item->thumbnail_url ) {
                $item->thumbnail_url = wp_mime_type_icon( $item->attachment_id );
            }
        }

        return array(
            'items' => $items,
            'total' => $total,
        );
    }

    /**
     * Get summary statistics
     *
     * @return array Statistics array.
     */
    public static function get_stats() {
        global $wpdb;

        $table = self::get_table_name();

        $stats = array(
            'total_changes'   => 0,
            'active_changes'  => 0,
            'reverted_changes' => 0,
            'unique_attachments' => 0,
            'last_change_date' => null,
        );

        // Total changes
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safely generated
        $stats['total_changes'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        // Active (not reverted) changes
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safely generated
        $stats['active_changes'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE reverted = 0" );

        // Reverted changes
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safely generated
        $stats['reverted_changes'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE reverted = 1" );

        // Unique attachments affected
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safely generated
        $stats['unique_attachments'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT attachment_id) FROM {$table} WHERE reverted = 0" );

        // Last change date
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safely generated
        $stats['last_change_date'] = $wpdb->get_var( "SELECT MAX(changed_at) FROM {$table}" );

        return $stats;
    }

    /**
     * Get list of unique scans
     *
     * @return array List of scan records.
     */
    public static function get_scans() {
        global $wpdb;

        $table = self::get_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safely generated, no user input
        return $wpdb->get_results(
            "SELECT scan_id,
                    MIN(changed_at) as started_at,
                    MAX(changed_at) as ended_at,
                    COUNT(*) as total_changes,
                    SUM(CASE WHEN reverted = 0 THEN 1 ELSE 0 END) as active_changes,
                    SUM(CASE WHEN reverted = 1 THEN 1 ELSE 0 END) as reverted_changes
             FROM {$table}
             WHERE scan_id IS NOT NULL
             GROUP BY scan_id
             ORDER BY started_at DESC"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Check if an attachment has been modified by this plugin
     *
     * @param int $attachment_id The attachment ID.
     * @return bool True if the attachment has active (non-reverted) changes.
     */
    public static function has_active_change( $attachment_id ) {
        global $wpdb;

        $table = self::get_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safely generated
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE attachment_id = %d AND reverted = 0",
                $attachment_id
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $count > 0;
    }

    /**
     * Clear all history records
     *
     * @param bool $only_reverted Only clear reverted records.
     * @return int Number of records deleted.
     */
    public static function clear_history( $only_reverted = false ) {
        global $wpdb;

        if ( $only_reverted ) {
            return $wpdb->delete(
                self::get_table_name(),
                array( 'reverted' => 1 ),
                array( '%d' )
            );
        }

        $table = esc_sql( self::get_table_name() );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, escaped with esc_sql()
        return $wpdb->query( "TRUNCATE TABLE `{$table}`" );
    }

    /**
     * Delete history records older than a certain date
     *
     * @param int $days Number of days to keep.
     * @return int Number of records deleted.
     */
    public static function cleanup_old_records( $days = 90 ) {
        global $wpdb;

        $cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        $table = self::get_table_name();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safely generated
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE reverted = 1 AND reverted_at < %s",
                $cutoff_date
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Drop the history table (for uninstall)
     */
    public static function drop_table() {
        global $wpdb;
        $table = esc_sql( self::get_table_name() );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix, escaped with esc_sql()
        $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
    }

    /**
     * Get all active (non-reverted) history IDs for bulk operations
     *
     * @return array Array of history IDs.
     */
    public static function get_all_active_ids() {
        global $wpdb;

        $table = self::get_table_name();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is safely generated
        $ids = $wpdb->get_col(
            "SELECT id FROM {$table} WHERE reverted = 0 ORDER BY id ASC"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $ids ? array_map( 'intval', $ids ) : array();
    }
}
