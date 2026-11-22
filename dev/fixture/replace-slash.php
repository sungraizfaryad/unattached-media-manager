<?php
/**
 * Replace Media must not strip backslashes from what it saves.
 *
 * Self-contained: creates two throwaway images and a draft post, replaces one image with
 * the other, checks the result, then deletes everything it made.
 * Run: dev/flpwp.sh eval-file dev/fixture/replace-slash.php
 */

require_once ABSPATH . 'wp-admin/includes/image.php';

// Replace Media runs as an admin. Without a user, kses filters every save.
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );

function unmam_rs_image( $name ) {
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

$old     = unmam_rs_image( 'rsold' );
$new     = unmam_rs_image( 'rsnew' );
$old_url = wp_get_attachment_url( $old );
$new_url = wp_get_attachment_url( $new );

// A literal backslash, and a block attribute holding <b>, which core stores as \u003cb\u003e.
$content = '<!-- wp:paragraph --><p>Path C:\\temp\\file</p><!-- /wp:paragraph -->'
	. '<!-- wp:image {"id":' . $old . ',"caption":"\u003cb\u003ebold\u003c/b\u003e"} -->'
	. '<figure class="wp-block-image"><img src="' . $old_url . '" class="wp-image-' . $old . '"/></figure>'
	. '<!-- /wp:image -->'
	// No such attachment; it only starts with the same digits.
	. '<!-- wp:html --><img class="wp-image-' . $old . '9" alt=""/><!-- /wp:html -->';

$pid = wp_insert_post( wp_slash( array(
	'post_type'    => 'post',
	'post_status'  => 'draft',
	'post_title'   => 'UNMAMFIX replace slash',
	'post_content' => $content,
) ) );

// JSON in meta with a quoted word. Unescaped slashes so the meta parser sees the uploads path.
$json = wp_json_encode( array( 'img' => $old_url, 'note' => 'say "hi"' ), JSON_UNESCAPED_SLASHES );
update_post_meta( $pid, 'unmamfix_json', wp_slash( $json ) );

UNMAM_Scanner::instance()->index_post( $pid );

global $wpdb;
$t     = UNMAM_Database::get_table_name( 'references' );
$found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE attachment_id = %d", $old ) );

$fail = 0;
$ok   = function ( $cond, $label ) use ( &$fail ) {
	if ( ! $cond ) {
		$fail++;
	}
	printf( "%-5s %s\n", $cond ? 'ok' : 'FAIL', $label );
};

$meta_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE attachment_id = %d AND context_key = %s", $old, 'unmamfix_json' ) );

$ok( $found > 0, "precondition: old image indexed ($found rows)" );
$ok( $meta_rows > 0, "precondition: meta JSON indexed ($meta_rows rows)" );

if ( $found && $meta_rows ) {
	UNMAM_Attachment_Manager::instance()->replace_references( $old, $new );

	clean_post_cache( $pid );
	$after = get_post_field( 'post_content', $pid, 'raw' );
	$meta  = json_decode( (string) get_post_meta( $pid, 'unmamfix_json', true ), true );

	$ok( false !== strpos( $after, $new_url ) && false === strpos( $after, $old_url ), 'content: URL replaced' );
	$ok( false !== strpos( $after, 'C:\\temp\\file' ), 'content: backslash kept' );
	$ok( false !== strpos( $after, '\u003cb\u003e' ), 'content: \u003c escape kept' );
	$ok( false !== strpos( $after, 'wp-image-' . $old . '9"' ), "content: wp-image-{$old}9 left alone" );
	$ok( is_array( $meta ), 'meta: still valid JSON' );
	$ok( is_array( $meta ) && 'say "hi"' === $meta['note'], 'meta: quotes kept' );
	$ok( is_array( $meta ) && $new_url === $meta['img'], 'meta: URL replaced' );
}

wp_clear_scheduled_hook( 'unmam_index_single_post', array( $pid ) );
wp_delete_post( $pid, true );
foreach ( array( $old, $new ) as $att ) {
	UNMAM_Database::delete_references_by_attachment( $att );
	wp_delete_attachment( $att, true );
}

echo "\nfailures: $fail\n";
