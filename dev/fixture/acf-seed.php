<?php
/**
 * Fills every ACF field type the scanner claims to read, on the fixture term and post,
 * then reports which ones produced a reference.
 *
 * Needs unmam-acf-harness.php in mu-plugins, ACF active, and seed.php already run.
 * Run with: dev/flpwp.sh eval-file dev/fixture/acf-seed.php
 */

require_once ABSPATH . 'wp-admin/includes/image.php';

function unmam_acf_seed_image( $name ) {
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

$term = get_term_by( 'name', 'UNMAMFIX term', 'unmam_tax' );
if ( ! $term ) {
	echo "fixture term missing - run seed.php first\n";
	return;
}
$tid = $term->term_id;
$ref = 'term_' . $tid;

$img = array();
foreach ( array( 'acfimg', 'acfgal1', 'acfgal2', 'acffile', 'acfwys', 'acfarea', 'acftext', 'acflink', 'acfurl', 'acficon', 'acfrep', 'acfgrp', 'banner', 'acfcdn' ) as $n ) {
	$img[ $n ] = unmam_acf_seed_image( $n );
}

update_field( 'unmam_img',  $img['acfimg'], $ref );
update_field( 'unmam_gal',  array( $img['acfgal1'], $img['acfgal2'] ), $ref );
update_field( 'unmam_file', $img['acffile'], $ref );
update_field( 'unmam_wys',  '<p>Body</p><img class="wp-image-' . $img['acfwys'] . '" src="' . wp_get_attachment_url( $img['acfwys'] ) . '" />', $ref );
update_field( 'unmam_area', 'See ' . wp_get_attachment_url( $img['acfarea'] ) . ' for details.', $ref );
update_field( 'unmam_text', wp_get_attachment_url( $img['acftext'] ), $ref );
update_field( 'unmam_link', array( 'title' => 'Doc', 'url' => wp_get_attachment_url( $img['acflink'] ), 'target' => '' ), $ref );
update_field( 'unmam_url',  wp_get_attachment_url( $img['acfurl'] ), $ref );
update_field( 'unmam_icon', array( 'type' => 'media_library', 'value' => $img['acficon'] ), $ref );
update_field( 'unmam_rep',  array( array( 'rep_img' => $img['acfrep'] ) ), $ref );
update_field( 'unmam_grp',  array( 'grp_img' => $img['acfgrp'] ), $ref );

// Someone else's banner.png, sharing a basename with ours. Must not be credited to ours.
update_field( 'unmam_extlink', array( 'title' => 'Partner', 'url' => 'https://partner.example/assets/banner.png', 'target' => '' ), $ref );
// Our own file on a CDN host, uploads path intact. Must still resolve.
update_field( 'unmam_cdnurl', str_replace( home_url(), 'https://cdn.example.com', wp_get_attachment_url( $img['acfcdn'] ) ), $ref );

$post_id = get_posts( array( 'post_type' => 'unmam_pub', 'title' => 'UNMAMFIX public cpt', 'fields' => 'ids', 'posts_per_page' => 1 ) );
$post_id = $post_id ? (int) $post_id[0] : 0;
if ( $post_id ) {
	$img['acfpostwys'] = unmam_acf_seed_image( 'acfpostwys' );
	update_field( 'unmam_post_wys', '<p>Post body</p><img class="wp-image-' . $img['acfpostwys'] . '" src="' . wp_get_attachment_url( $img['acfpostwys'] ) . '" />', $post_id );
}

$scanner = UNMAM_Scanner::instance();
$scanner->index_term( $tid, 'unmam_tax' );
if ( $post_id ) {
	$scanner->index_post( $post_id );
}

global $wpdb;
$table = UNMAM_Database::get_table_name( 'references' );
$count = function ( $att, $ctx = null ) use ( $wpdb, $table ) {
	$sql = "SELECT COUNT(*) FROM {$table} WHERE attachment_id = %d";
	$args = array( $att );
	if ( $ctx ) {
		$sql   .= ' AND context_type = %s';
		$args[] = $ctx;
	}
	return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
};

// Each field type must produce at least one reference.
$expect_found = array(
	'acfimg' => 'image', 'acfgal1' => 'gallery[0]', 'acfgal2' => 'gallery[1]', 'acffile' => 'file',
	'acfwys' => 'wysiwyg', 'acfarea' => 'textarea', 'acftext' => 'text', 'acflink' => 'link',
	'acfurl' => 'url', 'acficon' => 'icon_picker', 'acfrep' => 'repeater>image', 'acfgrp' => 'group>image',
	'acfcdn' => 'url on a CDN host (must not regress)',
);
if ( $post_id ) {
	$expect_found['acfpostwys'] = 'POST wysiwyg';
}

$fail = 0;
printf( "%-14s %-10s %-6s %s\n", 'IMAGE', 'ID', 'REFS', 'FIELD TYPE' );
foreach ( $expect_found as $key => $label ) {
	$n = $count( $img[ $key ] );
	if ( $n < 1 ) {
		$fail++;
	}
	printf( "%-14s %-10d %-6s %s\n", $key, $img[ $key ], $n > 0 ? $n : 'NONE', $label );
}

// The external link must not credit our local banner.png through the ACF path.
$acf_fp  = $count( $img['banner'], 'term_acf' );
$meta_fp = $count( $img['banner'], 'term_meta' );
printf( "\nexternal link -> local banner.png, ACF path : %d (want 0)\n", $acf_fp );
printf( "external link -> local banner.png, meta path: %d (known bypass, see ROADMAP)\n", $meta_fp );
if ( 0 !== $acf_fp ) {
	$fail++;
}

echo "\nfailures: $fail of " . ( count( $expect_found ) + 1 ) . "\n";
