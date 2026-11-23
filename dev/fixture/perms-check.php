<?php
/**
 * Checks the permission rules on the media AJAX actions.
 *
 * Makes two authors, an editor, and per case a draft post and two images for each author, runs
 * every handler as each of them, and checks both the response and what is left in the database.
 * Everything it made is removed at the end, pass or fail. An existing administrator is only
 * impersonated in memory; that user is never changed.
 *
 * Run: dev/flpwp.sh eval-file dev/fixture/perms-check.php
 */

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( 'class-unmam-admin.php', 'class-unmam-media-modal.php', 'class-unmam-bulk-actions.php' ) as $unmam_file ) {
	require_once UNMAM_PLUGIN_DIR . 'includes/admin/' . $unmam_file;
}

$fail = 0;
$ok   = function ( $cond, $label ) use ( &$fail ) {
	if ( ! $cond ) {
		$fail++;
	}
	printf( "%-5s %s\n", $cond ? 'ok' : 'FAIL', $label );
};

$made = array(
	'users'       => array(),
	'posts'       => array(),
	'attachments' => array(),
);
$seq  = 0;

$admin_id = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ) )[0];
$bulk     = UNMAM_Bulk_Actions::instance();
$modal    = UNMAM_Media_Modal::instance();
$admin    = UNMAM_Admin::instance();
$denied   = 'Permission denied';

// Runs a handler as a user and returns array( success, data ). wp_send_json_*() ends in
// wp_die(), so that is swapped for one that throws.
$call = function ( $user_id, $object, $method, $nonce_action, array $params ) {
	wp_set_current_user( $user_id );
	$_POST = $_REQUEST = array_merge( array( 'nonce' => wp_create_nonce( $nonce_action ) ), $params );
	$stop  = function () {
		return function () {
			throw new RuntimeException( 'wp_die' );
		};
	};
	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_die_ajax_handler', $stop );
	ob_start();
	try {
		$object->$method();
	} catch ( RuntimeException $e ) {
		// Expected: the response has been printed.
	} finally {
		$out = ob_get_clean();
		remove_filter( 'wp_die_ajax_handler', $stop );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$_POST = $_REQUEST = array();
	}

	$json = json_decode( (string) $out, true );
	if ( ! is_array( $json ) || ! array_key_exists( 'success', $json ) ) {
		return array( false, 'no response: ' . $out );
	}
	return array( (bool) $json['success'], isset( $json['data'] ) ? $json['data'] : null );
};
$bulk_call = function ( $user_id, $method, array $params ) use ( $call, $bulk ) {
	return $call( $user_id, $bulk, $method, 'unmam_admin_nonce', $params );
};
$modal_call = function ( $user_id, $method, array $params ) use ( $call, $modal ) {
	return $call( $user_id, $modal, $method, 'unmam_media_modal_nonce', $params );
};
$admin_call = function ( $user_id, $method, array $params ) use ( $call, $admin ) {
	return $call( $user_id, $admin, $method, 'unmam_admin_nonce', $params );
};

$make_user = function ( $role ) use ( &$made ) {
	$login = 'unmamfix_' . $role . '_' . wp_generate_password( 6, false, false );
	$id    = wp_insert_user( array(
		'user_login' => strtolower( $login ),
		'user_pass'  => wp_generate_password( 20 ),
		'user_email' => strtolower( $login ) . '@example.invalid',
		'role'       => $role,
	) );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	$made['users'][] = (int) $id;
	return (int) $id;
};

