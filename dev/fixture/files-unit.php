<?php
/**
 * Unit checks for the 1.4.0 file scan: extractor, walker, piece reader.
 *
 * Makes three throwaway images (unmamfix-u-1..3.png), runs each section, deletes them.
 * Pass a section name to run just that one.
 *
 * Run: dev/flpwp.sh eval-file dev/fixture/files-unit.php [section]
 */

require_once ABSPATH . 'wp-admin/includes/image.php';

$only = empty( $args ) ? '' : $args[0];
$fail = 0;
$ok   = function ( $cond, $label ) use ( &$fail ) {
	if ( ! $cond ) {
		$fail++;
	}
	printf( "%-5s %s\n", $cond ? 'ok' : 'FAIL', $label );
};

$make_image = function ( $n ) {
	$dir  = wp_upload_dir();
	$path = $dir['path'] . '/unmamfix-u-' . $n . '.png';
	$im   = imagecreatetruecolor( 40, 40 );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 20 * $n, 90, 160 ) );
	imagepng( $im, $path );
	imagedestroy( $im );

	$id = wp_insert_attachment( array(
		'post_title'     => 'UNMAMFIX u' . $n,
		'post_mime_type' => 'image/png',
		'post_status'    => 'inherit',
	), $path );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
	return $id;
};

// Runs $fn with the uploads baseurl swapped, then puts it back.
$with_baseurl = function ( $baseurl, $fn ) {
	$swap = function ( $dirs ) use ( $baseurl ) {
		$dirs['baseurl'] = $baseurl;
		return $dirs;
	};
	add_filter( 'upload_dir', $swap );
	try {
		$fn();
	} finally {
		remove_filter( 'upload_dir', $swap );
	}
};

$fx  = array( 'ids' => array(), 'urls' => array() );
$ids = array();

// A section is a block that gets the fixture; a throw inside it counts as one failure.
$section = function ( $name, $fn ) use ( $only, $ok, &$fx ) {
	if ( '' !== $only && $only !== $name ) {
		return;
	}
	echo "\n-- $name\n";
	try {
		$fn( $fx );
	} catch ( Throwable $e ) {
		$ok( false, "$name threw " . get_class( $e ) . ': ' . $e->getMessage() );
	}
};

