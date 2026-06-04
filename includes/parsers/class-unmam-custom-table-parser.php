<?php
/**
 * Custom Database Table Parser for Unattached Media Manager
 *
 * Scans admin-selected custom database tables/columns for media references.
 * Many plugins (newsletters, page builders, form builders) store HTML that
 * references uploaded media in their own tables rather than in wp_posts or
 * wp_options. This parser lets a site administrator point the scanner at those
 * `table.column` locations so the referenced media is protected from deletion.
 *
 * SECURITY NOTE
 * -------------
 * Table and column names are SQL identifiers and therefore cannot be bound with
 * $wpdb->prepare() placeholders (which only bind values). The only safe way to
 * use admin-supplied identifiers is to validate them strictly against the live
 * database schema *before* they are placed into any query:
 *   1. Reject any value containing characters outside [A-Za-z0-9_].
 *   2. The table must exist in SHOW TABLES (which only lists the current
 *      database, so other schemas are unreachable).
 *   3. The table must begin with this install's $wpdb->prefix (defence in depth).
 *   4. The column must exist and be a text-storage type.
 * Only identifiers that pass every check are interpolated, and every query is a
 * read-only SELECT.
 *
 * @package UnattachedMediaManager
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Custom Database Table Parser class.
 */
class UNMAM_Custom_Table_Parser {

    /**
     * Get parser name.
     *
     * @return string
     */
    public function get_name() {
        return 'custom_tables';
    }

    /**
     * Validate a table/column pair against the live database schema.
     *
     * @param string $table  Table name (must include the table prefix).
     * @param string $column Column name.
     * @return array|false   array( 'table', 'column', 'pk' ) on success, false otherwise.
     */
    public static function validate_table_column( $table, $column ) {
        global $wpdb;

        $table  = trim( (string) $table );
        $column = trim( (string) $column );

        if ( '' === $table || '' === $column ) {
            return false;
        }

        // Identifier whitelist: letters, digits, underscore only.
        if ( preg_match( '/[^A-Za-z0-9_]/', $table ) || preg_match( '/[^A-Za-z0-9_]/', $column ) ) {
            return false;
        }

        // Defence in depth: only allow tables within this install's prefix.
        if ( 0 !== strpos( $table, $wpdb->prefix ) ) {
            return false;
        }

        // Table must exist. SHOW TABLES only lists the current database.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Schema introspection; no user input in this query.
        $existing_tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( ! in_array( $table, (array) $existing_tables, true ) ) {
            return false;
        }

        // Column must exist and be a text-storage type.
        // Safe to interpolate $table now: it matched the SHOW TABLES whitelist above.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $table validated against SHOW TABLES; identifiers cannot be bound via prepare().
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" );

        $text_type_re = '/^(char|varchar|tinytext|text|mediumtext|longtext|json|enum|set)/i';
        $primary_key  = null;
        $column_found = false;
        $type_ok      = false;

        foreach ( (array) $columns as $col ) {
            // Only adopt the primary key if its name also satisfies the identifier
            // whitelist, so every interpolated identifier holds the same invariant.
            if ( isset( $col->Key, $col->Field ) && 'PRI' === $col->Key && null === $primary_key
                && ! preg_match( '/[^A-Za-z0-9_]/', $col->Field ) ) {
                $primary_key = $col->Field;
            }
            if ( isset( $col->Field ) && $col->Field === $column ) {
                $column_found = true;
                $type_ok      = (bool) preg_match( $text_type_re, isset( $col->Type ) ? $col->Type : '' );
            }
        }

        if ( ! $column_found || ! $type_ok ) {
            return false;
        }

        return array(
            'table'  => $table,
            'column' => $column,
            'pk'     => $primary_key,
        );
    }