$make_image = function ( $author_id, $label ) use ( &$made, &$seq ) {
	$dir = wp_upload_dir();
	wp_mkdir_p( $dir['path'] );
	$path = $dir['path'] . '/unmamfix-perms-' . ( ++$seq ) . '.png';
	file_put_contents( $path, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' ) );
	$id = wp_insert_attachment( array(
		'post_title'     => 'UNMAMFIX perms ' . $label,
		'post_mime_type' => 'image/png',
		'post_status'    => 'inherit',
		'post_author'    => $author_id,
	), $path );
	$made['attachments'][] = (int) $id;
	return (int) $id;
};

$make_post = function ( $author_id, $content = '' ) use ( &$made ) {
	$id = wp_insert_post( wp_slash( array(
		'post_type'    => 'post',
		'post_status'  => 'draft',
		'post_title'   => 'UNMAMFIX perms draft',
		'post_content' => $content,
		'post_author'  => $author_id,
	) ) );
	$made['posts'][] = (int) $id;
	return (int) $id;
};

$parent_of = function ( $id ) {
	clean_post_cache( $id );
	return (int) get_post_field( 'post_parent', $id );
};
$safe_of   = function ( $id ) {
	return (bool) get_post_meta( $id, '_mui_marked_safe', true );
};
$unused_of = function ( $id ) {
	return '' !== (string) get_post_meta( $id, '_mui_marked_unused', true );
};
$exists    = function ( $id ) {
	clean_post_cache( $id );
	return null !== get_post( $id ) && 'attachment' === get_post_type( $id );
};
$status_of = function ( $id ) {
	clean_post_cache( $id );
	return (string) get_post_field( 'post_status', $id, 'raw' );
};
$trash     = function ( $image_id ) use ( $admin_id ) {
	wp_set_current_user( $admin_id );
	wp_trash_post( $image_id );
};
$adopt     = function ( $image_id, $post_id ) {
	wp_update_post( array( 'ID' => $image_id, 'post_parent' => $post_id ) );
};

try {
	wp_set_current_user( $admin_id );
	$a      = $make_user( 'author' );
	$b      = $make_user( 'author' );
	$editor = $make_user( 'editor' );

	// Two authors, each with a draft and two images. Fresh for every case so none leans on another.
	$set = function () use ( $make_post, $make_image, $a, $b, $admin_id ) {
		wp_set_current_user( $admin_id );
		return array(
			'a' => array(
				'post' => $make_post( $a ),
				'img1' => $make_image( $a, 'a1' ),
				'img2' => $make_image( $a, 'a2' ),
			),
			'b' => array(
				'post' => $make_post( $b ),
				'img1' => $make_image( $b, 'b1' ),
				'img2' => $make_image( $b, 'b2' ),
			),
		);
	};

	echo "-- replace media\n";
	{
		// B's post uses B's image; the caller wants to swap it for one of A's.
		$victim = function () use ( $set, $make_post, $b ) {
			$s       = $set();
			$old     = $s['b']['img1'];
			$old_url = wp_get_attachment_url( $old );
			$content = '<!-- wp:image {"id":' . $old . '} --><figure class="wp-block-image"><img src="' . $old_url . '" class="wp-image-' . $old . '"/></figure><!-- /wp:image -->';
			$post    = $make_post( $b, $content );
			UNMAM_Scanner::instance()->index_post( $post );
			return array(
				'post'   => $post,
				'old'    => $old,
				'new'    => $s['a']['img2'],
				'params' => function ( $dry ) use ( $old, $s ) {
					return array(
						'old_attachment_id' => $old,
						'new_attachment_id' => $s['a']['img2'],
						'dry_run'           => $dry ? 'true' : 'false',
					);
				},
			);
		};
		$still_old = function ( $v ) {
			clean_post_cache( $v['post'] );
			$c = get_post_field( 'post_content', $v['post'], 'raw' );
			return false !== strpos( $c, 'wp-image-' . $v['old'] . '"' );
		};

		$v    = $victim();
		$rows = count( UNMAM_Database::get_references_for_attachment( $v['old'], array( 'per_page' => 10 ) ) );
		$ok( $rows > 0, "precondition: victim post indexed ($rows rows)" );
		list( $s_ok, $data ) = $bulk_call( $a, 'ajax_replace_attachment', $v['params']( true ) );
		$ok( ! $s_ok && 'Unauthorized' === $data, 'replace: author dry run -> Unauthorized' );
		$ok( $still_old( $v ), 'replace: author dry run left the post alone' );
		list( $s_ok, $data ) = $bulk_call( $a, 'ajax_replace_attachment', $v['params']( false ) );
		$ok( ! $s_ok && 'Unauthorized' === $data, 'replace: author real run -> Unauthorized' );
		$ok( $still_old( $v ), "replace: author real run left another author's post alone" );

		$v = $victim();
		list( $s_ok, $data ) = $bulk_call( $editor, 'ajax_replace_attachment', $v['params']( true ) );
		$ok( ! $s_ok && 'Unauthorized' === $data, 'replace: editor dry run -> Unauthorized' );
		list( $s_ok, $data ) = $bulk_call( $editor, 'ajax_replace_attachment', $v['params']( false ) );
		$ok( ! $s_ok && 'Unauthorized' === $data, 'replace: editor real run -> Unauthorized' );
		$ok( $still_old( $v ), 'replace: editor left the post alone' );

		$v = $victim();
		list( $s_ok, $data ) = $bulk_call( $admin_id, 'ajax_replace_attachment', $v['params']( true ) );
		$ok( $s_ok && is_array( $data ) && ! empty( $data['dry_run'] ) && (int) $data['total_count'] > 0, 'replace: admin dry run -> success with matches' );
		$ok( $still_old( $v ), 'replace: admin dry run wrote nothing' );
		list( $s_ok, $data ) = $bulk_call( $admin_id, 'ajax_replace_attachment', $v['params']( false ) );
		$ok( $s_ok && is_array( $data ) && (int) $data['total_count'] > 0, 'replace: admin real run -> success' );
		$ok( ! $still_old( $v ), 'replace: admin real run rewrote the post' );
	}

	echo "-- bulk delete\n";
	{
		$s = $set();
		list( $s_ok, $data ) = $bulk_call( $a, 'ajax_bulk_delete_unused', array(
			'attachment_ids' => array( $s['a']['img1'], $s['b']['img1'] ),
			'force_delete'   => 'true',
		) );
		$ok( $s_ok && 1 === (int) $data['deleted'], 'delete as A [own, B]: deleted count 1' );
		$ok( isset( $data['errors'][ $s['b']['img1'] ] ) && $denied === $data['errors'][ $s['b']['img1'] ] && 1 === count( $data['errors'] ), 'delete as A: one "Permission denied" error, for B\'s image' );
		$ok( ! $exists( $s['a']['img1'] ), "delete as A: A's image is gone" );
		$ok( $exists( $s['b']['img1'] ), "delete as A: B's image still exists" );

		list( $s_ok, $data ) = $bulk_call( $editor, 'ajax_bulk_delete_unused', array(
			'attachment_ids' => array( $s['b']['img2'] ),
			'force_delete'   => 'true',
		) );
		$ok( $s_ok && 1 === (int) $data['deleted'] && empty( $data['errors'] ), "delete as editor: B's other image deleted" );
		$ok( ! $exists( $s['b']['img2'] ), "delete as editor: B's other image is gone" );

		list( $s_ok, $data ) = $bulk_call( $a, 'ajax_bulk_delete_unused', array(
			'attachment_ids' => array( $s['a']['post'] ),
			'force_delete'   => 'true',
		) );
		$ok( $s_ok && 0 === (int) $data['deleted'] && 1 === count( $data['errors'] ), 'delete as A on a non-attachment: error' );
		$ok( null !== get_post( $s['a']['post'] ), 'delete as A on a non-attachment: post still exists' );
	}

	echo "-- bulk attach\n";
	{
		$s = $set();
		list( $s_ok, $data ) = $bulk_call( $a, 'ajax_bulk_attach', array(
			'attachment_ids' => array( $s['a']['img1'] ),
			'post_id'        => $s['a']['post'],
		) );
		$ok( $s_ok && 1 === (int) $data['success_count'] && 0 === (int) $data['error_count'], 'attach as A: own image to own post -> success' );
		$ok( $s['a']['post'] === $parent_of( $s['a']['img1'] ), 'attach as A: own image is attached' );

		list( $s_ok, $data ) = $bulk_call( $a, 'ajax_bulk_attach', array(
			'attachment_ids' => array( $s['b']['img1'] ),
			'post_id'        => $s['a']['post'],
		) );
		$ok( $s_ok && 0 === (int) $data['success_count'] && 1 === (int) $data['error_count'] && $denied === ( $data['errors'][ $s['b']['img1'] ] ?? '' ), "attach as A: B's image to own post -> error" );
		$ok( 0 === $parent_of( $s['b']['img1'] ), "attach as A: B's image parent unchanged" );

		list( $s_ok, $data ) = $bulk_call( $a, 'ajax_bulk_attach', array(
			'attachment_ids' => array( $s['a']['img2'] ),
			'post_id'        => $s['b']['post'],
		) );
		$ok( $s_ok && 0 === (int) $data['success_count'] && 1 === (int) $data['error_count'] && $denied === ( $data['errors'][ $s['a']['img2'] ] ?? '' ), "attach as A: own image to B's post -> error" );
		$ok( 0 === $parent_of( $s['a']['img2'] ), 'attach as A: own image parent unchanged' );

		list( $s_ok, $data ) = $bulk_call( $a, 'ajax_bulk_attach', array(
			'attachment_ids' => array( $s['a']['img2'], $s['b']['img2'] ),
			'post_id'        => $s['a']['post'],
		) );
		$ok( $s_ok && 1 === (int) $data['success_count'] && 1 === (int) $data['error_count'], 'attach as A [own, B]: one done, one error' );
		$ok( $s['a']['post'] === $parent_of( $s['a']['img2'] ) && 0 === $parent_of( $s['b']['img2'] ), 'attach as A [own, B]: only own attached' );
	}

	echo "-- bulk detach\n";
	{
		$s = $set();
		$adopt( $s['a']['img1'], $s['a']['post'] );
		$adopt( $s['b']['img1'], $s['b']['post'] );
		list( $s_ok, $data ) = $bulk_call( $a, 'ajax_bulk_detach', array(
			'attachment_ids' => array( $s['a']['img1'], $s['b']['img1'] ),
		) );
		$ok( $s_ok && 1 === (int) $data['success_count'] && 1 === (int) $data['error_count'] && $denied === ( $data['errors'][ $s['b']['img1'] ] ?? '' ), 'detach as A [own, B]: one done, B reported' );
		$ok( 0 === $parent_of( $s['a']['img1'] ), 'detach as A: own image detached' );
		$ok( $s['b']['post'] === $parent_of( $s['b']['img1'] ), "detach as A: B's image still attached" );
	}

	echo "-- bulk mark safe / unused\n";
	{
		$s = $set();
		list( $s_ok, $data ) = $bulk_call( $a, 'ajax_bulk_mark_safe', array(
			'attachment_ids' => array( $s['a']['img1'], $s['b']['img1'] ),
		) );
		$ok( $s_ok && 1 === (int) $data['count'] && $denied === ( $data['errors'][ $s['b']['img1'] ] ?? '' ) && 1 === count( $data['errors'] ), 'mark safe as A [own, B]: count 1, B reported' );
		$ok( $safe_of( $s['a']['img1'] ) && ! $safe_of( $s['b']['img1'] ), 'mark safe as A: only own is marked' );

		list( $s_ok, $data ) = $bulk_call( $a, 'ajax_bulk_mark_unused', array(
			'attachment_ids' => array( $s['a']['img1'], $s['b']['img1'] ),
		) );
		$ok( $s_ok && 1 === (int) $data['count'] && $denied === ( $data['errors'][ $s['b']['img1'] ] ?? '' ) && 1 === count( $data['errors'] ), 'mark unused as A [own, B]: count 1, B reported' );
		$ok( $unused_of( $s['a']['img1'] ) && ! $unused_of( $s['b']['img1'] ), 'mark unused as A: only own is marked' );

		list( $s_ok, $data ) = $bulk_call( $a, 'ajax_bulk_mark_safe', array(
			'attachment_ids' => array( $s['a']['post'] ),
		) );
		$ok( $s_ok && 0 === (int) $data['count'] && 1 === count( $data['errors'] ), 'mark safe as A on a non-attachment (own draft): error' );
		$ok( ! $safe_of( $s['a']['post'] ), 'mark safe as A on a non-attachment: nothing written' );

		list( $s_ok, $data ) = $bulk_call( $a, 'ajax_bulk_mark_unused', array(
			'attachment_ids' => array( $s['a']['post'] ),
		) );
		$ok( $s_ok && 0 === (int) $data['count'] && 1 === count( $data['errors'] ) && ! $unused_of( $s['a']['post'] ), 'mark unused as A on a non-attachment: error, nothing written' );

		list( $s_ok, $data ) = $bulk_call( $editor, 'ajax_bulk_mark_safe', array(
			'attachment_ids' => array( $s['b']['img2'] ),
		) );
		$ok( $s_ok && 1 === (int) $data['count'] && empty( $data['errors'] ) && $safe_of( $s['b']['img2'] ), "mark safe as editor on B's image: done" );
	}

	echo "-- media library bulk menu (upload.php)\n";
	{
		$run = function ( $user_id, $action, array $ids ) use ( $bulk ) {
			wp_set_current_user( $user_id );
			$url = $bulk->handle_bulk_actions( 'upload.php', $action, $ids );
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			return $q;
		};
		$s = $set();
		$q = $run( $a, 'unmam_mark_safe', array( $s['a']['img1'], $s['b']['img1'] ) );
		$ok( 1 === (int) ( $q['unmam_marked_safe'] ?? 0 ) && $safe_of( $s['a']['img1'] ) && ! $safe_of( $s['b']['img1'] ), 'menu mark safe as A [own, B]: only own marked' );

		update_post_meta( $s['a']['img2'], '_mui_marked_safe', true );
		update_post_meta( $s['b']['img2'], '_mui_marked_safe', true );
		$q = $run( $a, 'unmam_unmark_safe', array( $s['a']['img2'], $s['b']['img2'] ) );
		$ok( 1 === (int) ( $q['unmam_unmarked_safe'] ?? 0 ) && ! $safe_of( $s['a']['img2'] ) && $safe_of( $s['b']['img2'] ), 'menu unmark safe as A [own, B]: only own unmarked' );

		$q = $run( $a, 'unmam_mark_unused', array( $s['a']['img1'], $s['b']['img1'] ) );
		$ok( 1 === (int) ( $q['unmam_marked_unused'] ?? 0 ) && $unused_of( $s['a']['img1'] ) && ! $unused_of( $s['b']['img1'] ), 'menu mark unused as A [own, B]: only own marked' );

		$adopt( $s['a']['img1'], $s['a']['post'] );
		$adopt( $s['b']['img1'], $s['b']['post'] );
		$q = $run( $a, 'unmam_detach', array( $s['a']['img1'], $s['b']['img1'] ) );
		$ok( 1 === (int) ( $q['unmam_detached'] ?? 0 ) && 0 === $parent_of( $s['a']['img1'] ) && $s['b']['post'] === $parent_of( $s['b']['img1'] ), 'menu detach as A [own, B]: only own detached' );

		$q = $run( $a, 'unmam_mark_safe', array( $s['a']['post'] ) );
		$ok( empty( $q['unmam_marked_safe'] ) && ! $safe_of( $s['a']['post'] ), 'menu mark safe on a non-attachment: nothing written' );
	}

	echo "-- modal attach\n";
	{
		$s = $set();
		list( $s_ok, $data ) = $modal_call( $a, 'ajax_attach_to_post', array( 'attachment_id' => $s['a']['img1'], 'post_id' => $s['a']['post'] ) );
		$ok( $s_ok, 'modal attach as A: own image to own post -> success' );
		$ok( $s['a']['post'] === $parent_of( $s['a']['img1'] ), 'modal attach as A: own image is attached' );

		list( $s_ok, $data ) = $modal_call( $a, 'ajax_attach_to_post', array( 'attachment_id' => $s['b']['img1'], 'post_id' => $s['a']['post'] ) );
		$ok( ! $s_ok && $denied === $data, "modal attach as A: B's image -> Permission denied" );
		$ok( 0 === $parent_of( $s['b']['img1'] ), "modal attach as A: B's image parent unchanged" );

		list( $s_ok, $data ) = $modal_call( $a, 'ajax_attach_to_post', array( 'attachment_id' => $s['a']['img2'], 'post_id' => $s['b']['post'] ) );
		$ok( ! $s_ok && $denied === $data, "modal attach as A: own image to B's post -> Permission denied" );
		$ok( 0 === $parent_of( $s['a']['img2'] ), 'modal attach as A: own image parent unchanged' );

		list( $s_ok, $data ) = $modal_call( $editor, 'ajax_attach_to_post', array( 'attachment_id' => $s['b']['img1'], 'post_id' => $s['a']['post'] ) );
		$ok( $s_ok && $s['a']['post'] === $parent_of( $s['b']['img1'] ), "modal attach as editor: B's image to A's post -> success" );
	}

	echo "-- modal detach\n";
	{
		$s = $set();
		$adopt( $s['a']['img1'], $s['a']['post'] );
		$adopt( $s['b']['img1'], $s['b']['post'] );
		list( $s_ok, $data ) = $modal_call( $a, 'ajax_detach_from_post', array( 'attachment_id' => $s['a']['img1'] ) );
		$ok( $s_ok && 0 === $parent_of( $s['a']['img1'] ), 'modal detach as A: own image -> success, detached' );
		list( $s_ok, $data ) = $modal_call( $a, 'ajax_detach_from_post', array( 'attachment_id' => $s['b']['img1'] ) );
		$ok( ! $s_ok && $denied === $data, "modal detach as A: B's image -> Permission denied" );
		$ok( $s['b']['post'] === $parent_of( $s['b']['img1'] ), "modal detach as A: B's image still attached" );
	}

	echo "-- modal mark safe / unmark safe\n";
	{
		$s = $set();
		list( $s_ok, $data ) = $modal_call( $a, 'ajax_mark_safe', array( 'attachment_id' => $s['a']['img1'] ) );
		$ok( $s_ok && $safe_of( $s['a']['img1'] ), 'modal mark safe as A: own image -> success, marked' );
		list( $s_ok, $data ) = $modal_call( $a, 'ajax_mark_safe', array( 'attachment_id' => $s['b']['img1'] ) );
		$ok( ! $s_ok && $denied === $data && ! $safe_of( $s['b']['img1'] ), "modal mark safe as A: B's image -> Permission denied, not marked" );
		list( $s_ok, $data ) = $modal_call( $a, 'ajax_mark_safe', array( 'attachment_id' => $s['a']['post'] ) );
		$ok( ! $s_ok && ! $safe_of( $s['a']['post'] ), 'modal mark safe as A: a non-attachment -> error, nothing written' );

		update_post_meta( $s['b']['img2'], '_mui_marked_safe', true );
		list( $s_ok, $data ) = $modal_call( $a, 'ajax_unmark_safe', array( 'attachment_id' => $s['a']['img1'] ) );
		$ok( $s_ok && ! $safe_of( $s['a']['img1'] ), 'modal unmark safe as A: own image -> success, cleared' );
		list( $s_ok, $data ) = $modal_call( $a, 'ajax_unmark_safe', array( 'attachment_id' => $s['b']['img2'] ) );
		$ok( ! $s_ok && $denied === $data && $safe_of( $s['b']['img2'] ), "modal unmark safe as A: B's image -> Permission denied, still marked" );

		list( $s_ok, $data ) = $modal_call( $editor, 'ajax_mark_safe', array( 'attachment_id' => $s['b']['img1'] ) );
		$ok( $s_ok && $safe_of( $s['b']['img1'] ), "modal mark safe as editor: B's image -> success" );
		list( $s_ok, $data ) = $modal_call( $editor, 'ajax_unmark_safe', array( 'attachment_id' => $s['b']['img2'] ) );
		$ok( $s_ok && ! $safe_of( $s['b']['img2'] ), "modal unmark safe as editor: B's image -> success" );
	}
	echo "-- bulk attach / modal attach, target post that does not exist\n";
	{
		$s       = $set();
		$missing = 2147480000;
		list( $s_ok, $data ) = $bulk_call( $a, 'ajax_bulk_attach', array(
			'attachment_ids' => array( $s['a']['img1'] ),
			'post_id'        => $missing,
		) );
		$ok( $s_ok && 1 === (int) $data['error_count'] && 'Invalid post ID.' === ( $data['errors'][ $s['a']['img1'] ] ?? '' ), 'bulk attach to a missing post: "Invalid post ID."' );
		$ok( 0 === $parent_of( $s['a']['img1'] ), 'bulk attach to a missing post: image unchanged' );
		list( $s_ok, $data ) = $modal_call( $a, 'ajax_attach_to_post', array( 'attachment_id' => $s['a']['img1'], 'post_id' => $missing ) );
		$ok( ! $s_ok && 'Invalid post ID.' === $data, 'modal attach to a missing post: "Invalid post ID."' );
		$ok( 0 === $parent_of( $s['a']['img1'] ), 'modal attach to a missing post: image unchanged' );
	}

	echo "-- trash, restore and delete (plugin page actions)\n";
	{
		$ok( UNMAM_Database::is_trash_available(), 'precondition: trash is available on this site' );

		// Trash, single.
		$s = $set();
		list( $s_ok, $data ) = $admin_call( $a, 'ajax_trash_media', array( 'attachment_id' => $s['b']['img1'] ) );
		$ok( ! $s_ok && $denied === $data, "trash as A: B's image -> Permission denied" );
		$ok( $exists( $s['b']['img1'] ) && 'trash' !== $status_of( $s['b']['img1'] ), "trash as A: B's image unchanged" );
		list( $s_ok, $data ) = $admin_call( $a, 'ajax_trash_media', array( 'attachment_id' => $s['a']['img1'] ) );
		$ok( $s_ok && 'trash' === $status_of( $s['a']['img1'] ), 'trash as A: own image -> success, in the trash' );
		list( $s_ok, $data ) = $admin_call( $a, 'ajax_trash_media', array( 'attachment_id' => $s['a']['post'] ) );
		$ok( ! $s_ok && 'draft' === $status_of( $s['a']['post'] ), 'trash as A: a non-attachment -> error, post unchanged' );

		$s = $set();
		list( $s_ok, $data ) = $admin_call( $editor, 'ajax_trash_media', array( 'attachment_id' => $s['b']['img1'] ) );
		$ok( $s_ok && 'trash' === $status_of( $s['b']['img1'] ), "trash as editor: B's image -> success" );

		// Trash, bulk.
		$s = $set();
		list( $s_ok, $data ) = $admin_call( $a, 'ajax_trash_media_bulk', array( 'attachment_ids' => array( $s['a']['img1'], $s['b']['img1'] ) ) );
		$ok( $s_ok && 1 === (int) $data['success'] && 1 === (int) $data['errors'] && $denied === ( $data['failed'][ $s['b']['img1'] ] ?? '' ) && 1 === count( $data['failed'] ), 'trash bulk as A [own, B]: one done, B reported' );
		$ok( 'trash' === $status_of( $s['a']['img1'] ) && 'trash' !== $status_of( $s['b']['img1'] ), 'trash bulk as A: only own is in the trash' );
		$s = $set();
		list( $s_ok, $data ) = $admin_call( $editor, 'ajax_trash_media_bulk', array( 'attachment_ids' => array( $s['b']['img2'] ) ) );
		$ok( $s_ok && 1 === (int) $data['success'] && 0 === (int) $data['errors'] && 'trash' === $status_of( $s['b']['img2'] ), "trash bulk as editor: B's image -> done" );

		// Restore, single. Everything is trashed first, by the admin.
		$s = $set();
		foreach ( array( $s['a']['img1'], $s['b']['img1'], $s['b']['img2'] ) as $img ) {
			$trash( $img );
		}
		$ok( 'trash' === $status_of( $s['a']['img1'] ) && 'trash' === $status_of( $s['b']['img1'] ), 'precondition: images are in the trash' );
		list( $s_ok, $data ) = $admin_call( $a, 'ajax_restore_media', array( 'attachment_id' => $s['b']['img1'] ) );
		$ok( ! $s_ok && $denied === $data && 'trash' === $status_of( $s['b']['img1'] ), "restore as A: B's image -> Permission denied, still in the trash" );
		list( $s_ok, $data ) = $admin_call( $a, 'ajax_restore_media', array( 'attachment_id' => $s['a']['img1'] ) );
		$ok( $s_ok && 'trash' !== $status_of( $s['a']['img1'] ), 'restore as A: own trashed image -> success, restored' );
		$s = $set();
		$trash( $s['b']['img1'] );
		list( $s_ok, $data ) = $admin_call( $editor, 'ajax_restore_media', array( 'attachment_id' => $s['b']['img1'] ) );
		$ok( $s_ok && 'trash' !== $status_of( $s['b']['img1'] ), "restore as editor: B's trashed image -> success" );

		// Restore, bulk.
		$s = $set();
		foreach ( array( $s['a']['img1'], $s['b']['img1'], $s['b']['img2'] ) as $img ) {
			$trash( $img );
		}
		list( $s_ok, $data ) = $admin_call( $a, 'ajax_restore_media_bulk', array( 'attachment_ids' => array( $s['a']['img1'], $s['b']['img1'] ) ) );
		$ok( $s_ok && 1 === (int) $data['success'] && 1 === (int) $data['errors'] && $denied === ( $data['failed'][ $s['b']['img1'] ] ?? '' ), 'restore bulk as A [own, B]: one done, B reported' );
		$ok( 'trash' !== $status_of( $s['a']['img1'] ) && 'trash' === $status_of( $s['b']['img1'] ), 'restore bulk as A: only own is restored' );
		$s = $set();
		$trash( $s['b']['img2'] );
		list( $s_ok, $data ) = $admin_call( $editor, 'ajax_restore_media_bulk', array( 'attachment_ids' => array( $s['b']['img2'] ) ) );
		$ok( $s_ok && 1 === (int) $data['success'] && 'trash' !== $status_of( $s['b']['img2'] ), "restore bulk as editor: B's image -> done" );

		// Delete permanently, bulk. A trashed own image is deletable by its owner too.
		$s = $set();
		$trash( $s['a']['img2'] );
		list( $s_ok, $data ) = $admin_call( $a, 'ajax_delete_media_permanently', array( 'attachment_ids' => array( $s['a']['img1'], $s['a']['img2'], $s['b']['img1'] ) ) );
		$ok( $s_ok && 2 === (int) $data['success'] && 1 === (int) $data['errors'] && $denied === ( $data['failed'][ $s['b']['img1'] ] ?? '' ), 'delete bulk as A [own, own trashed, B]: two done, B reported' );
		$ok( ! $exists( $s['a']['img1'] ) && ! $exists( $s['a']['img2'] ) && $exists( $s['b']['img1'] ), 'delete bulk as A: own images gone, B\'s still there' );
		list( $s_ok, $data ) = $admin_call( $a, 'ajax_delete_media_permanently', array( 'attachment_ids' => array( $s['a']['post'] ) ) );
		$ok( $s_ok && 0 === (int) $data['success'] && 1 === (int) $data['errors'] && null !== get_post( $s['a']['post'] ), 'delete bulk as A on a non-attachment: reported, post still there' );

		$s = $set();
		list( $s_ok, $data ) = $admin_call( $editor, 'ajax_delete_media_permanently', array( 'attachment_ids' => array( $s['b']['img1'] ) ) );
		$ok( $s_ok && 1 === (int) $data['success'] && ! $exists( $s['b']['img1'] ), "delete bulk as editor: B's image -> deleted" );

		// Delete permanently, single.
		$s = $set();
		list( $s_ok, $data ) = $admin_call( $a, 'ajax_delete_single', array( 'attachment_id' => $s['b']['img1'] ) );
		$ok( ! $s_ok && $denied === $data && $exists( $s['b']['img1'] ), "delete single as A: B's image -> Permission denied, still there" );
		list( $s_ok, $data ) = $admin_call( $a, 'ajax_delete_single', array( 'attachment_id' => $s['a']['img1'] ) );
		$ok( $s_ok && ! $exists( $s['a']['img1'] ), 'delete single as A: own image -> success, gone' );
		list( $s_ok, $data ) = $admin_call( $a, 'ajax_delete_single', array( 'attachment_id' => $s['a']['post'] ) );
		$ok( ! $s_ok && null !== get_post( $s['a']['post'] ), 'delete single as A on a non-attachment: error, post still there' );
		$s = $set();
		list( $s_ok, $data ) = $admin_call( $editor, 'ajax_delete_single', array( 'attachment_id' => $s['b']['img1'] ) );
		$ok( $s_ok && ! $exists( $s['b']['img1'] ), "delete single as editor: B's image -> success, gone" );
	}
} catch ( Throwable $e ) {
	$fail++;
	printf( "FAIL  stopped by %s: %s (%s:%d)\n", get_class( $e ), $e->getMessage(), basename( $e->getFile() ), $e->getLine() );
} finally {
	wp_set_current_user( $admin_id );
	foreach ( $made['posts'] as $pid ) {
		wp_clear_scheduled_hook( 'unmam_index_single_post', array( $pid ) );
		wp_delete_post( $pid, true );
	}
	foreach ( $made['attachments'] as $att ) {
		UNMAM_Database::delete_references_by_attachment( $att );
		wp_delete_attachment( $att, true );
	}
	foreach ( $made['users'] as $uid ) {
		wp_delete_user( $uid );
	}
	wp_set_current_user( 0 );

	$dir = wp_upload_dir();
	foreach ( (array) glob( $dir['path'] . '/unmamfix-perms-*' ) as $left ) {
		unlink( $left );
	}
	global $wpdb;
	$left_users = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->users WHERE user_login LIKE 'unmamfix%'" );
	$left_posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_title LIKE '%UNMAMFIX perms%'" );
	$left_files = count( (array) glob( $dir['path'] . '/unmamfix-perms-*' ) );
	echo "-- cleanup\n";
	$ok( 0 === $left_users && 0 === $left_posts && 0 === $left_files, "nothing left over (users $left_users, posts $left_posts, files $left_files)" );
}

echo "\nfailures: $fail\n";
