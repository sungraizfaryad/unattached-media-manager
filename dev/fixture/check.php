<?php
global $wpdb;
$t = UNMAM_Database::get_table_name( 'references' );
$cases = array(
	'pubcpt'      => 'public CPT registered after settings (featured image)',
	'hiddencpt'   => 'non-public show_ui CPT, Bricks-shaped (postmeta)',
	'termmeta'    => 'term meta, plain attachment ID',
	'termwysiwyg' => 'term meta, WYSIWYG HTML',
	'termthumb'   => 'term meta, WooCommerce-style thumbnail_id',
	'oddoption'   => 'option with a non-matching name',
	'themecss'    => 'upload URL hardcoded in theme CSS',
);
printf( "%-14s %-10s %-8s %s\n", 'CASE', 'ID', 'REFS', 'WHAT' );
$fail = 0;
foreach ( $cases as $k => $desc ) {
	$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_title=%s AND post_type='attachment'", 'UNMAMFIX ' . $k ) );
	$n  = $id ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE attachment_id=%d", $id ) ) : -1;
	if ( $n < 1 ) { $fail++; }
	printf( "%-14s %-10d %-8s %s\n", $k, $id, ( $n > 0 ? $n : 'NONE' ), $desc );
}
$orphans = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT r.attachment_id) FROM $t r LEFT JOIN $wpdb->posts p ON p.ID=r.attachment_id AND p.post_type='attachment' WHERE p.ID IS NULL" );
echo "\nundetected cases : $fail of " . count( $cases ) . "\n";
echo "orphan ref ids   : $orphans\n";
echo "unused count     : " . UNMAM_Database::get_unused_count() . "\n";
