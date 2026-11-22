<?php
/**
 * Removes everything files-seed.php made: the theme, plugin and mu-plugin files, the images,
 * their reference rows, and the scan state that mentions the fixture theme.
 * Paths that are already gone are fine.
 *
 * Run: dev/flpwp.sh eval-file dev/fixture/files-teardown.php
 */

$fx = get_option( 'unmamfix_files' );
if ( ! is_array( $fx ) ) {
	echo "nothing seeded\n";
	return;
}

// Only ever remove inside the three places the seed writes to.
$roots = array(
	trailingslashit( $fx['theme'] ),
	trailingslashit( $fx['plugin'] ),
	$fx['mu'],
);
$inside = function ( $path ) use ( $roots ) {
	foreach ( $roots as $root ) {
		if ( 0 === strpos( $path, $root ) || $path === untrailingslashit( $root ) ) {
			return true;
		}
	}
	return false;
};

$removed = 0;
foreach ( array_reverse( $fx['paths'] ) as $path ) {
	if ( ! $inside( $path ) ) {
		echo "skipped, outside the fixture: $path\n";
		continue;
	}
	if ( is_link( $path ) || is_file( $path ) ) {
		unlink( $path );
		$removed++;
	} elseif ( is_dir( $path ) ) {
		rmdir( $path );
		$removed++;
	}
}

global $wpdb;
$ids = array_merge( array_values( $fx['found'] ), array_values( $fx['ignored'] ), array_values( $fx['spare'] ) );
foreach ( $ids as $id ) {
	UNMAM_Database::delete_references_by_attachment( $id );
	wp_delete_attachment( $id, true );
}

// Rows the scan wrote for the fixture's own files, and the state that lists its theme.
$table = UNMAM_Database::get_table_name( 'references' );
$wpdb->query( $wpdb->prepare(
	"DELETE FROM $table WHERE source_type = 'file' AND ( context_key LIKE %s OR context_key LIKE %s OR context_key = %s )",
	$wpdb->esc_like( 'theme:unmam-fixture-theme|' ) . '%',
	$wpdb->esc_like( 'plugin:unmam-fixture-plugin|' ) . '%',
	'mu|unmamfix-mu.php'
) );
delete_option( UNMAM_File_Scanner::STATE_OPTION );
UNMAM_Database::reset_scan_progress( 'files' );

delete_option( 'unmamfix_files' );
printf( "removed %d paths and %d images\n", $removed, count( $ids ) );
