<?php
/**
 * WP-CLI Commands for Media Usage Inspector
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Media Usage Inspector CLI commands.
 *
 * ## EXAMPLES
 *
 *     # Run a full scan
 *     $ wp mui scan
 *
 *     # Get statistics
 *     $ wp mui stats
 *
 *     # Check usage for a specific attachment
 *     $ wp mui usage 123
 *
 *     # List unused attachments
 *     $ wp mui unused --limit=50
 *
 *     # Export report
 *     $ wp mui export --format=csv > report.csv
 */
class UNMAM_CLI_Commands {

    /**
     * Run a full media usage scan.
     *
     * ## OPTIONS
     *
     * [--reset]
     * : Reset existing index before scanning.
     *
     * [--batch-size=<number>]
     * : Number of posts to process per batch. Default: 50.
     *
     * ## EXAMPLES
     *
     *     # Run full scan
     *     wp mui scan
     *
     *     # Reset and run fresh scan
     *     wp mui scan --reset
     *
     *     # Run with larger batch size
     *     wp mui scan --batch-size=100
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function scan( $args, $assoc_args ) {
        $reset      = isset( $assoc_args['reset'] );
        $batch_size = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : 50;

        if ( $batch_size > 0 ) {
            Unattached_Media_Manager::update_setting( 'batch_size', $batch_size );
        }

        $scanner = UNMAM_Scanner::instance();

        // Start scan
        WP_CLI::log( 'Starting media usage scan...' );

        if ( $reset ) {
            WP_CLI::log( 'Resetting existing index...' );
        }

        $result = $scanner->full_scan( $reset );
        WP_CLI::log( sprintf( 'Total posts to scan: %d', $result['total'] ) );

        // Create progress bar
        $progress = WP_CLI\Utils\make_progress_bar( 'Scanning posts', $result['total'] );

        // Run batches until complete
        $scan_type = 'posts';
        $total_processed = 0;

        while ( true ) {
            $batch_result = $scanner->run_batch( $scan_type );

            if ( 'completed' === $batch_result['status'] ) {
                // Move to next scan type
                if ( 'posts' === $scan_type ) {
                    $scan_type = 'options';
                    WP_CLI::log( '' );
                    WP_CLI::log( 'Scanning options...' );
                } elseif ( 'options' === $scan_type ) {
                    $scan_type = 'widgets';
                    WP_CLI::log( 'Scanning widgets...' );
                } else {
                    break;
                }
                continue;
            }

            $total_processed += $batch_result['processed'];

            // Update progress
            for ( $i = 0; $i < $batch_result['processed']; $i++ ) {
                $progress->tick();
            }
        }

        $progress->finish();

        // Get final stats
        $stats = UNMAM_Database::get_statistics();

        WP_CLI::success( 'Scan completed!' );
        WP_CLI::log( '' );
        WP_CLI::log( sprintf( 'Total attachments: %d', $stats['total_attachments'] ) );
        WP_CLI::log( sprintf( 'Referenced: %d', $stats['referenced_attachments'] ) );
        WP_CLI::log( sprintf( 'Potentially unused: %d', $stats['unused_attachments'] ) );
        WP_CLI::log( sprintf( 'Total references found: %d', $stats['total_references'] ) );
    }

    /**
     * Display media usage statistics.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Options: table, json, yaml. Default: table.
     *
     * ## EXAMPLES
     *
     *     # Display stats as table
     *     wp mui stats
     *
     *     # Get stats as JSON
     *     wp mui stats --format=json
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function stats( $args, $assoc_args ) {
        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
        $stats  = UNMAM_Database::get_statistics();

        if ( 'json' === $format ) {
            WP_CLI::log( wp_json_encode( $stats, JSON_PRETTY_PRINT ) );
            return;
        }

        if ( 'yaml' === $format ) {
            WP_CLI::log( \Spyc::YAMLDump( $stats ) );
            return;
        }

        // Table format
        WP_CLI::log( '' );
        WP_CLI::log( 'Media Usage Statistics' );
        WP_CLI::log( '======================' );
        WP_CLI::log( '' );

        $summary = array(
            array( 'Metric', 'Value' ),
            array( 'Total Attachments', number_format( $stats['total_attachments'] ) ),
            array( 'Referenced Attachments', number_format( $stats['referenced_attachments'] ) ),
            array( 'Potentially Unused', number_format( $stats['unused_attachments'] ) ),
            array( 'Total References', number_format( $stats['total_references'] ) ),
        );

        WP_CLI\Utils\format_items( 'table', array_slice( $summary, 1 ), array( 'Metric', 'Value' ) );

        if ( ! empty( $stats['by_context'] ) ) {
            WP_CLI::log( '' );
            WP_CLI::log( 'References by Context:' );

            $context_data = array();
            foreach ( $stats['by_context'] as $context ) {
                $context_data[] = array(
                    'Context' => $context['context_type'],
                    'Count'   => number_format( $context['count'] ),
                );
            }

            WP_CLI\Utils\format_items( 'table', $context_data, array( 'Context', 'Count' ) );
        }
    }

    /**
     * Get usage information for a specific attachment.
     *
     * ## OPTIONS
     *
     * <attachment_id>
     * : The attachment ID to check.
     *
     * [--format=<format>]
     * : Output format. Options: table, json, yaml, count. Default: table.
     *
     * ## EXAMPLES
     *
     *     # Check usage for attachment 123
     *     wp mui usage 123
     *
     *     # Get count only
     *     wp mui usage 123 --format=count
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function usage( $args, $assoc_args ) {
        $attachment_id = absint( $args[0] );
        $format        = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

        $attachment = get_post( $attachment_id );
        if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
            WP_CLI::error( 'Invalid attachment ID.' );
        }

        $references = UNMAM_Database::get_references_for_attachment( $attachment_id, array( 'per_page' => 1000 ) );
        $total      = UNMAM_Database::get_reference_count( $attachment_id );

        if ( 'count' === $format ) {
            WP_CLI::log( $total );
            return;
        }

        if ( 'json' === $format ) {
            WP_CLI::log( wp_json_encode( array(
                'attachment_id'   => $attachment_id,
                'attachment_title' => $attachment->post_title,
                'total_references' => $total,
                'references'       => $references,
            ), JSON_PRETTY_PRINT ) );
            return;
        }

        // Table format
        WP_CLI::log( '' );
        WP_CLI::log( sprintf( 'Usage for: %s (ID: %d)', $attachment->post_title, $attachment_id ) );
        WP_CLI::log( sprintf( 'Total references: %d', $total ) );
        WP_CLI::log( '' );

        if ( empty( $references ) ) {
            WP_CLI::log( 'No references found.' );
            return;
        }

        $table_data = array();
        foreach ( $references as $ref ) {
            $source_title = '';
            if ( $ref['source_id'] > 0 ) {
                $source_title = get_the_title( $ref['source_id'] ) ?: sprintf( 'Post #%d', $ref['source_id'] );
            } else {
                $source_title = $ref['context_key'];
            }

            $table_data[] = array(
                'Source ID'    => $ref['source_id'],
                'Source'       => $source_title,
                'Context'      => $ref['context_type'],
                'Field/Key'    => $ref['context_key'],
                'Ref Type'     => $ref['reference_type'],
            );
        }

        WP_CLI\Utils\format_items( 'table', $table_data, array( 'Source ID', 'Source', 'Context', 'Field/Key', 'Ref Type' ) );
    }

    /**
     * List unused/unreferenced attachments.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Maximum number of results. Default: 50.
     *
     * [--format=<format>]
     * : Output format. Options: table, json, ids, count. Default: table.
     *
     * ## EXAMPLES
     *
     *     # List unused attachments
     *     wp mui unused
     *
     *     # Get IDs only for scripting
     *     wp mui unused --format=ids
     *
     *     # Get count only
     *     wp mui unused --format=count
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function unused( $args, $assoc_args ) {
        $limit  = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 50;
        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

        $unused = UNMAM_Database::get_unused_attachments( array( 'per_page' => $limit ) );

        if ( 'count' === $format ) {
            $stats = UNMAM_Database::get_statistics();
            WP_CLI::log( $stats['unused_attachments'] );
            return;
        }

        if ( 'ids' === $format ) {
            $ids = array_column( $unused, 'ID' );
            WP_CLI::log( implode( ' ', $ids ) );
            return;
        }

        if ( 'json' === $format ) {
            WP_CLI::log( wp_json_encode( $unused, JSON_PRETTY_PRINT ) );
            return;
        }

        // Table format
        if ( empty( $unused ) ) {
            WP_CLI::success( 'No unused attachments found!' );
            return;
        }

        WP_CLI::log( sprintf( 'Found %d potentially unused attachments:', count( $unused ) ) );
        WP_CLI::log( '' );

        $table_data = array();
        foreach ( $unused as $item ) {
            $table_data[] = array(
                'ID'    => $item['ID'],
                'Title' => $item['post_title'] ?: '(No title)',
                'File'  => basename( $item['guid'] ),
            );
        }

        WP_CLI\Utils\format_items( 'table', $table_data, array( 'ID', 'Title', 'File' ) );
    }

    /**
     * Attach an attachment to a post.
     *
     * ## OPTIONS
     *
     * <attachment_id>
     * : The attachment ID.
     *
     * <post_id>
     * : The post ID to attach to.
     *
     * ## EXAMPLES
     *
     *     # Attach image 123 to post 456
     *     wp mui attach 123 456
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function attach( $args, $assoc_args ) {
        $attachment_id = absint( $args[0] );
        $post_id       = absint( $args[1] );

        $result = UNMAM_Attachment_Manager::instance()->attach( $attachment_id, $post_id );

        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }

        WP_CLI::success( sprintf( 'Attachment %d attached to post %d.', $attachment_id, $post_id ) );
    }

    /**
     * Detach an attachment from its parent post.
     *
     * ## OPTIONS
     *
     * <attachment_id>
     * : The attachment ID.
     *
     * ## EXAMPLES
     *
     *     # Detach attachment 123
     *     wp mui detach 123
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function detach( $args, $assoc_args ) {
        $attachment_id = absint( $args[0] );

        $result = UNMAM_Attachment_Manager::instance()->detach( $attachment_id );

        if ( is_wp_error( $result ) ) {
            WP_CLI::error( $result->get_error_message() );
        }

        WP_CLI::success( sprintf( 'Attachment %d detached.', $attachment_id ) );
    }

    /**
     * Replace all references to one attachment with another.
     *
     * ## OPTIONS
     *
     * <old_id>
     * : The attachment ID to replace.
     *
     * <new_id>
     * : The new attachment ID.
     *
     * [--dry-run]
     * : Show what would be changed without making changes.
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     # Preview changes
     *     wp mui replace 123 456 --dry-run
     *
     *     # Replace references
     *     wp mui replace 123 456 --yes
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function replace( $args, $assoc_args ) {
        $old_id  = absint( $args[0] );
        $new_id  = absint( $args[1] );
        $dry_run = isset( $assoc_args['dry-run'] );

        if ( ! $dry_run && ! isset( $assoc_args['yes'] ) ) {
            WP_CLI::confirm( sprintf(
                'This will replace all references to attachment %d with %d. Continue?',
                $old_id,
                $new_id
            ) );
        }

        $result = UNMAM_Attachment_Manager::instance()->replace_references( $old_id, $new_id, $dry_run );

        if ( ! $result['success'] ) {
            WP_CLI::error( $result['error'] );
        }

        if ( $dry_run ) {
            WP_CLI::log( 'Dry run - no changes made.' );
            WP_CLI::log( '' );
        }

        WP_CLI::log( sprintf( 'Total references to replace: %d', $result['total_count'] ) );

        if ( ! empty( $result['replaced'] ) ) {
            WP_CLI::log( '' );
            WP_CLI::log( 'Changes:' );
            foreach ( $result['replaced'] as $change ) {
                WP_CLI::log( sprintf(
                    '  - %s (ID: %d) - %s',
                    $change['context_type'],
                    $change['source_id'],
                    $change['action'] ?? 'would update'
                ) );
            }
        }

        if ( ! empty( $result['errors'] ) ) {
            WP_CLI::warning( 'Some references could not be updated:' );
            foreach ( $result['errors'] as $error ) {
                WP_CLI::log( sprintf( '  - %s', $error['error'] ?? 'Unknown error' ) );
            }
        }

        if ( ! $dry_run && $result['total_count'] > 0 ) {
            WP_CLI::success( 'References replaced successfully.' );
        }
    }

    /**
     * Export usage report.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Options: csv, json. Default: csv.
     *
     * [--output=<file>]
     * : Output file path. If not specified, outputs to stdout.
     *
     * ## EXAMPLES
     *
     *     # Export to CSV file
     *     wp mui export --output=report.csv
     *
     *     # Export as JSON
     *     wp mui export --format=json > report.json
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function export( $args, $assoc_args ) {
        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'csv';
        $output = isset( $assoc_args['output'] ) ? $assoc_args['output'] : null;

        $data = UNMAM_Attachment_Manager::instance()->export_report();

        if ( 'json' === $format ) {
            $content = wp_json_encode( $data, JSON_PRETTY_PRINT );
        } else {
            // CSV
            $content = '';
            foreach ( $data as $row ) {
                $content .= implode( ',', array_map( function( $cell ) {
                    return '"' . str_replace( '"', '""', $cell ) . '"';
                }, $row ) ) . "\n";
            }
        }

        if ( $output ) {
            file_put_contents( $output, $content );
            WP_CLI::success( sprintf( 'Report exported to %s', $output ) );
        } else {
            WP_CLI::log( $content );
        }
    }

    /**
     * Index a specific post.
     *
     * ## OPTIONS
     *
     * <post_id>
     * : The post ID to index.
     *
     * ## EXAMPLES
     *
     *     # Index post 123
     *     wp mui index 123
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function index( $args, $assoc_args ) {
        $post_id = absint( $args[0] );

        $post = get_post( $post_id );
        if ( ! $post ) {
            WP_CLI::error( 'Post not found.' );
        }

        UNMAM_Scanner::instance()->index_post( $post_id );

        $count = UNMAM_Database::get_reference_count( $post_id );
        WP_CLI::success( sprintf( 'Post %d indexed. Found %d media references in this post.', $post_id, $count ) );
    }

    /**
     * Get scan status.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Options: table, json. Default: table.
     *
     * ## EXAMPLES
     *
     *     # Check scan status
     *     wp mui status
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function status( $args, $assoc_args ) {
        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
        $status = UNMAM_Scanner::instance()->get_scan_status();

        if ( 'json' === $format ) {
            WP_CLI::log( wp_json_encode( $status, JSON_PRETTY_PRINT ) );
            return;
        }

        WP_CLI::log( '' );
        WP_CLI::log( 'Scan Status' );
        WP_CLI::log( '===========' );
        WP_CLI::log( '' );

        $overall = $status['overall'];
        WP_CLI::log( sprintf( 'Overall Progress: %s%%', $overall['percentage'] ) );
        WP_CLI::log( sprintf( 'Status: %s', ucfirst( $overall['status'] ) ) );
        WP_CLI::log( '' );

        $types = array( 'posts', 'options', 'widgets' );
        foreach ( $types as $type ) {
            if ( isset( $status[ $type ] ) ) {
                $s = $status[ $type ];
                WP_CLI::log( sprintf(
                    '%s: %s (%d/%d)',
                    ucfirst( $type ),
                    $s['status'] ?? 'pending',
                    $s['processed_items'] ?? 0,
                    $s['total_items'] ?? 0
                ) );
            }
        }
    }
}
