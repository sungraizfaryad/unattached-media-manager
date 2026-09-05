<?php
/**
 * Seeds every scanner-coverage test case and prints the created IDs.
 * Run with: wp eval-file seed.php
 */

$out = array();

function unmam_seed_image( $name ) {
	$dir  = wp_upload_dir();
	$path = $dir['path'] . '/' . $name . '.png';
	$im   = imagecreatetruecolor( 60, 60 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, crc32( $name ) % 200, 120, 200 ) );
	imagepng( $im, $path );
	imagedestroy( $im );

	$id = wp_insert_attachment( array(
		'post_title'     => 'UNMAMFIX ' . $name,
		'post_mime_type' => 'image/png',
		'post_status'    => 'inherit',
	), $path );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
	return $id;
}

// One image per gap being tested. Each must end up REFERENCED once its fix lands.
$img = array();
foreach ( array( 'pubcpt', 'hiddencpt', 'termmeta', 'termwysiwyg', 'termthumb', 'oddoption', 'themecss' ) as $k ) {
	$img[ $k ] = unmam_seed_image( $k );
}
$out['images'] = $img;

// 1. Public CPT registered after the settings snapshot was stored.
$p = wp_insert_post( array( 'post_title' => 'UNMAMFIX public cpt', 'post_type' => 'unmam_pub', 'post_status' => 'publish' ) );
set_post_thumbnail( $p, $img['pubcpt'] );
$out['public_cpt_post'] = $p;

// 2. Non-public, show_ui CPT (Bricks-shaped). Media referenced in postmeta, like a builder payload.
$h = wp_insert_post( array( 'post_title' => 'UNMAMFIX hidden cpt', 'post_type' => 'unmam_hidden', 'post_status' => 'publish' ) );
update_post_meta( $h, '_unmam_builder_payload', array(
	'elements' => array( array( 'settings' => array( 'image' => array( 'id' => $img['hiddencpt'] ) ) ) ),
) );
$out['hidden_cpt_post'] = $h;

// 3 + 4 + 5. Term meta: plain ID, WYSIWYG HTML, and a WooCommerce-style thumbnail_id.
$t = wp_insert_term( 'UNMAMFIX term', 'unmam_tax' );
if ( is_wp_error( $t ) ) {
	$t = array( 'term_id' => get_term_by( 'name', 'UNMAMFIX term', 'unmam_tax' )->term_id );
}
$tid = $t['term_id'];
update_term_meta( $tid, 'unmam_acf_image', $img['termmeta'] );
update_term_meta( $tid, 'unmam_acf_wysiwyg', '<p>Copy</p><img class="wp-image-' . $img['termwysiwyg'] . '" src="' . wp_get_attachment_url( $img['termwysiwyg'] ) . '" />' );
update_term_meta( $tid, 'thumbnail_id', $img['termthumb'] );
$out['term_id'] = $tid;

// 6. Option whose name matches none of the scan_patterns.
update_option( 'unmamfix_random_blob', array( 'hero' => wp_get_attachment_url( $img['oddoption'] ) ) );
$out['option'] = 'unmamfix_random_blob';

// 7. Upload URL hardcoded in a theme stylesheet, invisible to any DB scan.
$css  = get_stylesheet_directory() . '/unmam-fixture.css';
file_put_contents( $css, ".unmam-hero{background-image:url('" . wp_get_attachment_url( $img['themecss'] ) . "');}\n" );
$out['css_file'] = $css;

// 8. Orphan reference row: points at an attachment ID that does not exist.
global $wpdb;
$wpdb->insert( UNMAM_Database::get_table_name( 'references' ), array(
	'attachment_id' => 999999999,
	'source_id'     => $p,
	'source_type'   => 'post',
	'context_type'  => 'post_content',
	'reference_type'=> 'id',
) );
$out['orphan_ref_attachment_id'] = 999999999;

echo json_encode( $out, JSON_PRETTY_PRINT ) . "\n";
