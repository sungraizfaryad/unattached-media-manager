<?php
/**
 * Removes everything seed.php created.
 *
 * Matches on the title prefix with a direct query rather than get_posts(), deliberately.
 * get_posts() needs the post type to still be registered, so removing the harness mu-plugin
 * before running this would strand the fixture posts with no way to find them again. Its
 * 's' search is also unreliable for attachments.
 *
 * Run with: wp eval-file dev/fixture/teardown.php
 */

global $wpdb;

$rows = $wpdb->get_results(
	"SELECT ID, post_type FROM {$wpdb->posts} WHERE post_title LIKE 'UNMAMFIX%'"
);

$deleted = 0;
foreach ( $rows as $row ) {
	if ( 'attachment' === $row->post_type ) {
		wp_delete_attachment( (int) $row->ID, true );
	} else {
		wp_delete_post( (int) $row->ID, true );
	}
	$deleted++;
}

$term = get_term_by( 'name', 'UNMAMFIX term', 'unmam_tax' );
if ( $term ) {
	wp_delete_term( $term->term_id, 'unmam_tax' );
}

delete_option( 'unmamfix_random_blob' );

$css = get_stylesheet_directory() . '/unmam-fixture.css';
if ( file_exists( $css ) ) {
	unlink( $css );
}

$wpdb->delete( UNMAM_Database::get_table_name( 'references' ), array( 'attachment_id' => 999999999 ) );

// Say what is left rather than assuming, so a partial teardown is visible.
$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'UNMAMFIX%'" );
$orphan    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . UNMAM_Database::get_table_name( 'references' ) . ' WHERE attachment_id = 999999999' );
$css_left  = file_exists( $css ) ? 1 : 0;

printf(
	"teardown: %d post(s) deleted. remaining UNMAMFIX rows: %d, orphan ref rows: %d, css file left: %d\n",
	$deleted,
	$remaining,
	$orphan,
	$css_left
);
