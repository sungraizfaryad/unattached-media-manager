<?php
/**
 * Removes the design fixture and puts Additional CSS back as it was.
 * Run: dev/flpwp.sh eval-file dev/fixture/design-teardown.php
 */

global $wpdb;

$fx = get_option( 'unmamfix_design' );
if ( ! $fx ) {
	echo "nothing to tear down\n";
	return;
}

$active = get_stylesheet();

if ( $fx['css']['existed'] ) {
	wp_update_custom_css_post( $fx['css']['css'], array( 'stylesheet' => $active ) );
} else {
	wp_delete_post( $fx['css_id'], true );
	remove_theme_mod( 'custom_css_post_id' );
}

foreach ( array_filter( $fx['posts'] ) as $pid ) {
	wp_clear_scheduled_hook( 'unmam_index_single_post', array( $pid ) );
	UNMAM_Database::delete_references_by_source( $pid, 'post' );
	wp_delete_post( $pid, true );
}

// Revisions the seed and teardown made on the Additional CSS post.
$revs = $wpdb->get_col( $wpdb->prepare(
	"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d AND ID > %d",
	$fx['css_id'],
	$fx['max_id']
) );
foreach ( $revs as $rev ) {
	wp_delete_post( (int) $rev, true );
}
wp_clear_scheduled_hook( 'unmam_index_single_post', array( $fx['css_id'] ) );

foreach ( $fx['img'] as $att ) {
	UNMAM_Database::delete_references_by_attachment( $att );
	wp_delete_attachment( $att, true );
}

// The fixture's theme term for the deleted-theme case.
$gone = get_term_by( 'name', 'unmamfix-gone-theme', 'wp_theme' );
if ( $gone ) {
	wp_delete_term( $gone->term_id, 'wp_theme' );
}

delete_option( 'unmamfix_design' );

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'UNMAMFIX%' OR post_name LIKE 'unmamfix%'" );
echo "torn down, UNMAMFIX posts left: $left\n";
