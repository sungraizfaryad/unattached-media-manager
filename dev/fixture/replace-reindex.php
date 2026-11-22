<?php
/**
 * Replace Media must re-index the sources it changed, and leave every other row on the
 * old image. A row it could not rewrite (a custom table column, a theme file) still holds
 * the old image, so the old image is still in use.
 *
 * Self-contained: two throwaway images, a published post with a block for the first, one
 * hand-made row Replace Media cannot change. Everything is deleted at the end, pass or fail.
 * Run: dev/flpwp.sh eval-file dev/fixture/replace-reindex.php
 */

require_once ABSPATH . 'wp-admin/includes/image.php';

wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );

function unmam_ri_image( $name ) {
	$dir  = wp_upload_dir();
	$path = $dir['path'] . '/' . $name . '.png';
	$im   = imagecreatetruecolor( 40, 40 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, crc32( $name ) % 200, 90, 160 ) );
	imagepng( $im, $path );
	imagedestroy( $im );

	$id = wp_insert_attachment( array(
		'post_title'     => 'UNMAMFIX ' . $name,
		'post_mime_type' => 'image/png',
		'post_status'    => 'inherit',
	), $path );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
	return $id;
}

global $wpdb;
$t    = UNMAM_Database::get_table_name( 'references' );
$fail = 0;
$ok   = function ( $cond, $label ) use ( &$fail ) {
	if ( ! $cond ) {
		$fail++;
	}
	printf( "%-5s %s\n", $cond ? 'ok' : 'FAIL', $label );
};

$a   = 0;
$b   = 0;
$pid = 0;

try {
	$a     = unmam_ri_image( 'unmamfix-ri-a' );
	$b     = unmam_ri_image( 'unmamfix-ri-b' );
	$a_url = wp_get_attachment_url( $a );

	$pid = wp_insert_post( wp_slash( array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'UNMAMFIX replace reindex',
		'post_content' => '<!-- wp:image {"id":' . $a . '} --><figure class="wp-block-image"><img src="' . $a_url . '" class="wp-image-' . $a . '"/></figure><!-- /wp:image -->',
	) ) );

	UNMAM_Scanner::instance()->index_post( $pid );

	$wpdb->insert( $t, array(
		'attachment_id' => $a,
		'source_type'   => 'custom_table',
		'source_id'     => 999999,
		'context_type'  => 'custom_table',
		'context_key'   => 'unmamfix.col',
	) );

	$rows_for_post_on = function ( $att ) use ( $wpdb, $t, $pid ) {
		return $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE attachment_id = %d AND source_type = 'post' AND source_id = %d", $att, $pid ) );
	};

	$ok( count( $rows_for_post_on( $a ) ) >= 2, 'precondition: post has 2+ rows for A' );

	UNMAM_Attachment_Manager::instance()->replace_references( $a, $b, false );

	clean_post_cache( $pid );
	$ok( false !== strpos( get_post( $pid )->post_content, 'wp-image-' . $b ), 'content now uses B' );
	$ok( 0 === count( $rows_for_post_on( $a ) ), 'no post rows left on A' );
	$ok( count( $rows_for_post_on( $b ) ) >= 2, 'post rows rebuilt on B' );
	$ok( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE attachment_id = $a AND source_type = 'custom_table'" ), 'unchangeable row stays on A' );
	$ok( 1 === UNMAM_Database::get_reference_count( $a ), 'A still used' );
} finally {
	if ( $pid ) {
		wp_clear_scheduled_hook( 'unmam_index_single_post', array( $pid ) );
		wp_delete_post( $pid, true );
	}
	foreach ( array( $a, $b ) as $att ) {
		if ( $att ) {
			wp_delete_attachment( $att, true );
		}
	}
	$ids = array_filter( array( $a, $b ) );
	$wpdb->query( "DELETE FROM $t WHERE source_id = 999999" );
	if ( $pid ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE source_type = 'post' AND source_id = %d", $pid ) );
	}
	if ( $ids ) {
		$wpdb->query( "DELETE FROM $t WHERE attachment_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' );
	}
}

echo "\nfailures: $fail\n";
