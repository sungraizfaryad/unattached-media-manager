<?php
/**
 * Settings saved by 1.3.2, with Pages deliberately unticked: after the update the four
 * design types are ticked and Pages stays off. Restores the real settings at the end.
 *
 * Run: dev/flpwp.sh eval-file dev/fixture/design-upgrade-check.php
 */

$real = get_option( 'unmam_settings' );

$old_candidates = array_values( array_diff( unmam_get_scannable_post_type_candidates(), unmam_design_post_types() ) );

$shape                          = $real;
$shape['scan_post_types_known'] = $old_candidates;
$shape['scan_post_types']       = array_values( array_diff( $old_candidates, array( 'page' ) ) );
update_option( 'unmam_settings', $shape );

// Read several times: the reconcile must settle, not flip back and forth.
for ( $i = 0; $i < 3; $i++ ) {
	$s = Unattached_Media_Manager::get_setting();
}

$fail = 0;
foreach ( unmam_design_post_types() as $type ) {
	$on = in_array( $type, $s['scan_post_types'], true );
	$fail += $on ? 0 : 1;
	printf( "%-5s %s ticked\n", $on ? 'ok' : 'FAIL', $type );
}

$page_off = ! in_array( 'page', $s['scan_post_types'], true );
$fail    += $page_off ? 0 : 1;
printf( "%-5s page still unticked\n", $page_off ? 'ok' : 'FAIL' );

update_option( 'unmam_settings', $real );
echo "\nfailures: $fail\n";
