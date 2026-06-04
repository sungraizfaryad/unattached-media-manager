<?php
/**
 * Database handler for Media Usage Inspector
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Database class
 */
class UNMAM_Database {

    /**
     * Table name for media references
     *
     * @var string
     */
    private static $table_name = 'unmam_media_references';

    /**
     * Table name for scan progress
     *
     * @var string
     */
    private static $progress_table = 'unmam_scan_progress';

    /**
     * Get full table name with prefix
     *
     * @param string $table Base table name.
     * @return string
     */
    public static function get_table_name( $table = 'references' ) {
        global $wpdb;

        if ( 'progress' === $table ) {
            return $wpdb->prefix . self::$progress_table;
        }

        return $wpdb->prefix . self::$table_name;
    }

    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Media references table
        $references_table = self::get_table_name( 'references' );
        $sql_references   = "CREATE TABLE {$references_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL,
            source_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_type varchar(50) NOT NULL DEFAULT 'post',
            context_type varchar(50) NOT NULL,
            context_key varchar(255) DEFAULT NULL,
            context_label varchar(255) DEFAULT NULL,
            reference_type varchar(20) NOT NULL DEFAULT 'id',
            reference_value text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY attachment_id (attachment_id),
            KEY source_id (source_id),
            KEY source_type (source_type),
            KEY context_type (context_type),
            KEY reference_type (reference_type)
        ) {$charset_collate};";

        // Scan progress table
        $progress_table = self::get_table_name( 'progress' );
        $sql_progress   = "CREATE TABLE {$progress_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_type varchar(50) NOT NULL,
            last_processed_id bigint(20) unsigned NOT NULL DEFAULT 0,
            total_items bigint(20) unsigned NOT NULL DEFAULT 0,
            processed_items bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            error_log text DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY scan_type (scan_type)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_references );
        dbDelta( $sql_progress );

        // Create history table
        UNMAM_History::create_table();

        // Store DB version
        update_option( 'unmam_db_version', UNMAM_VERSION );
    }

    /**
     * Drop database tables (for uninstall)
     */
    public static function drop_tables() {
        global $wpdb;

        $references_table = self::get_table_name( 'references' );
        $progress_table   = self::get_table_name( 'progress' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$references_table}" );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$progress_table}" );

        // Drop history table
        UNMAM_History::drop_table();

        delete_option( 'unmam_db_version' );
    }

    /**
     * Insert a media reference
     *
     * @param array $data Reference data.
     * @return int|false Insert ID or false on failure.
     */
    public static function insert_reference( $data ) {
        global $wpdb;

        $defaults = array(
            'attachment_id'   => 0,
            'source_id'       => 0,
            'source_type'     => 'post',
            'context_type'    => 'unknown',
            'context_key'     => null,
            'context_label'   => null,
            'reference_type'  => 'id',
            'reference_value' => null,
        );

        $data = wp_parse_args( $data, $defaults );

        // Validate required fields
        if ( empty( $data['attachment_id'] ) ) {
            return false;
        }

        $table = self::get_table_name( 'references' );

        // Check for existing reference to avoid duplicates
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT id FROM {$table} WHERE attachment_id = %d AND source_id = %d AND source_type = %s AND context_type = %s AND context_key = %s",
                $data['attachment_id'],
                $data['source_id'],
                $data['source_type'],
                $data['context_type'],
                $data['context_key']
            )
        );

        if ( $existing ) {
            // Update existing reference
            $wpdb->update(
                $table,
                array(
                    'reference_type'  => $data['reference_type'],
                    'reference_value' => $data['reference_value'],
                    'context_label'   => $data['context_label'],
                ),
                array( 'id' => $existing ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );
            return (int) $existing;
        }

        // Insert new reference
        $result = $wpdb->insert(
            $table,
            $data,
            array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( false === $result ) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Get references for an attachment
     *
     * @param int   $attachment_id Attachment ID.
     * @param array $args          Query arguments.
     * @return array
     */
    public static function get_references_for_attachment( $attachment_id, $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page' => 20,
            'page'     => 1,
            'orderby'  => 'source_id',
            'order'    => 'DESC',
        );

        $args   = wp_parse_args( $args, $defaults );
        $table  = self::get_table_name( 'references' );
        $offset = ( $args['page'] - 1 ) * $args['per_page'];

        // Sanitize orderby
        $allowed_orderby = array( 'source_id', 'context_type', 'created_at' );
        $orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'source_id';
        $order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and orderby/order are safely generated
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE attachment_id = %d ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
                $attachment_id,
                $args['per_page'],
                $offset
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $results ? $results : array();
    }

    /**
     * Get total reference count for an attachment
     *
     * @param int $attachment_id Attachment ID.
     * @return int
     */
    public static function get_reference_count( $attachment_id ) {
        global $wpdb;

        $table = self::get_table_name( 'references' );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely generated
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE attachment_id = %d",
                $attachment_id
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return (int) $count;
    }

    /**
     * Get all references grouped by attachment
     *
     * @param array $args Query arguments.
     * @return array
     */
    public static function get_all_references( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page'     => 50,
            'page'         => 1,
            'context_type' => null,
            'source_type'  => null,
            'has_usage'    => null, // true = only used, false = only unused
        );

        $args   = wp_parse_args( $args, $defaults );
        $table  = self::get_table_name( 'references' );
        $offset = ( $args['page'] - 1 ) * $args['per_page'];

        $where = array( '1=1' );
        $values = array();

        if ( $args['context_type'] ) {
            $where[]  = 'context_type = %s';
            $values[] = $args['context_type'];
        }

        if ( $args['source_type'] ) {
            $where[]  = 'source_type = %s';
            $values[] = $args['source_type'];
        }

        $where_clause = implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $query = "SELECT attachment_id, COUNT(*) as reference_count FROM {$table} WHERE {$where_clause} GROUP BY attachment_id ORDER BY reference_count DESC LIMIT %d OFFSET %d";

        $values[] = $args['per_page'];
        $values[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results( $wpdb->prepare( $query, $values ), ARRAY_A );

        return $results ? $results : array();
    }

    /**
     * Delete references by source
     *
     * @param int    $source_id   Source ID.
     * @param string $source_type Source type.
     * @return int Number of rows deleted.
     */
    public static function delete_references_by_source( $source_id, $source_type = 'post' ) {
        global $wpdb;

        $table = self::get_table_name( 'references' );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely generated
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE source_id = %d AND source_type = %s",
                $source_id,
                $source_type
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Delete all references of a given source type.
     *
     * Used by scan sources whose rows do not share a single source_id
     * (e.g. custom tables, where source_id is each row's primary key), so the
     * source_id-based delete cannot clear them in one call.
     *
     * @param string $source_type Source type.
     * @return int Number of rows deleted.
     */
    public static function delete_references_by_source_type( $source_type ) {
        global $wpdb;

        $table = self::get_table_name( 'references' );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely generated
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE source_type = %s",
                $source_type
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Delete references by attachment
     *
     * @param int $attachment_id Attachment ID.
     * @return int Number of rows deleted.
     */
    public static function delete_references_by_attachment( $attachment_id ) {
        global $wpdb;

        $table = self::get_table_name( 'references' );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely generated
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE attachment_id = %d",
                $attachment_id
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Get unused attachments (no references in our table)
     *
     * @param array $args Query arguments.
     * @return array
     */
    public static function get_unused_attachments( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page' => 50,
            'page'     => 1,
        );

        $args   = wp_parse_args( $args, $defaults );
        $table  = self::get_table_name( 'references' );
        $offset = ( $args['page'] - 1 ) * $args['per_page'];

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safely generated
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_title, p.guid
                FROM {$wpdb->posts} p
                LEFT JOIN {$table} r ON p.ID = r.attachment_id
                WHERE p.post_type = 'attachment'
                AND p.post_status != 'trash'
                AND r.attachment_id IS NULL
                ORDER BY p.ID DESC
                LIMIT %d OFFSET %d",
                $args['per_page'],
                $offset
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $results ? $results : array();
    }

    /**
     * Get unused attachments with full details for the admin panel
     *
     * @param array $args Query arguments.
     * @return array Array with 'items' and 'total' keys.
     */
    public static function get_unused_attachments_detailed( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page'  => 20,
            'page'      => 1,
            'orderby'   => 'ID',
            'order'     => 'DESC',
            's'         => '',  // Filename / title search
            'mime_type' => '',  // e.g. 'image', 'image/jpeg', 'video', 'application/pdf'
            'date_from' => '',  // YYYY-MM-DD
            'date_to'   => '',  // YYYY-MM-DD
        );

        $args   = wp_parse_args( $args, $defaults );
        $table  = self::get_table_name( 'references' );
        $offset = ( $args['page'] - 1 ) * $args['per_page'];

        // Allowed columns for ordering
        $allowed_orderby = array( 'ID', 'post_title', 'post_date', 'post_mime_type' );
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'ID';
        $order = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

        // Build optional WHERE clauses for filters
        $where_extra = '';
        $where_params = array();

        if ( '' !== $args['s'] ) {
            $like = '%' . $wpdb->esc_like( $args['s'] ) . '%';
            $where_extra .= ' AND (p.post_title LIKE %s OR p.guid LIKE %s)';
            $where_params[] = $like;
            $where_params[] = $like;
        }

        if ( '' !== $args['mime_type'] ) {
            $mime = $args['mime_type'];
            if ( false === strpos( $mime, '/' ) ) {
                // Group like 'image' -> 'image/%'
                $where_extra .= ' AND p.post_mime_type LIKE %s';
                $where_params[] = $mime . '/%';
            } else {
                $where_extra .= ' AND p.post_mime_type = %s';
                $where_params[] = $mime;
            }
        }

        if ( '' !== $args['date_from'] ) {
            $where_extra .= ' AND p.post_date >= %s';
            $where_params[] = $args['date_from'] . ' 00:00:00';
        }

        if ( '' !== $args['date_to'] ) {
            $where_extra .= ' AND p.post_date <= %s';
            $where_params[] = $args['date_to'] . ' 23:59:59';
        }

        $base_where = "WHERE p.post_type = 'attachment'
            AND p.post_status != 'trash'
            AND r.attachment_id IS NULL" . $where_extra;

        // Get total count
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safely generated; user values are bound via $where_params
        $count_sql = "SELECT COUNT(*)
            FROM {$wpdb->posts} p
            LEFT JOIN {$table} r ON p.ID = r.attachment_id
            {$base_where}";

        if ( ! empty( $where_params ) ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $where_params ) );
        } else {
            $total = (int) $wpdb->get_var( $count_sql );
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Get items with details
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names and orderby/order are safely generated; user values are bound via prepare
        $items_sql = "SELECT p.ID, p.post_title, p.guid, p.post_date, p.post_mime_type, p.post_parent
            FROM {$wpdb->posts} p
            LEFT JOIN {$table} r ON p.ID = r.attachment_id
            {$base_where}
            ORDER BY p.{$orderby} {$order}
            LIMIT %d OFFSET %d";

        $prepare_args = array_merge( $where_params, array( $args['per_page'], $offset ) );
        $results = $wpdb->get_results( $wpdb->prepare( $items_sql, $prepare_args ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Add thumbnail URLs and file sizes
        foreach ( $results as &$item ) {
            $item->thumbnail_url = wp_get_attachment_image_url( $item->ID, 'thumbnail' );
            if ( ! $item->thumbnail_url ) {
                $item->thumbnail_url = wp_mime_type_icon( $item->ID );
            }

            // Get file size
            $file_path = get_attached_file( $item->ID );
            $item->file_size = $file_path && file_exists( $file_path ) ? size_format( filesize( $file_path ) ) : __( 'Unknown', 'unattached-media-manager' );

            // Get filename
            $item->filename = basename( get_attached_file( $item->ID ) );
        }

        return array(
            'items' => $results ? $results : array(),
            'total' => $total,
        );
    }

    /**
     * Get count of unused attachments
     *
     * @return int
     */
    public static function get_unused_count() {
        global $wpdb;

        $table = self::get_table_name( 'references' );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safely generated
        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
            FROM {$wpdb->posts} p
            LEFT JOIN {$table} r ON p.ID = r.attachment_id
            WHERE p.post_type = 'attachment'
            AND p.post_status != 'trash'
            AND r.attachment_id IS NULL"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Get count of trashed attachments
     *
     * @return int
     */
    public static function get_trashed_count() {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
            FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_status = 'trash'"
        );
    }

    /**
     * Get trashed attachments
     *
     * @param array $args Query arguments.
     * @return array Array with 'items' and 'total' keys.
     */
    public static function get_trashed_attachments( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page' => 20,
            'page'     => 1,
        );

        $args   = wp_parse_args( $args, $defaults );
        $offset = ( $args['page'] - 1 ) * $args['per_page'];

        // Get total count
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*)
            FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_status = 'trash'"
        );

        // Get items
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, guid, post_date, post_mime_type
                FROM {$wpdb->posts}
                WHERE post_type = 'attachment'
                AND post_status = 'trash'
                ORDER BY post_modified DESC
                LIMIT %d OFFSET %d",
                $args['per_page'],
                $offset
            )
        );

        // Add thumbnail URLs
        foreach ( $results as &$item ) {
            $item->thumbnail_url = wp_get_attachment_image_url( $item->ID, 'thumbnail' );
            if ( ! $item->thumbnail_url ) {
                $item->thumbnail_url = wp_mime_type_icon( $item->ID );
            }
            $item->filename = basename( $item->guid );
        }

        return array(
            'items' => $results ? $results : array(),
            'total' => $total,
        );
    }

    /**
     * Trash a single attachment
     *
     * @param int $attachment_id Attachment ID.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public static function trash_attachment( $attachment_id ) {
        $attachment = get_post( $attachment_id );

        if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
            return new WP_Error( 'invalid_attachment', __( 'Invalid attachment ID.', 'unattached-media-manager' ) );
        }

        if ( 'trash' === $attachment->post_status ) {
            return new WP_Error( 'already_trashed', __( 'Attachment is already in trash.', 'unattached-media-manager' ) );
        }

        // Use WordPress's built-in trash function
        $result = wp_trash_post( $attachment_id );

        if ( ! $result ) {
            return new WP_Error( 'trash_failed', __( 'Failed to move attachment to trash.', 'unattached-media-manager' ) );
        }

        return true;
    }

    /**
     * Trash multiple attachments
     *
     * @param array $attachment_ids Array of attachment IDs.
     * @return array Results with success and error counts.
     */
    public static function trash_attachments( $attachment_ids ) {
        $results = array(
            'success' => 0,
            'errors'  => 0,
            'details' => array(),
        );

        foreach ( $attachment_ids as $attachment_id ) {
            $result = self::trash_attachment( $attachment_id );

            if ( is_wp_error( $result ) ) {
                $results['errors']++;
                $results['details'][ $attachment_id ] = $result->get_error_message();
            } else {
                $results['success']++;
                $results['details'][ $attachment_id ] = true;
            }
        }

        return $results;
    }

    /**
     * Restore a single attachment from trash
     *
     * @param int $attachment_id Attachment ID.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public static function restore_attachment( $attachment_id ) {
        $attachment = get_post( $attachment_id );

        if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
            return new WP_Error( 'invalid_attachment', __( 'Invalid attachment ID.', 'unattached-media-manager' ) );
        }

        if ( 'trash' !== $attachment->post_status ) {
            return new WP_Error( 'not_trashed', __( 'Attachment is not in trash.', 'unattached-media-manager' ) );
        }

        // Use WordPress's built-in untrash function
        $result = wp_untrash_post( $attachment_id );

        if ( ! $result ) {
            return new WP_Error( 'restore_failed', __( 'Failed to restore attachment from trash.', 'unattached-media-manager' ) );
        }

        return true;
    }

    /**
     * Restore multiple attachments from trash
     *
     * @param array $attachment_ids Array of attachment IDs.
     * @return array Results with success and error counts.
     */
    public static function restore_attachments( $attachment_ids ) {
        $results = array(
            'success' => 0,
            'errors'  => 0,
            'details' => array(),
        );

        foreach ( $attachment_ids as $attachment_id ) {
            $result = self::restore_attachment( $attachment_id );

            if ( is_wp_error( $result ) ) {
                $results['errors']++;
                $results['details'][ $attachment_id ] = $result->get_error_message();
            } else {
                $results['success']++;
                $results['details'][ $attachment_id ] = true;
            }
        }

        return $results;
    }

    /**
     * Permanently delete a single attachment
     *
     * @param int $attachment_id Attachment ID.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public static function delete_attachment_permanently( $attachment_id ) {
        $attachment = get_post( $attachment_id );

        if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
            return new WP_Error( 'invalid_attachment', __( 'Invalid attachment ID.', 'unattached-media-manager' ) );
        }

        // Use WordPress's built-in delete function (force delete bypasses trash)
        $result = wp_delete_attachment( $attachment_id, true );

        if ( ! $result ) {
            return new WP_Error( 'delete_failed', __( 'Failed to delete attachment permanently.', 'unattached-media-manager' ) );
        }

        // Also remove from our references table
        self::delete_references_by_attachment( $attachment_id );

        return true;
    }

    /**
     * Permanently delete multiple attachments
     *
     * @param array $attachment_ids Array of attachment IDs.
     * @return array Results with success and error counts.
     */
    public static function delete_attachments_permanently( $attachment_ids ) {
        $results = array(
            'success' => 0,
            'errors'  => 0,
            'details' => array(),
        );

        foreach ( $attachment_ids as $attachment_id ) {
            $result = self::delete_attachment_permanently( $attachment_id );

            if ( is_wp_error( $result ) ) {
                $results['errors']++;
                $results['details'][ $attachment_id ] = $result->get_error_message();
            } else {
                $results['success']++;
                $results['details'][ $attachment_id ] = true;
            }
        }

        return $results;
    }

    /**
     * Empty the trash (delete all trashed attachments permanently)
     *
     * @return array Results with success and error counts.
     */
    public static function empty_trash() {
        global $wpdb;

        $trashed_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_status = 'trash'"
        );

        if ( empty( $trashed_ids ) ) {
            return array(
                'success' => 0,
                'errors'  => 0,
                'details' => array(),
            );
        }

        return self::delete_attachments_permanently( $trashed_ids );
    }

    /**
     * Get scan progress
     *
     * @param string $scan_type Scan type identifier.
     * @return array|null
     */
    public static function get_scan_progress( $scan_type ) {
        global $wpdb;

        $table = self::get_table_name( 'progress' );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely generated
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE scan_type = %s",
                $scan_type
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $result;
    }

    /**
     * Update scan progress
     *
     * @param string $scan_type Scan type identifier.
     * @param array  $data      Progress data.
     * @return bool
     */
    public static function update_scan_progress( $scan_type, $data ) {
        global $wpdb;

        $table    = self::get_table_name( 'progress' );
        $existing = self::get_scan_progress( $scan_type );

        if ( $existing ) {
            return (bool) $wpdb->update(
                $table,
                $data,
                array( 'scan_type' => $scan_type ),
                null,
                array( '%s' )
            );
        }

        $data['scan_type'] = $scan_type;
        return (bool) $wpdb->insert( $table, $data );
    }

    /**
     * Reset scan progress
     *
     * @param string|null $scan_type Scan type or null to reset all.
     * @return bool
     */
    public static function reset_scan_progress( $scan_type = null ) {
        global $wpdb;

        $table = self::get_table_name( 'progress' );

        if ( $scan_type ) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely generated
            return (bool) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE scan_type = %s",
                    $scan_type
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safely generated
        return (bool) $wpdb->query( "TRUNCATE TABLE {$table}" );
    }

    /**
     * Get statistics
     *
     * @return array
     */
    public static function get_statistics() {
        global $wpdb;

        $table = self::get_table_name( 'references' );

        // Total attachments
        $total_attachments = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'"
        );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safely generated

        // Attachments with references
        $referenced_attachments = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT attachment_id) FROM {$table}"
        );

        // Total references
        $total_references = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}"
        );

        // Used but unattached (in our references table but post_parent = 0)
        // Must have source_id > 0 and source_type = 'post' to be attachable
        $used_but_unattached = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT r.attachment_id)
            FROM {$table} r
            INNER JOIN {$wpdb->posts} p ON r.attachment_id = p.ID
            WHERE p.post_type = 'attachment'
            AND p.post_parent = 0
            AND r.source_id > 0
            AND r.source_type = 'post'"
        );

        // References by context type
        $by_context = $wpdb->get_results(
            "SELECT context_type, COUNT(*) as count FROM {$table} GROUP BY context_type ORDER BY count DESC",
            ARRAY_A
        );

        // References by reference type (id vs url)
        $by_ref_type = $wpdb->get_results(
            "SELECT reference_type, COUNT(*) as count FROM {$table} GROUP BY reference_type",
            ARRAY_A
        );

        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array(
            'total_attachments'      => $total_attachments,
            'referenced_attachments' => $referenced_attachments,
            'unused_attachments'     => $total_attachments - $referenced_attachments,
            'used_but_unattached'    => $used_but_unattached,
            'total_references'       => $total_references,
            'by_context'             => $by_context ? $by_context : array(),
            'by_reference_type'      => $by_ref_type ? $by_ref_type : array(),
        );
    }

    /**
     * Get list of used but unattached media with their first usage location
     *
     * @return array Array of attachment data with suggested parent
     */
    public static function get_used_but_unattached() {
        global $wpdb;

        $table = self::get_table_name( 'references' );

        // Get all attachments that are used but have post_parent = 0
        // Also get the first source_id from our references to suggest as parent
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safely generated
        $results = $wpdb->get_results(
            "SELECT
                p.ID as attachment_id,
                p.post_title,
                MIN(r.source_id) as suggested_parent
            FROM {$wpdb->posts} p
            INNER JOIN {$table} r ON p.ID = r.attachment_id
            WHERE p.post_type = 'attachment'
            AND p.post_parent = 0
            AND r.source_id > 0
            AND r.source_type = 'post'
            GROUP BY p.ID, p.post_title
            ORDER BY p.ID ASC",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $results ? $results : array();
    }

    /**
     * Get count of used but unattached media
     *
     * @return int Count of unattached media that is in use.
     */
    public static function get_used_but_unattached_count() {
        global $wpdb;

        $table = self::get_table_name( 'references' );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safely generated
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$table} r ON p.ID = r.attachment_id
            WHERE p.post_type = 'attachment'
            AND p.post_parent = 0
            AND r.source_id > 0
            AND r.source_type = 'post'"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $count;
    }

    /**
     * Attach all used but unattached media to their first usage location
     *
     * @param string|null $scan_id Optional scan ID for grouping history records.
     * @return array Result with count of attached items
     */
    public static function attach_all_used_media( $scan_id = null ) {
        $unattached = self::get_used_but_unattached();
        $attached_count = 0;

        // Generate scan ID if not provided
        if ( null === $scan_id ) {
            $scan_id = 'bulk_' . gmdate( 'Y-m-d_H-i-s' );
        }

        foreach ( $unattached as $item ) {
            if ( empty( $item['suggested_parent'] ) ) {
                continue;
            }

            // Get current post_parent before update
            $attachment = get_post( $item['attachment_id'] );
            $old_parent = $attachment ? (int) $attachment->post_parent : 0;

            $result = wp_update_post( array(
                'ID'          => $item['attachment_id'],
                'post_parent' => $item['suggested_parent'],
            ) );

            if ( $result && ! is_wp_error( $result ) ) {
                $attached_count++;

                // Log to history for backup/revert functionality
                UNMAM_History::log_change(
                    $item['attachment_id'],
                    $old_parent,
                    $item['suggested_parent'],
                    'post',
                    'bulk_attach',
                    __( 'Bulk Attachment', 'unattached-media-manager' ),
                    $scan_id
                );

                /**
                 * Fires when an attachment is manually attached via bulk action
                 *
                 * @param int $attachment_id Attachment ID.
                 * @param int $parent_id     Post ID it was attached to.
                 */
                do_action( 'unmam_attachment_bulk_attached', $item['attachment_id'], $item['suggested_parent'] );
            }
        }

        return array(
            'attached' => $attached_count,
            'total'    => count( $unattached ),
            'scan_id'  => $scan_id,
        );
    }

    /**
     * Attach a single media item with history logging
     *
     * @param int    $attachment_id Attachment ID.
     * @param int    $parent_id     Parent post ID.
     * @param string $context_type  Context type (content, acf, elementor, etc.).
     * @param string $context_label Human-readable label.
     * @param string $scan_id       Optional scan ID for grouping.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public static function attach_media_with_history( $attachment_id, $parent_id, $context_type = '', $context_label = '', $scan_id = null ) {
        $attachment = get_post( $attachment_id );

        if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
            return new WP_Error( 'invalid_attachment', __( 'Invalid attachment ID.', 'unattached-media-manager' ) );
        }

        $old_parent = (int) $attachment->post_parent;

        // Don't attach if already attached to the same parent
        if ( $old_parent === (int) $parent_id ) {
            return new WP_Error( 'already_attached', __( 'Attachment is already attached to this parent.', 'unattached-media-manager' ) );
        }

        // Update the post parent
        $result = wp_update_post(
            array(
                'ID'          => $attachment_id,
                'post_parent' => $parent_id,
            ),
            true
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Log to history
        UNMAM_History::log_change(
            $attachment_id,
            $old_parent,
            $parent_id,
            'post',
            $context_type,
            $context_label,
            $scan_id
        );

        /**
         * Fires when an attachment is attached via this plugin
         *
         * @param int    $attachment_id Attachment ID.
         * @param int    $parent_id     Post ID it was attached to.
         * @param int    $old_parent    Previous parent ID.
         * @param string $context_type  Context type.
         */
        do_action( 'unmam_attachment_attached', $attachment_id, $parent_id, $old_parent, $context_type );

        return true;
    }

    /**
     * Resolve URL to attachment ID
     *
     * @param string $url Image URL.
     * @return int Attachment ID or 0 if not found.
     */
    public static function url_to_attachment_id( $url ) {
        global $wpdb;

        // Try direct match first
        $attachment_id = attachment_url_to_postid( $url );
        if ( $attachment_id ) {
            return $attachment_id;
        }

        // Handle resized images (-300x200.jpg etc)
        $url_without_size = preg_replace( '/-\d+x\d+(?=\.[a-z]{3,4}$)/i', '', $url );
        if ( $url_without_size !== $url ) {
            $attachment_id = attachment_url_to_postid( $url_without_size );
            if ( $attachment_id ) {
                return $attachment_id;
            }
        }

        // Try to find by guid
        $attachment_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid = %s",
                $url
            )
        );

        if ( $attachment_id ) {
            return (int) $attachment_id;
        }

        // Try partial match on filename
        $filename = basename( $url );
        $filename = preg_replace( '/-\d+x\d+(?=\.[a-z]{3,4}$)/i', '', $filename );

        $attachment_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
                '%' . $wpdb->esc_like( $filename )
            )
        );

        return $attachment_id ? (int) $attachment_id : 0;
    }

    /**
     * Get used but unattached attachments with pagination and details
     *
     * @param array $args Query arguments.
     * @return array Array with 'items' and 'total'.
     */
    public static function get_used_but_unattached_detailed( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page' => 20,
            'page'     => 1,
        );

        $args   = wp_parse_args( $args, $defaults );
        $offset = ( $args['page'] - 1 ) * $args['per_page'];
        $table  = self::get_table_name( 'references' );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safely generated

        // Get total count
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$table} r ON p.ID = r.attachment_id
            WHERE p.post_type = 'attachment'
            AND p.post_parent = 0
            AND r.source_id > 0
            AND r.source_type = 'post'"
        );

        // Get items with pagination
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    p.ID,
                    p.post_title,
                    p.post_mime_type,
                    p.post_date,
                    MIN(r.source_id) as suggested_parent_id
                FROM {$wpdb->posts} p
                INNER JOIN {$table} r ON p.ID = r.attachment_id
                WHERE p.post_type = 'attachment'
                AND p.post_parent = 0
                AND r.source_id > 0
                AND r.source_type = 'post'
                GROUP BY p.ID, p.post_title, p.post_mime_type, p.post_date
                ORDER BY p.ID DESC
                LIMIT %d OFFSET %d",
                $args['per_page'],
                $offset
            )
        );

        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // Add extra data for each item
        foreach ( $results as &$item ) {
            $item->thumbnail_url = wp_get_attachment_image_url( $item->ID, 'thumbnail' );
            if ( ! $item->thumbnail_url ) {
                $item->thumbnail_url = wp_mime_type_icon( $item->ID );
            }

            // Get file size
            $file_path       = get_attached_file( $item->ID );
            $item->file_size = $file_path && file_exists( $file_path ) ? size_format( filesize( $file_path ) ) : __( 'Unknown', 'unattached-media-manager' );

            // Get filename
            $item->filename = basename( get_attached_file( $item->ID ) );

            // Get suggested parent post info
            if ( $item->suggested_parent_id ) {
                $parent_post = get_post( $item->suggested_parent_id );
                if ( $parent_post ) {
                    $item->parent_title     = $parent_post->post_title;
                    $item->parent_post_type = $parent_post->post_type;
                    $item->parent_edit_url  = get_edit_post_link( $item->suggested_parent_id );
                }
            }

            // Get reference count for this attachment
            $item->reference_count = self::get_reference_count( $item->ID );
        }

        return array(
            'items' => $results ? $results : array(),
            'total' => $total,
        );
    }

    /**
     * Get all unused attachment IDs (for bulk operations)
     *
     * @return array Array of attachment IDs.
     */
    public static function get_all_unused_attachment_ids() {
        global $wpdb;

        $table = self::get_table_name( 'references' );

        // Get all attachment IDs that have NO references
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are safely generated
        $unused_ids = $wpdb->get_col(
            "SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$table} r ON p.ID = r.attachment_id
            WHERE p.post_type = 'attachment'
            AND p.post_status = 'inherit'
            AND r.attachment_id IS NULL
            ORDER BY p.ID ASC"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return $unused_ids ? array_map( 'intval', $unused_ids ) : array();
    }
}
