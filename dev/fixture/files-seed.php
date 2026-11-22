<?php
/**
 * Fixture for the 1.4.0 file scan.
 *
 * One throwaway image per case, a block theme, a plugin and a must-use plugin that mention
 * them. The files go into the real themes, plugins and mu-plugins folders and are never
 * activated. Every path written and every image made is recorded in the option
 * unmamfix_files, which files-teardown.php reads.
 *
 * Run: dev/flpwp.sh eval-file dev/fixture/files-seed.php
 */

require_once ABSPATH . 'wp-admin/includes/image.php';

if ( get_option( 'unmamfix_files' ) ) {
	echo "already seeded - run files-teardown.php first\n";
	return;
}

$theme_dir  = WP_CONTENT_DIR . '/themes/unmam-fixture-theme';
$plugin_dir = WP_PLUGIN_DIR . '/unmam-fixture-plugin';
$mu_file    = WPMU_PLUGIN_DIR . '/unmamfix-mu.php';

foreach ( array( $theme_dir, $plugin_dir, $mu_file ) as $path ) {
	if ( file_exists( $path ) || is_link( $path ) ) {
		echo "$path is already there and is not ours\n";
		return;
	}
}

function unmam_fx_image( $name ) {
	$dir  = wp_upload_dir();
	$path = $dir['path'] . '/unmamfix-' . $name . '.png';
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

$found_cases = array(
	'cssabs', 'cssroot', 'cssproto', 'cssrel', 'contenturl', 'phpimg', 'phpurl', 'sidebar', 'themejson',
	'json1', 'json3', 'wpimage', 'bigstraddle', 'bigpast', 'plugin', 'mu', 'longpath', 'badutf8',
	'n9a', 'n9b', 'n10a', 'n10b',
);
for ( $i = 1; $i <= 20; $i++ ) {
	$found_cases[] = sprintf( 'many%02d', $i );
}
$ignored_cases = array( 'nm', 'vendor', 'git', 'md', 'huge' );

$found   = array();
$ignored = array();
foreach ( $found_cases as $case ) {
	$found[ $case ] = unmam_fx_image( $case );
}
foreach ( $ignored_cases as $case ) {
	$ignored[ $case ] = unmam_fx_image( 'ign-' . $case );
}
$spare = array( 'rel2' => unmam_fx_image( 'rel2' ) );

$base = untrailingslashit( wp_get_upload_dir()['baseurl'] );
$url  = function ( $id ) {
	return wp_get_attachment_url( $id );
};
$tail = function ( $id ) use ( $url, $base ) {
	return substr( $url( $id ), strlen( $base ) + 1 );
};

// Not an attachment, whatever the number looks like.
$bad_id = 999999;
while ( get_post( $bad_id ) ) {
	$bad_id++;
}

// Everything below is recorded as it is made, so teardown can undo exactly this.
$made = array();
$mkdirs = function ( $dir ) use ( &$made ) {
	$missing = array();
	while ( ! is_dir( $dir ) ) {
		array_unshift( $missing, $dir );
		$dir = dirname( $dir );
	}
	foreach ( $missing as $m ) {
		mkdir( $m, 0755 );
		$made[] = $m;
	}
};
$put = function ( $path, $body ) use ( &$made, $mkdirs ) {
	$mkdirs( dirname( $path ) );
	file_put_contents( $path, $body );
	$made[] = $path;
};
$pad_to = function ( $text, $length ) {
	return $text . str_repeat( ' ', max( 0, $length - strlen( $text ) ) );
};

try {
	// Theme, with the cases that live in the usual places.
	$put( "$theme_dir/style.css", "/*\nTheme Name: Unmam Fixture Theme\nVersion: 1.0\n*/\n"
		. '.a{background:url("' . $url( $found['cssabs'] ) . "\")}\n"
		. '.b{background:url(' . wp_parse_url( $url( $found['cssroot'] ), PHP_URL_PATH ) . ")}\n"
		. '.c{background:url("' . preg_replace( '#^https?:#', '', $url( $found['cssproto'] ) ) . "\")}\n" );
	$put( "$theme_dir/templates/index.html", '<!-- wp:template-part {"slug":"sidebar"} /-->' );
	$put( "$theme_dir/parts/sidebar.html", '<!-- wp:image --><figure class="wp-block-image"><img src="' . $url( $found['sidebar'] ) . '" alt=""/></figure><!-- /wp:image -->' );
	$put( "$theme_dir/theme.json", wp_json_encode( array(
		'version' => 3,
		'styles'  => array( 'background' => array( 'backgroundImage' => array( 'url' => $url( $found['themejson'] ) ) ) ),
	), JSON_UNESCAPED_SLASHES ) );
	$put( "$theme_dir/patterns/p.php", "<?php\n/**\n * Title: Fixture pattern\n * Slug: unmam-fixture-theme/p\n */\n?>\n"
		. '<!-- wp:image {"id":' . $found['wpimage'] . '} --><figure class="wp-block-image"><img alt="" class="wp-image-' . $found['wpimage'] . "\"/></figure><!-- /wp:image -->\n" );
	$put( "$theme_dir/functions.php", "<?php\nadd_action( 'wp_head', function () {\n"
		. "\techo wp_get_attachment_image( {$found['phpimg']}, 'full' );\n"
		. "\techo wp_get_attachment_url( '{$found['phpurl']}' );\n"
		. "\techo wp_get_attachment_image( $bad_id );\n"
		. "\techo content_url( '/uploads/" . $tail( $found['contenturl'] ) . "' );\n"
		. "} );\n" );
	$put( "$theme_dir/css/rel.css", '.r{background:url(../../../uploads/' . $tail( $found['cssrel'] ) . ")}\n" );

	// JSON: plain, with escaped slashes, and JSON stored inside JSON.
	$put( "$theme_dir/data/one.json", wp_json_encode( array( 'img' => $url( $found['json1'] ) ) ) );
	$put( "$theme_dir/data/three.json", '{"blob":"' . str_replace( '/', '\\\\\\/', $url( $found['json3'] ) ) . '"}' );

	// 2,000 distinct URLs, 20 of them real. A lookup costs queries, so this is the file that
	// runs out of time half way through.
	$many = array();
	$real = 0;
	for ( $i = 0; $i < 2000; $i++ ) {
		if ( 50 === $i % 100 ) {
			$real++;
			$many[] = $url( $found[ sprintf( 'many%02d', $real ) ] );
		} else {
			$many[] = $base . sprintf( '/2026/11/unmamfix-nope-%04d.jpg', $i );
		}
	}
	$put( "$theme_dir/data/many.json", wp_json_encode( $many ) );

	// Over 1 MB: one URL across the piece boundary, one past it.
	$boundary = UNMAM_File_Scanner::PIECE_BYTES;
	$css      = $pad_to( '/* big */', $boundary - 60 ) . '.s{background:url(' . $url( $found['bigstraddle'] ) . ');}';
	$css      = $pad_to( $css, $boundary + 4000 ) . '.p{background:url(' . $url( $found['bigpast'] ) . ');}';
	$put( "$theme_dir/css/big.css", $pad_to( $css, $boundary + 8000 ) );

	// Over 10 MB: skipped and reported, so the image in it stays unused.
	$put( "$theme_dir/huge.css", $pad_to( '.h{background:url(' . $url( $ignored['huge'] ) . ');}', UNMAM_File_Walker::MAX_FILE_BYTES + 1000 ) );

	// Never walked.
	$put( "$theme_dir/node_modules/n.css", '.n{background:url(' . $url( $ignored['nm'] ) . ')}' );
	$put( "$theme_dir/vendor/v.css", '.v{background:url(' . $url( $ignored['vendor'] ) . ')}' );
	$put( "$theme_dir/.git/g.css", '.g{background:url(' . $url( $ignored['git'] ) . ')}' );
	$put( "$theme_dir/notes.md", 'Logo: ' . $url( $ignored['md'] ) . "\n" );

	// Folders whose names compare differently as numbers and as strings.
	foreach ( array( '9/a' => 'n9a', '9/b' => 'n9b', '10/a' => 'n10a', '10/b' => 'n10b' ) as $file => $case ) {
		$put( "$theme_dir/$file.css", '.x{background:url(' . $url( $found[ $case ] ) . ')}' );
	}

	// A relative path longer than 255 characters, on disk, each name well under the limit.
	$deep = '';
	for ( $i = 1; $i <= 5; $i++ ) {
		$deep .= "/l$i-" . str_repeat( 'p', 56 );
	}
	$put( "$theme_dir$deep/deep.css", '.d{background:url(' . $url( $found['longpath'] ) . ')}' );

	// A link back to the folder it sits in.
	symlink( '.', "$theme_dir/loop" );
	$made[] = "$theme_dir/loop";

	// Plugin and must-use plugin.
	$put( "$plugin_dir/unmam-fixture-plugin.php", "<?php\n/**\n * Plugin Name: Unmam Fixture Plugin\n */\n\$unmamfix_logo = '" . $url( $found['plugin'] ) . "';\n" );
	$put( $mu_file, "<?php\n// UNMAMFIX\n\$unmamfix_mu = '" . $url( $found['mu'] ) . "';\n" );
} catch ( Throwable $e ) {
	echo 'seed failed: ' . $e->getMessage() . "\n";
}

update_option( 'unmamfix_files', array(
	'found'   => $found,
	'ignored' => $ignored,
	'spare'   => $spare,
	'bad_id'  => $bad_id,
	'paths'   => $made,
	'theme'   => $theme_dir,
	'plugin'  => $plugin_dir,
	'mu'      => $mu_file,
), false );

printf( "seeded %d images, %d paths\n", count( $found ) + count( $ignored ) + count( $spare ), count( $made ) );