    /**
     * Scan one configured table/column for media references.
     *
     * @param array $entry  array( 'table', 'column', optional 'pk' ).
     * @param int   $offset Row offset.
     * @param int   $limit  Maximum rows to read.
     * @return array        array( 'references' => array, 'rows' => int ).
     */
    public function scan_table( $entry, $offset, $limit ) {
        global $wpdb;

        $references = array();

        $table  = isset( $entry['table'] ) ? $entry['table'] : '';
        $column = isset( $entry['column'] ) ? $entry['column'] : '';

        // Re-validate at scan time: the schema may have changed since the
        // setting was saved, and this guarantees identifiers are always safe.
        $valid = self::validate_table_column( $table, $column );
        if ( ! $valid ) {
            return array(
                'references' => $references,
                'rows'       => 0,
            );
        }

        $table     = $valid['table'];
        $column    = $valid['column'];
        $pk        = $valid['pk'];
        $order_col = $pk ? $pk : $column;
        $limit     = max( 1, (int) $limit );
        $offset    = max( 0, (int) $offset );

        // All identifiers ($table, $column, $order_col, $pk) were validated
        // against the live schema above and contain only [A-Za-z0-9_].
        $select = "SELECT `{$column}` AS unmam_val";
        if ( $pk ) {
            $select .= ", `{$pk}` AS unmam_pk";
        }
        $select .= " FROM `{$table}` ORDER BY `{$order_col}` LIMIT %d OFFSET %d";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifiers validated against schema; values (LIMIT/OFFSET) bound via prepare().
        $rows = $wpdb->get_results( $wpdb->prepare( $select, $limit, $offset ) );

        if ( empty( $rows ) ) {
            return array(
                'references' => $references,
                'rows'       => 0,
            );
        }

        $context_key = $table . '.' . $column;

        foreach ( $rows as $row ) {
            $value = isset( $row->unmam_val ) ? $row->unmam_val : '';
            if ( ! is_string( $value ) || '' === $value ) {
                continue;
            }
            $row_pk = isset( $row->unmam_pk ) ? (int) $row->unmam_pk : 0;

            $found = $this->extract_attachment_refs( $value );
            foreach ( $found as $attachment_id => $matched ) {
                $references[] = array(
                    'attachment_id'   => (int) $attachment_id,
                    'source_id'       => $row_pk,
                    'source_type'     => 'custom_table',
                    'context_type'    => 'custom_table',
                    'context_key'     => $context_key,
                    'context_label'   => sprintf(
                        /* translators: 1: table.column location, 2: row primary key */
                        __( 'Custom Table: %1$s (row %2$d)', 'unattached-media-manager' ),
                        $context_key,
                        $row_pk
                    ),
                    'reference_type'  => is_string( $matched ) ? 'url' : 'id',
                    'reference_value' => is_string( $matched ) ? $matched : null,
                );
            }
        }

        return array(
            'references' => $references,
            'rows'       => count( $rows ),
        );
    }

    /**
     * Extract attachment references from a text blob.
     *
     * Detects both `wp-image-{ID}` editor classes and uploaded-media URLs,
     * resolving URLs to attachment IDs via the shared resolver.
     *
     * @param string $text Text to scan.
     * @return array       Map of attachment_id => matched URL (string) or attachment_id (int).
     */
    private function extract_attachment_refs( $text ) {
        $results = array();

        // 1. wp-image-{ID} classes (editor-inserted images).
        if ( preg_match_all( '/wp-image-(\d+)/', $text, $matches ) ) {
            foreach ( $matches[1] as $id ) {
                $id = (int) $id;
                if ( $id > 0 && 'attachment' === get_post_type( $id ) ) {
                    $results[ $id ] = $id;
                }
            }
        }

        // 2. Direct media URLs.
        $url_re = '#https?://[^\s"\'<>()]+?\.(?:jpe?g|png|gif|webp|svg|bmp|ico|avif|mp4|m4v|webm|ogg|ogv|mp3|wav|m4a|pdf|docx?|pptx?|xlsx?|zip)#i';
        if ( preg_match_all( $url_re, $text, $url_matches ) ) {
            foreach ( array_unique( $url_matches[0] ) as $url ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $url );
                if ( $attachment_id ) {
                    $results[ (int) $attachment_id ] = $url;
                }
            }
        }

        return $results;
    }
}