try {
	foreach ( array( 1, 2, 3 ) as $n ) {
		$ids[ $n ]       = $make_image( $n );
		$fx['ids'][ $n ] = $ids[ $n ];
		$fx['urls'][ $n ] = wp_get_attachment_url( $ids[ $n ] );
	}

	$section( 'extractor', function ( $fx ) use ( $ok, $with_baseurl ) {
		list( $id1, $id2, $id3 ) = array( $fx['ids'][1], $fx['ids'][2], $fx['ids'][3] );
		list( $u1, $u2, $u3 )    = array( $fx['urls'][1], $fx['urls'][2], $fx['urls'][3] );

		$baseurl = untrailingslashit( wp_get_upload_dir()['baseurl'] );
		$p1      = substr( $u1, strlen( $baseurl ) + 1 );
		$root2   = wp_parse_url( $u2, PHP_URL_PATH );
		$proto3  = preg_replace( '#^https?:#', '', $u3 );
		$mixed   = '<img class="wp-image-' . $id1 . '" src="' . $u1 . '"> <div style="background:url(' . $root2 . ')"></div> <link href="' . $proto3 . '">';

		$ok( 0 === strpos( $u1, $baseurl . '/' ) && '' !== $p1, 'precondition: fixture image sits under the uploads baseurl' );

		// Parity with 1.3.3, recorded by running the old extract_from_text() on these strings first.
		$before     = array( $id1 => $u1 );
		$before_rel = array( $id1 => $u1, $id2 => $u2, $id3 => $u3 );
		$ok( $before === UNMAM_Reference_Extractor::extract_from_text( $mixed ), 'parity, absolute' );
		$ok( $before_rel === UNMAM_Reference_Extractor::extract_from_text( $mixed, true ), 'parity, relative' );

		// A relative URL never overwrites an id entry.
		$text = 'class="wp-image-' . $id2 . '" ' . $root2;
		$ok( array( $id2 => $id2 ) === UNMAM_Reference_Extractor::extract_from_text( $text, true ), 'parity, relative keeps the id entry' );
		// A later absolute URL for the same file overwrites an earlier one.
		$http1  = preg_replace( '#^https?:#', 'http:', $u1 );
		$https1 = preg_replace( '#^https?:#', 'https:', $u1 );
		$ok( array( $id1 => $https1 ) === UNMAM_Reference_Extractor::extract_from_text( $http1 . ' ' . $https1 ), 'parity, later absolute URL wins' );
		// Numbers that merely start like an attachment id do not count.
		$ok( array() === UNMAM_Reference_Extractor::extract_from_text( 'wp-image-999999 wp-image-' . $id3 . '9', true ), 'parity, unknown ids dropped' );
		$ok( array() === UNMAM_Reference_Extractor::extract_from_text( 12345, true ), 'parity, non-string input' );

		// Plain finds.
		$c = UNMAM_Reference_Extractor::find_candidates( $mixed );
		$ok( array( array( 'type' => 'id', 'value' => $id1 ), array( 'type' => 'url', 'value' => $u1 ) ) === $c, 'find: id then absolute url, nothing relative by default' );
		$c = UNMAM_Reference_Extractor::find_candidates( $mixed, array( 'relative' => true ) );
		$ok( array( 'id', 'url', 'url', 'url' ) === array_column( $c, 'type' ) && array( $id1, $u1, $u2, $u3 ) === array_column( $c, 'value' ), 'find: relative urls made absolute, in order' );
		$c = UNMAM_Reference_Extractor::find_candidates( "wp-image-$id1 wp-image-$id1 $u2 $u2" );
		$ok( 2 === count( $c ), 'find: de-duplicated on type plus value' );
		$ok( array() === UNMAM_Reference_Extractor::find_candidates( "uploads/$p1 wp_get_attachment_url( $id1 )" ), 'find: tails and calls are off by default' );

		// Tails.
		$c = UNMAM_Reference_Extractor::find_candidates( "a{background:url(../../../uploads/$p1)}", array( 'tails' => true ) );
		$ok( array( array( 'type' => 'exact_url', 'value' => $baseurl . '/' . $p1 ) ) === $c, 'tail from ../ path' );
		$c = UNMAM_Reference_Extractor::find_candidates( "content_url( '/uploads/$p1' )", array( 'tails' => true ) );
		$ok( 1 === count( $c ) && $id1 === UNMAM_Reference_Extractor::resolve_candidate( $c[0] ), 'tail from content_url()' );
		$c = UNMAM_Reference_Extractor::find_candidates( $u1, array( 'relative' => true, 'tails' => true ) );
		$ok( 1 === count( $c ) && 'url' === $c[0]['type'], 'full URL not matched again as a tail' );
		$c = UNMAM_Reference_Extractor::find_candidates( $root2, array( 'relative' => true, 'tails' => true ) );
		$ok( 1 === count( $c ) && 'url' === $c[0]['type'], 'root-relative URL not matched again as a tail' );
		$c = UNMAM_Reference_Extractor::find_candidates( $root2, array( 'tails' => true ) );
		$ok( 1 === count( $c ) && 'exact_url' === $c[0]['type'], 'root-relative URL is a tail when relative is off' );
		$ok( array() === UNMAM_Reference_Extractor::find_candidates( "myuploads/$p1", array( 'tails' => true ) ), 'tail needs a boundary' );
		$ok( array() === UNMAM_Reference_Extractor::find_candidates( "a-uploads/$p1", array( 'tails' => true ) ), 'tail needs a boundary, after a hyphen' );
		$ok( 0 === UNMAM_Reference_Extractor::resolve_candidate( array( 'type' => 'exact_url', 'value' => $baseurl . '/2001/01/unmamfix-u-1.png' ) ), 'exact_url: right name, wrong folder, no filename fallback' );
		$ok( $id1 === UNMAM_Reference_Extractor::resolve_candidate( array( 'type' => 'url', 'value' => $baseurl . '/2001/01/unmamfix-u-1.png' ) ), 'url: same string still resolves by filename' );

		// Calls.
		$c = UNMAM_Reference_Extractor::find_candidates( "wp_get_attachment_image( $id1 ); wp_get_attachment_url( '$id2' ); wp_get_attachment_link( 0 );", array( 'calls' => true ) );
		$ok( array( $id1, $id2 ) === array_values( array_filter( array_map( array( 'UNMAM_Reference_Extractor', 'resolve_candidate' ), $c ) ) ), 'literal ids in calls' );
		$ok( 0 === UNMAM_Reference_Extractor::resolve_candidate( array( 'type' => 'id', 'value' => 999999 ) ), 'non-attachment id rejected' );
		$c = UNMAM_Reference_Extractor::find_candidates( "get_attached_file( \"$id3\", true ); image_downsize( $id3, 'full' ); wp_get_attachment_image( \$id ); my_wp_get_attachment_url( $id2 );", array( 'calls' => true ) );
		$ok( array( array( 'type' => 'id', 'value' => $id3 ) ) === $c, 'calls: quoted, extra arguments, variables ignored, prefix boundary, de-duplicated' );
		$ok( array() === UNMAM_Reference_Extractor::find_candidates( "wp_get_attachment_image( $id1 )" ), 'calls are off by default' );

		// Candidates come back ids, urls, relative urls, tails, calls; the output has no extra keys.
		$c = UNMAM_Reference_Extractor::find_candidates( "wp_get_attachment_url( $id3 ) uploads/$p1 $u2 wp-image-$id1", array( 'tails' => true, 'calls' => true, 'relative' => true ) );
		$ok( array( 'id', 'url', 'exact_url', 'id' ) === array_column( $c, 'type' ), 'find: pass order' );
		$ok( array( 'type', 'value' ) === array_keys( $c[0] ), 'find: candidate keys' );
		$ok( 0 === UNMAM_Reference_Extractor::resolve_candidate( array( 'type' => 'nope', 'value' => $id1 ) ), 'unknown candidate type resolves to 0' );

		// Tail anchor, read fresh on every call; $with_baseurl() swaps the uploads baseurl.
		$ok( 'uploads' === UNMAM_Reference_Extractor::tail_anchor(), 'tail anchor, default' );
		$with_baseurl( home_url( '/wp-content/uploads/sites/2' ), function () use ( $ok ) {
			$ok( 'uploads/sites/2' === UNMAM_Reference_Extractor::tail_anchor(), 'tail anchor, multisite path' );
		} );
		$with_baseurl( home_url( '/wp-content/uploads/' ), function () use ( $ok ) {
			$ok( 'uploads' === UNMAM_Reference_Extractor::tail_anchor(), 'tail anchor, trailing slash' );
		} );
		$with_baseurl( home_url( '/files' ), function () use ( $ok ) {
			$ok( 'files' === UNMAM_Reference_Extractor::tail_anchor(), 'tail anchor, uploads outside wp-content' );
		} );
		$with_baseurl( home_url( '/wp-contentx/uploads' ), function () use ( $ok ) {
			$ok( 'uploads' === UNMAM_Reference_Extractor::tail_anchor(), 'tail anchor, wp-content prefix must end at a slash' );
		} );
		$with_baseurl( 'https://cdn.example.com', function () use ( $ok, $p1 ) {
			$ok( '' === UNMAM_Reference_Extractor::tail_anchor(), 'tail anchor, uploads at a domain root' );
			$ok( array() === UNMAM_Reference_Extractor::find_candidates( "../uploads/$p1", array( 'tails' => true ) ), 'no tails without an anchor' );
		} );
		$with_baseurl( home_url( '/files' ), function () use ( $ok, $p1 ) {
			$c = UNMAM_Reference_Extractor::find_candidates( "x/files/$p1", array( 'tails' => true ) );
			$ok( array( array( 'type' => 'exact_url', 'value' => home_url( '/files/' . $p1 ) ) ) === $c, 'tail built on the current baseurl' );
		} );
		$ok( 'uploads' === UNMAM_Reference_Extractor::tail_anchor(), 'tail anchor, back to default' );

		// Size guard.
		$ok( array() === UNMAM_Reference_Extractor::find_candidates( str_repeat( ' ', 1048577 ) . $u1 ), 'over MAX_TEXT_LENGTH returns nothing' );
		$ok( 1 === count( UNMAM_Reference_Extractor::find_candidates( str_repeat( ' ', 1048576 - strlen( $u1 ) ) . $u1 ) ), 'exactly MAX_TEXT_LENGTH still scanned' );
	} );
} finally {
	foreach ( $ids as $id ) {
		wp_delete_attachment( $id, true );
	}
}

echo "\nfailures: $fail\n";
