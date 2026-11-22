<?php
/**
 * One throwaway image per design-content case, and the design posts that use them.
 * Additional CSS is appended to, never replaced; teardown puts the original back.
 *
 * Run: dev/flpwp.sh eval-file dev/fixture/design-seed.php
 */

require_once ABSPATH . 'wp-admin/includes/image.php';

if ( get_option( 'unmamfix_design' ) ) {
	echo "already seeded - run design-teardown.php first\n";
	return;
}

$active   = get_stylesheet();
$inactive = 'genesis-block-theme';
if ( $inactive === $active || ! wp_get_theme( $inactive )->exists() ) {
	echo "need $inactive installed and not active\n";
	return;
}

function unmam_ds_image( $name ) {
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

// Old date so a real global styles post, if any, still wins core's newest-first lookup.
function unmam_ds_post( $type, $slug, $title, $content, $theme ) {
	$id = wp_insert_post( wp_slash( array(
		'post_type'    => $type,
		'post_name'    => $slug,
		'post_title'   => $title,
		'post_status'  => 'publish',
		'post_content' => $content,
		'post_date'    => '2000-01-01 00:00:00',
	) ), true );

	if ( is_wp_error( $id ) ) {
		echo "$type $slug: " . $id->get_error_message() . "\n";
		return 0;
	}

	wp_set_object_terms( $id, $theme, 'wp_theme' );
	if ( 'wp_template_part' === $type ) {
		wp_set_object_terms( $id, 'uncategorized', 'wp_template_part_area' );
	}
	return $id;
}

// As an admin, like a real Site Editor save. With no user, kses filters the content and
// the global styles sanitizer may drop the background image.
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );

global $wpdb;
$max_id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );

$img = array();
foreach ( array( 'dcssabs', 'dcssroot', 'dcssproto', 'dpart', 'dtpl', 'dstyles', 'dinactive', 'dgone', 'dreplace' ) as $n ) {
	$img[ $n ] = unmam_ds_image( $n );
}
$url  = function ( $n ) use ( $img ) {
	return wp_get_attachment_url( $img[ $n ] );
};
$path = function ( $n ) use ( $url ) {
	return wp_parse_url( $url( $n ), PHP_URL_PATH );
};
$host = wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_HOST );

// Additional CSS for the active theme: absolute, root-relative, protocol-relative.
$css_post = wp_get_custom_css_post( $active );
$backup   = array(
	'existed' => (bool) $css_post,
	'css'     => $css_post ? $css_post->post_content : '',
);
$css = $backup['css'] . "\n/* UNMAMFIX */\n"
	. '.unmamfix-a{background:url(' . $url( 'dcssabs' ) . ")}\n"
	. '.unmamfix-b{background:url("' . $path( 'dcssroot' ) . "\")}\n"
	. '.unmamfix-c{background:url(//' . $host . $path( 'dcssproto' ) . ")}\n";
$css_result = wp_update_custom_css_post( $css, array( 'stylesheet' => $active ) );
if ( is_wp_error( $css_result ) ) {
	echo 'custom css: ' . $css_result->get_error_message() . "\n";
	return;
}

$posts = array();

// Gal's case: an absolute URL in a sidebar part, no attachment id anywhere.
$posts['dpart'] = unmam_ds_post( 'wp_template_part', 'unmamfix-sidebar', 'UNMAMFIX sidebar',
	"<!-- wp:image {\"sizeSlug\":\"full\"} -->\n<figure class=\"wp-block-image size-full\"><img src=\"" . $url( 'dpart' ) . "\" alt=\"\"/></figure>\n<!-- /wp:image -->",
	$active );

$posts['dtpl'] = unmam_ds_post( 'wp_template', 'unmamfix-template', 'UNMAMFIX template',
	'<!-- wp:cover {"url":"' . $url( 'dtpl' ) . '","dimRatio":50} -->' . "\n"
	. '<div class="wp-block-cover"><img class="wp-block-cover__image-background" alt="" src="' . $url( 'dtpl' ) . '" data-object-fit="cover"/><div class="wp-block-cover__inner-container"></div></div>' . "\n"
	. '<!-- /wp:cover -->',
	$active );

// Default wp_json_encode() escapes slashes: the pre-JSON_UNESCAPED_SLASHES format.
$posts['dstyles'] = unmam_ds_post( 'wp_global_styles', 'unmamfix-global-styles', 'Custom Styles',
	wp_json_encode( array(
		'version'                     => 3,
		'isGlobalStylesUserThemeJSON' => true,
		'styles'                      => array( 'background' => array( 'backgroundImage' => array( 'url' => $url( 'dstyles' ), 'id' => $img['dstyles'] ) ) ),
		'settings'                    => new stdClass(),
	) ),
	$active );

$posts['dinactive'] = unmam_ds_post( 'wp_template_part', 'unmamfix-inactive-part', 'UNMAMFIX inactive part',
	'<!-- wp:image --><figure class="wp-block-image"><img src="' . $url( 'dinactive' ) . '" alt=""/></figure><!-- /wp:image -->',
	$inactive );

$posts['dgone'] = unmam_ds_post( 'wp_template_part', 'unmamfix-gone-part', 'UNMAMFIX gone part',
	'<!-- wp:image --><figure class="wp-block-image"><img src="' . $url( 'dgone' ) . '" alt=""/></figure><!-- /wp:image -->',
	'unmamfix-gone-theme' );

update_option( 'unmamfix_design', array(
	'max_id' => $max_id,
	'css_id' => (int) $css_result->ID,
	'css'    => $backup,
	'img'    => $img,
	'posts'  => $posts,
), false );

echo 'seeded ' . count( $img ) . ' images, ' . count( array_filter( $posts ) ) . " design posts, css post {$css_result->ID}\n";
