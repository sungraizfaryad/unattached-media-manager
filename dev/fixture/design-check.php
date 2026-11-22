<?php
/**
 * Asserts the design fixture. By default it indexes the fixture posts itself, with
 * auto-attach switched on for the run. Pass `noindex` to check what a full scan left.
 *
 * Run: dev/flpwp.sh eval-file dev/fixture/design-check.php [noindex]
 */

global $wpdb;

$fx = get_option( 'unmamfix_design' );
if ( ! $fx ) {
	echo "run design-seed.php first\n";
	return;
}

// Edit links depend on capabilities.
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );

$t    = UNMAM_Database::get_table_name( 'references' );
$fail = 0;
$ok   = function ( $cond, $label ) use ( &$fail ) {
	if ( ! $cond ) {
		$fail++;
	}
	printf( "%-5s %s\n", $cond ? 'ok' : 'FAIL', $label );
};

$settings = Unattached_Media_Manager::get_setting();
foreach ( unmam_design_post_types() as $type ) {
	$ok( in_array( $type, $settings['scan_post_types'], true ), "$type ticked" );
}

$design_ids = array_merge( array( $fx['css_id'] ), array_values( array_filter( $fx['posts'] ) ) );

if ( empty( $args ) || 'noindex' !== $args[0] ) {
	$real = get_option( 'unmam_settings' );
	update_option( 'unmam_settings', array_merge( $real, array( 'auto_attach' => true ) ) );
	foreach ( $design_ids as $pid ) {
		UNMAM_Scanner::instance()->index_post( $pid );
	}
	update_option( 'unmam_settings', $real );
}

$rows = function ( $key ) use ( $wpdb, $t, $fx ) {
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE attachment_id = %d", $fx['img'][ $key ] ), ARRAY_A );
};

$active_name   = wp_get_theme()->get( 'Name' );
$inactive_name = wp_get_theme( 'genesis-block-theme' )->get( 'Name' );

// image => [ expected label, belongs to active theme ]
$expect = array(
	'dcssabs'   => array( 'Additional CSS', true ),
	'dcssroot'  => array( 'Additional CSS', true ),
	'dcssproto' => array( 'Additional CSS', true ),
	'dpart'     => array( 'Template part: UNMAMFIX sidebar', true ),
	'dtpl'      => array( 'Template: UNMAMFIX template', true ),
	'dstyles'   => array( 'Styles', true ),
	'dinactive' => array( 'Template part: UNMAMFIX inactive part', false ),
);

foreach ( $expect as $key => $e ) {
	$found = $rows( $key );
	$ok( count( $found ) > 0, "$key referenced" );
	if ( ! $found ) {
		continue;
	}

	$src  = UNMAM_Database::describe_source( $found[0] );
	$want = sprintf( '%s (%s)', $e[0], $e[1] ? $active_name : $inactive_name );
	$ok( $src && $want === $src['title'], "$key title: " . ( $src ? $src['title'] : 'null' ) );
	$ok( $src && ( $e[1] ? ! empty( $src['edit_link'] ) : empty( $src['edit_link'] ) ), "$key edit link " . ( $e[1] ? 'present' : 'absent' ) );
	$ok( $src && null === $src['view_link'], "$key no view link" );
	$ok( 0 === (int) get_post_field( 'post_parent', $fx['img'][ $key ] ), "$key not attached" );
}

$ok( 0 === count( $rows( 'dgone' ) ), 'deleted theme skipped' );

$suggested = array_map( 'intval', wp_list_pluck( UNMAM_Database::get_used_but_unattached(), 'attachment_id' ) );
$ok( ! array_intersect( $suggested, array_values( $fx['img'] ) ), 'no fixture image offered for attach' );
$ok( count( UNMAM_Database::get_used_but_unattached() ) === UNMAM_Database::get_used_but_unattached_count(), 'attach list and count agree' );
$ok( UNMAM_Database::get_used_but_unattached_count() === UNMAM_Database::get_statistics()['used_but_unattached'], 'dashboard count agrees' );

// Replace Media on Additional CSS. Done last because it changes the fixture.
if ( empty( $args ) || 'noindex' !== $args[0] ) {
	UNMAM_Attachment_Manager::instance()->replace_references( $fx['img']['dcssabs'], $fx['img']['dreplace'] );
	$css = get_post_field( 'post_content', $fx['css_id'], 'raw' );
	$ok( false !== strpos( $css, wp_get_attachment_url( $fx['img']['dreplace'] ) ), 'replace: new URL in Additional CSS' );
	$ok( false === strpos( $css, wp_get_attachment_url( $fx['img']['dcssabs'] ) ), 'replace: old URL gone' );
	$ok( false !== strpos( $css, '.unmamfix-c{' ), 'replace: rest of the CSS intact' );
}

echo "\nfailures: $fail\n";
