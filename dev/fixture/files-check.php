<?php
/**
 * Checks for the 1.4.0 file scan step, against what files-seed.php made.
 *
 * Without an argument: one full pass through full_scan() and a run_batch() loop over the
 * active scan types at the normal limits, then the cursor cases.
 *   tiny      same pass, files step limited to 3 files, 50 entries and 1 second a batch
 *   pause     same pass, with the files step told to stop after one unit every batch
 *   settings  the scan_files setting: untick, untick mid-pass, retick, upgrade shape
 *   display   Where Used for file rows: titles, who sees what, Replace Media, with a temporary
 *             author and contributor and the fixture plugin folder removed for a moment
 *   notices   the not-scanned, changed and not-read notices, and the hooks that set "changed"
 *
 * Every mode puts settings and fixture files back before it exits.
 * Run: dev/flpwp.sh eval-file dev/fixture/files-check.php [tiny|pause|settings|display|notices]
 */

$mode = empty( $args ) ? '' : $args[0];
$fail = 0;
$ok   = function ( $cond, $label ) use ( &$fail ) {
	if ( ! $cond ) {
		$fail++;
	}
	printf( "%-5s %s\n", $cond ? 'ok' : 'FAIL', $label );
};

$fx = get_option( 'unmamfix_files' );
if ( ! is_array( $fx ) ) {
	echo "not seeded - run files-seed.php first\n";
	return;
}

// Notices and warnings from the plugin count as failures. Core's own deprecations do not.
$notices = array();
set_error_handler( function ( $no, $str, $file, $line ) use ( &$notices ) {
	if ( ( error_reporting() & $no ) && false !== strpos( $file, 'unattached-media-manager' ) ) {
		$notices[] = "$str ($file:$line)";
	}
	return true;
} );

global $wpdb;
$table   = UNMAM_Database::get_table_name( 'references' );
$scanner = UNMAM_Scanner::instance();
$theme   = 'theme:unmam-fixture-theme';

$saved_settings = get_option( 'unmam_settings' );
$set_settings   = function ( array $over ) use ( $saved_settings ) {
	update_option( 'unmam_settings', array_merge( $saved_settings, $over ) );
};

// Limits for the files step, changed from the cases below.
$override = array();
add_filter( 'unmam_file_scan_batch_limits', function ( $limits ) use ( &$override ) {
	return array_merge( $limits, $override );
} );

// Runs $fn with every INSERT into the references table broken, so insert_reference() returns false.
$with_inserts_refused = function ( $fn ) use ( $wpdb, $table ) {
	$refuse = function ( $query ) use ( $table ) {
		if ( 0 === strpos( ltrim( $query ), 'INSERT INTO `' . $table . '`' ) ) {
			return str_replace( 'INSERT INTO', 'INSERT INTX', $query );
		}
		return $query;
	};
	$wpdb->suppress_errors( true );
	add_filter( 'query', $refuse );
	try {
		$fn();
	} finally {
		remove_filter( 'query', $refuse );
		$wpdb->suppress_errors( false );
	}
};

$count_file_rows = function () use ( $wpdb, $table ) {
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE source_type = 'file'" );
};
$file_rows_for = function ( $img ) use ( $wpdb, $table ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE source_type = 'file' AND attachment_id = %d", $img ) );
};
$key_for = function ( $img ) use ( $wpdb, $table ) {
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT context_key FROM $table WHERE attachment_id = %d AND source_type = 'file' ORDER BY id LIMIT 1", $img ) );
};
$ctx_for = function ( $img ) use ( $wpdb, $table ) {
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT context_type FROM $table WHERE attachment_id = %d AND source_type = 'file' ORDER BY id LIMIT 1", $img ) );
};
$tail_of = function ( $img ) {
	return substr( wp_get_attachment_url( $img ), strlen( untrailingslashit( wp_get_upload_dir()['baseurl'] ) ) + 1 );
};
$state_of = function () {
	$state = get_option( UNMAM_File_Scanner::STATE_OPTION );
	return is_array( $state ) ? $state : array();
};
$position = function () use ( $state_of ) {
	$state = $state_of();
	return $state ? $state['index'] . '|' . $state['path'] . '|' . $state['candidate'] : '';
};
$many_ids = array();
for ( $i = 1; $i <= 20; $i++ ) {
	$many_ids[] = $fx['found'][ sprintf( 'many%02d', $i ) ];
}

// Runs the first batch of a files pass (which builds the state), then moves the cursor to the
// fixture theme so a case does not walk the whole site. With $reset it clears the progress row
// and the state first, as full_scan() does; without it, the caller has just run full_scan().
$start_at_fixture = function ( array $limits, $reset = true ) use ( &$override, $scanner, $state_of, $theme ) {
	if ( $reset ) {
		UNMAM_Database::reset_scan_progress( 'files' );
		delete_option( UNMAM_File_Scanner::STATE_OPTION );
	}

	$override = array( 'files' => 1, 'seconds' => 0 );
	$scanner->run_batch( 'files' );

	$state = $state_of();
	foreach ( $state['components'] as $i => $component ) {
		if ( $theme === $component['key'] ) {
			$state['index'] = $i;
		}
	}
	$state['path']       = '';
	$state['candidate']  = 0;
	$state['size']       = 0;
	$state['mtime']      = 0;
	$state['attempt_at'] = '';
	$state['attempts']   = 0;
	update_option( UNMAM_File_Scanner::STATE_OPTION, $state, false );

	$override = $limits;
};
$one_batch = function () use ( $scanner, $state_of ) {
	$scanner->run_batch( 'files' );
	return $state_of();
};
$finish = function ( $ceiling = 4000 ) use ( $scanner ) {
	for ( $n = 0; $n < $ceiling; $n++ ) {
		if ( 'completed' === $scanner->run_batch( 'files' )['status'] ) {
			return true;
		}
	}
	return false;
};
$stop_in_many = function ( $ceiling = 400 ) use ( $one_batch ) {
	for ( $n = 0; $n < $ceiling; $n++ ) {
		$state = $one_batch();
		if ( 'data/many.json' === $state['path'] && $state['candidate'] > 0 ) {
			return true;
		}
	}
	return false;
};
// A full scan resets the posts row to running; put it back so a case leaves the table as it found it.
$keep_posts_row = function ( $fn ) {
	$before = UNMAM_Database::get_scan_progress( 'posts' );
	try {
		$fn();
	} finally {
		if ( $before ) {
			UNMAM_Database::update_scan_progress( 'posts', array_intersect_key( $before, array_flip( array( 'last_processed_id', 'total_items', 'processed_items', 'status', 'started_at', 'completed_at' ) ) ) );
		}
	}
};

// A whole-site pass of the files step on its own: the posts and the other steps are left alone.
$run_full_pass = function () use ( $scanner, &$override ) {
	$override = array();
	UNMAM_Database::reset_scan_progress( 'files' );
	delete_option( UNMAM_File_Scanner::STATE_OPTION );
	for ( $n = 0; $n < 5000; $n++ ) {
		if ( 'completed' === $scanner->run_batch( 'files' )['status'] ) {
			return true;
		}
	}
	return false;
};

if ( 'display' === $mode ) {
	echo "-- display\n";
	if ( ! class_exists( 'UNMAM_Media_Modal' ) ) {
		require_once UNMAM_PLUGIN_DIR . 'includes/admin/class-unmam-media-modal.php';
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';

	$admin_id = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ) )[0];
	$sidebar  = $fx['found']['sidebar'];
	$own      = $fx['found']['phpimg']; // Only functions.php mentions it.
	$spare    = $fx['spare']['rel2'];

	$source_for = function ( $img ) {
		foreach ( UNMAM_Database::get_references_for_attachment( $img, array( 'per_page' => 50 ) ) as $ref ) {
			if ( 'file' === $ref['source_type'] ) {
				return UNMAM_Database::describe_source( $ref );
			}
		}
		return array( 'title' => '', 'edit_link' => false, 'view_link' => false );
	};
	$title_for = function ( $img ) use ( $source_for ) {
		return (string) $source_for( $img )['title'];
	};

	// ajax_get_usage() as a given user. wp_send_json_*() ends in wp_die(), so that is swapped
	// for one that throws. Returns the response data, or false for an error response.
	$ajax_error    = null;
	$ajax_usage_as = function ( $user_id, $attachment_id ) use ( &$ajax_error ) {
		wp_set_current_user( $user_id );
		$_POST = $_REQUEST = array(
			'nonce'         => wp_create_nonce( 'unmam_media_modal_nonce' ),
			'attachment_id' => $attachment_id,
		);
		$stop = function () {
			return function () {
				throw new RuntimeException( 'wp_die' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $stop );
		ob_start();
		try {
			UNMAM_Media_Modal::instance()->ajax_get_usage();
		} catch ( RuntimeException $e ) {
			// Expected: the response has been printed.
		} finally {
			$out = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $stop );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			$_POST = $_REQUEST = array();
		}

		$json       = json_decode( (string) $out, true );
		$ajax_error = null;
		if ( ! is_array( $json ) || empty( $json['success'] ) ) {
			$ajax_error = is_array( $json ) && isset( $json['data'] ) ? $json['data'] : 'no response: ' . $out;
			return false;
		}
		return $json['data'];
	};

	$rest_usage = function ( $user_id, $attachment_id ) {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'GET', '/unmam/v1/attachments/' . $attachment_id . '/usage' );
		$request->set_param( 'id', $attachment_id );
		$request->set_param( 'page', 1 );
		$request->set_param( 'per_page', 20 );
		return ( new UNMAM_REST_Controller() )->get_attachment_usage( $request )->get_data();
	};

	$plugin_dir  = $fx['plugin'];
	$plugin_file = $plugin_dir . '/unmam-fixture-plugin.php';
	$plugin_body = is_readable( $plugin_file ) ? (string) file_get_contents( $plugin_file ) : '';
	$sidebar_md5 = md5_file( $fx['theme'] . '/parts/sidebar.html' );
	$orig_author = (int) get_post_field( 'post_author', $own );
	$author_id   = 0;
	$contrib_id  = 0;

	try {
		$ok( '' !== $plugin_body, 'precondition: the fixture plugin file can be read' );
		$set_settings( array( 'scan_files' => true ) );
		$ok( $run_full_pass(), 'precondition: a files pass finished' );
		$ok( $file_rows_for( $sidebar ) > 0 && $file_rows_for( $own ) > 0 && $file_rows_for( $fx['found']['plugin'] ) > 0 && $file_rows_for( $fx['found']['mu'] ) > 0, 'precondition: the sidebar, functions.php, plugin and mu-plugin images have file rows' );

		$author_id  = wp_insert_user( array(
			'user_login' => 'unmamfix-author',
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'unmamfix-author@example.invalid',
			'role'       => 'author',
		) );
		$contrib_id = wp_insert_user( array(
			'user_login' => 'unmamfix-contributor',
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'unmamfix-contributor@example.invalid',
			'role'       => 'contributor',
		) );
		$ok( is_int( $author_id ) && is_int( $contrib_id ), 'temporary author and contributor created' );
		$wpdb->update( $wpdb->posts, array( 'post_author' => $author_id ), array( 'ID' => $own ) );
		clean_post_cache( $own );

		// An administrator sees the whole title and gets no links.
		wp_set_current_user( $admin_id );
		$ok( 'Unmam Fixture Theme (inactive): parts/sidebar.html' === $title_for( $sidebar ), 'admin title: ' . $title_for( $sidebar ) );
		$ok( 'Unmam Fixture Plugin (inactive): unmam-fixture-plugin.php' === $title_for( $fx['found']['plugin'] ), 'admin title, plugin: ' . $title_for( $fx['found']['plugin'] ) );
		$ok( 0 === strpos( $title_for( $fx['found']['mu'] ), 'Must-use plugins: ' ), 'mu title: ' . $title_for( $fx['found']['mu'] ) );
		$ok( null === $source_for( $sidebar )['edit_link'] && null === $source_for( $sidebar )['view_link'], 'no edit or view link' );

		$usage = $ajax_usage_as( $admin_id, $own );
		$ok( is_array( $usage ) && 1 === count( $usage['references'] ), 'ajax as admin: one reference for the image in functions.php' );
		$row = is_array( $usage ) ? $usage['references'][0] : array();
		$ok( 'Unmam Fixture Theme (inactive): functions.php' === ( isset( $row['source_title'] ) ? $row['source_title'] : '' ), 'ajax as admin: full title' );
		$ok( 'theme:unmam-fixture-theme|functions.php' === ( isset( $row['context_key'] ) ? $row['context_key'] : '' ) && ! empty( $row['reference_value'] ), 'ajax as admin: context_key and reference_value kept' );
		$ok( empty( $row['edit_link'] ) && empty( $row['view_link'] ), 'ajax as admin: no links' );
		$rest = $rest_usage( $admin_id, $own );
		$ok( isset( $rest['references'][0]['context_key'] ) && 'Unmam Fixture Theme (inactive): functions.php' === $rest['references'][0]['source_title'], 'rest as admin: full title and context_key' );

		// The author who owns the image may ask, and gets nothing that names a file.
		wp_set_current_user( $author_id );
		$ok( 'Theme or plugin file' === $title_for( $own ), 'author sees the generic title' );
		$usage = $ajax_usage_as( $author_id, $own );
		$ok( is_array( $usage ) && 1 === count( $usage['references'] ), 'ajax as the author of the image: allowed' );
		$row = is_array( $usage ) ? $usage['references'][0] : array();
		$ok( ! isset( $row['context_key'] ) && ! isset( $row['reference_value'] ), 'author gets no context_key and no reference_value' );
		$ok( 'Theme or plugin file' === ( isset( $row['source_title'] ) ? $row['source_title'] : '' ), 'author: generic source_title' );
		$whole = (string) wp_json_encode( $usage );
		$ok( false === strpos( $whole, 'functions.php' ) && false === stripos( $whole, 'fixture' ) && false === strpos( $whole, 'unmam-fixture' ), 'author: nothing in the response names the file, theme or plugin' );
		$rest = $rest_usage( $author_id, $own );
		$whole = (string) wp_json_encode( $rest );
		$ok( ! isset( $rest['references'][0]['context_key'] ) && ! isset( $rest['references'][0]['reference_value'] ) && false === stripos( $whole, 'fixture' ), 'rest handler as author: no context_key, no reference_value, no names' );

		// Who may ask at all.
		$ok( false === $ajax_usage_as( $contrib_id, $own ) && 'Permission denied' === $ajax_error, 'contributor refused' );
		$ok( false === $ajax_usage_as( $author_id, $sidebar ) && 'Permission denied' === $ajax_error, 'author refused on an image they cannot edit' );
		$ok( false === $ajax_usage_as( 0, $own ) && 'Permission denied' === $ajax_error, 'logged out refused' );
		$ok( is_array( $ajax_usage_as( $admin_id, $sidebar ) ), 'admin allowed on any image' );

		// The plugin goes away without a rescan. The request cache still lists it.
		wp_set_current_user( $admin_id );
		$ok( isset( UNMAM_File_Scanner::installed_plugins()['unmam-fixture-plugin'] ), 'precondition: the plugin list was read while the plugin was there' );
		unlink( $plugin_file );
		rmdir( $plugin_dir );
		$ok( false !== strpos( $title_for( $fx['found']['plugin'] ), '(not installed)' ), 'removed plugin: ' . $title_for( $fx['found']['plugin'] ) );

		// Replace Media never writes a file; it says which one to edit.
		$dry = UNMAM_Attachment_Manager::instance()->replace_references( $sidebar, $spare, true );
		$ok( ! empty( $dry['errors'] ) && false !== strpos( $dry['errors'][0]['error'], 'edit this file by hand: Unmam Fixture Theme (inactive): parts/sidebar.html' ), 'replace lists the file' );
		$ok( ! $dry['replaced'] && 0 === $dry['total_count'], 'replace: a file row is not counted as replaced' );
		$real = UNMAM_Attachment_Manager::instance()->replace_references( $sidebar, $spare, false );
		$ok( 0 === $real['total_count'] && $sidebar_md5 === md5_file( $fx['theme'] . '/parts/sidebar.html' ), 'replace, for real: the theme file is untouched' );
		$ok( $file_rows_for( $sidebar ) > 0 && 0 === $file_rows_for( $spare ), 'replace, for real: the file row stays on the old image' );
		wp_set_current_user( $author_id );
		$dry = UNMAM_Attachment_Manager::instance()->replace_references( $sidebar, $spare, true );
		$ok( ! empty( $dry['errors'] ) && 'Not changed, edit this file by hand: Theme or plugin file' === $dry['errors'][0]['error'], 'replace as author: generic title in the message' );
	} finally {
		wp_set_current_user( 0 );

		// The plugin folder as the seed made it.
		if ( ! is_dir( $plugin_dir ) && '' !== $plugin_body ) {
			mkdir( $plugin_dir, 0755 );
			file_put_contents( $plugin_file, $plugin_body );
		}

		// The image goes back to its author before the temporary one is deleted, or its
		// posts would go with it.
		$wpdb->update( $wpdb->posts, array( 'post_author' => $orig_author ), array( 'ID' => $own ) );
		clean_post_cache( $own );
		foreach ( array( $author_id, $contrib_id ) as $uid ) {
			if ( is_int( $uid ) && $uid > 0 ) {
				wp_delete_user( $uid, $admin_id );
			}
		}
		update_option( 'unmam_settings', $saved_settings );
		$_POST = $_REQUEST = array();
	}

	$ok( is_file( $plugin_file ) && $plugin_body === file_get_contents( $plugin_file ), 'cleanup: the fixture plugin is back' );
	$ok( $orig_author === (int) get_post_field( 'post_author', $own ), 'cleanup: the image has its first author' );
	$ok( ! get_user_by( 'login', 'unmamfix-author' ) && ! get_user_by( 'login', 'unmamfix-contributor' ), 'cleanup: both temporary users are gone' );
	$ok( ! $notices, 'no notices or warnings from the plugin' . ( $notices ? ': ' . $notices[0] : '' ) );
	echo "\nfailures: $fail\n";
	return;
}

if ( 'notices' === $mode ) {
	echo "-- notices\n";
	if ( ! class_exists( 'UNMAM_Admin' ) ) {
		require_once UNMAM_PLUGIN_DIR . 'includes/admin/class-unmam-admin.php';
	}

	$admin_id   = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ) )[0];
	$reader_id  = (int) get_users( array( 'role' => 'contributor', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ) )[0]; // Only ever read as.
	$was        = array(
		'scanned' => get_option( 'unmam_files_scanned_at', null ),
		'changed' => get_option( 'unmam_files_changed_at', null ),
		'state'   => get_option( UNMAM_File_Scanner::STATE_OPTION, null ),
	);
	$notice_state = function () {
		return UNMAM_File_Scanner::notice_state();
	};
	$html_for = function ( $tab = 'unused' ) {
		ob_start();
		UNMAM_Admin::instance()->render_file_scan_notices( $tab );
		return ob_get_clean();
	};

	// Fires a hook with only this plugin's own callbacks on it. Core hangs update checks that
	// call api.wordpress.org on upgrader_process_complete, and other plugins hang their own.
	$fire = function ( $hook, array $args ) {
		global $wp_filter;
		$saved = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ] : null;
		if ( $saved ) {
			$ours = new WP_Hook();
			foreach ( $saved->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					$fn    = $cb['function'];
					$owner = is_array( $fn ) ? ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) : '';
					if ( 0 === strpos( $owner, 'UNMAM_' ) || 'Unattached_Media_Manager' === $owner ) {
						$ours->add_filter( $hook, $fn, $priority, $cb['accepted_args'] );
					}
				}
			}
			$wp_filter[ $hook ] = $ours;
		}
		try {
			do_action_ref_array( $hook, $args );
		} finally {
			if ( $saved ) {
				$wp_filter[ $hook ] = $saved;
			}
		}
	};
	$upgraded = function ( $extra ) use ( $fire ) {
		$fire( 'upgrader_process_complete', array( null, $extra ) );
	};

	try {
		$set_settings( array( 'scan_files' => true ) );

		delete_option( 'unmam_files_scanned_at' );
		delete_option( 'unmam_files_changed_at' );
		$ok( $notice_state()['never_scanned'], 'never scanned' );
		wp_set_current_user( $admin_id );
		$html = $html_for();
		$ok( false !== strpos( $html, 'Theme and plugin files have not been scanned yet. Run a full scan before deleting anything.' ) && false !== strpos( $html, 'notice notice-warning' ), 'never scanned: admin notice, exact copy' );
		$ok( '' === $html_for( 'settings' ), 'never scanned: nothing on the settings tab' );

		$ok( $run_full_pass(), 'a files pass finished' );
		$ok( ! $notice_state()['never_scanned'] && ! $notice_state()['changed'], 'clean after a pass' );
		$ok( false === strpos( $html_for(), 'have not been scanned' ) && false === strpos( $html_for(), 'changed since the last scan' ), 'clean after a pass: neither notice shows' );

		sleep( 1 );
		$upgraded( array( 'type' => 'translation' ) );
		$ok( ! $notice_state()['changed'], 'translation update ignored' );
		$upgraded( array() );
		$upgraded( array( 'type' => 'core' ) );
		$ok( ! $notice_state()['changed'] && false === get_option( 'unmam_files_changed_at' ), 'an update without a type, and a core update, ignored' );

		$upgraded( array( 'type' => 'plugin', 'action' => 'update' ) );
		$ok( $notice_state()['changed'], 'changed after a plugin update' );
		$html = $html_for();
		$ok( false !== strpos( $html, 'Themes or plugins changed since the last scan. Run a scan before deleting anything.' ), 'changed: admin notice, exact copy' );
		$ok( '' === $html_for( 'settings' ), 'changed: nothing on the settings tab' );
		$autoload = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'unmam_files_changed_at' ) );
		$ok( in_array( $autoload, array( 'no', 'off' ), true ), 'unmam_files_changed_at is not autoloaded (' . $autoload . ')' );

		// The other ways a theme or plugin changes. Each is cleared by hand, not by a pass.
		foreach ( array(
			'theme update'  => function () use ( $upgraded ) {
				$upgraded( array( 'type' => 'theme', 'action' => 'update' ) );
			},
			'plugin delete' => function () use ( $fire ) {
				$fire( 'deleted_plugin', array( 'unmamfix/unmamfix.php', true ) );
			},
			'theme delete'  => function () use ( $fire ) {
				$fire( 'deleted_theme', array( 'unmamfix-theme', true ) );
			},
		) as $what => $do ) {
			delete_option( 'unmam_files_changed_at' );
			$ok( ! $notice_state()['changed'], "$what: precondition, nothing changed" );
			sleep( 1 );
			$do();
			$ok( $notice_state()['changed'], "changed after a $what" );
		}

		$ok( $run_full_pass(), 'a second files pass finished' );
		$ok( ! $notice_state()['changed'], 'cleared by the next pass' );
		$ok( ! empty( $notice_state()['skipped']['items'] ), 'skipped list exposed' );

		// The not-read notice, as an administrator.
		wp_set_current_user( $admin_id );
		$html = $html_for();
		$ok( false !== strpos( $html, 'could not be read' ) && false !== strpos( $html, 'huge.css' ), 'admin notice lists skipped file' );
		$ok( false !== strpos( $html, 'Unmam Fixture Theme (inactive): huge.css: over 10 MB' ), 'admin notice: title, path and reason' );
		$ok( '' === $html_for( 'settings' ), 'not read: nothing on the settings tab' );

		// Someone who cannot activate plugins gets the generic title and no path.
		wp_set_current_user( $reader_id );
		$html = $html_for();
		$ok( false !== strpos( $html, 'could not be read' ) && false === strpos( $html, 'huge.css' ) && false === strpos( $html, 'Unmam Fixture' ) && false !== strpos( $html, 'Theme or plugin file: over 10 MB' ), 'not read, as a non-admin: generic title, no path' );
		wp_set_current_user( $admin_id );

		// A crafted state: every reason, a component skipped as a whole, more than ten, then one.
		$state                  = $state_of();
		$state['skipped']       = array();
		$state['skipped_total'] = 0;
		foreach ( array( 'too_big', 'unreadable', 'too_deep', 'stopped' ) as $i => $reason ) {
			$state['skipped'][] = array( 'key' => $theme, 'path' => "css/r$i.css", 'reason' => $reason );
		}
		$state['skipped'][] = array( 'key' => $theme, 'path' => '', 'reason' => 'stopped' );
		$state['skipped'][] = array( 'key' => 'mu', 'path' => '', 'reason' => 'unreadable' );
		for ( $i = 6; $i < 12; $i++ ) {
			$state['skipped'][] = array( 'key' => $theme, 'path' => "css/r$i.css", 'reason' => 'too_big' );
		}
		$state['skipped_total'] = 40;
		update_option( UNMAM_File_Scanner::STATE_OPTION, $state, false );
		$html = $html_for();
		$ok( false !== strpos( $html, '40 theme or plugin files could not be read, so images used only in them may be listed as unused.' ), 'crafted state: the total is in the sentence' );
		$ok( 10 === substr_count( $html, '<li>' ), 'crafted state: ten lines, not twelve (' . substr_count( $html, '<li>' ) . ')' );
		$ok( false !== strpos( $html, 'css/r0.css: over 10 MB' ) && false !== strpos( $html, 'css/r1.css: unreadable' ) && false !== strpos( $html, 'css/r2.css: folder too deep' ) && false !== strpos( $html, 'css/r3.css: stopped the scan' ), 'crafted state: the four reasons read right' );
		$ok( false !== strpos( $html, '<li>Unmam Fixture Theme (inactive): stopped the scan</li>' ) && false !== strpos( $html, '<li>Must-use plugins: unreadable</li>' ), 'crafted state: a component skipped as a whole shows its name alone' );
		$state['skipped']       = array( array( 'key' => $theme, 'path' => '<b>x</b>.css', 'reason' => 'unreadable' ) );
		$state['skipped_total'] = 1;
		update_option( UNMAM_File_Scanner::STATE_OPTION, $state, false );
		$html = $html_for();
		$ok( false !== strpos( $html, '1 theme or plugin file could not be read' ) && false === strpos( $html, '<b>' ) && false !== strpos( $html, '&lt;b&gt;x&lt;/b&gt;.css' ), 'crafted state: one file reads in the singular, and a path is escaped' );

		// Off means off: no notice and an empty state, whatever the options hold.
		delete_option( 'unmam_files_scanned_at' );
		UNMAM_File_Scanner::mark_changed();
		$set_settings( array( 'scan_files' => false ) );
		$off = $notice_state();
		$ok( ! $off['never_scanned'] && ! $off['changed'] && ! $off['skipped']['items'] && 0 === $off['skipped']['total'], 'scan_files off: notice_state is all false and empty' );
		$ok( '' === $html_for(), 'scan_files off: nothing is printed' );
	} finally {
		foreach ( array( 'scanned' => 'unmam_files_scanned_at', 'changed' => 'unmam_files_changed_at', 'state' => UNMAM_File_Scanner::STATE_OPTION ) as $k => $name ) {
			if ( null === $was[ $k ] ) {
				delete_option( $name );
			} else {
				update_option( $name, $was[ $k ], false );
			}
		}
		update_option( 'unmam_settings', $saved_settings );
		wp_set_current_user( 0 );
	}

	$ok( ! $notices, 'no notices or warnings from the plugin' . ( $notices ? ': ' . $notices[0] : '' ) );
	echo "\nfailures: $fail\n";
	return;
}

if ( 'settings' !== $mode ) {
	// Mode limits.
	if ( 'tiny' === $mode ) {
		$override = array( 'files' => 3, 'entries' => 50, 'seconds' => 1 );
	} elseif ( 'pause' === $mode ) {
		$override = array( 'seconds' => 0 );
	}
	$ceiling = '' === $mode ? 5000 : 50000;
	$label   = '' === $mode ? 'default' : $mode;

	try {
		$set_settings( array( 'scan_files' => true ) );
		echo "-- $label: full pass\n";

		// One full pass: full_scan(), then run_batch() down the chain. Auto-attach goes on only
		// for the files step, so nothing real gets a parent from the earlier steps.
		$batches  = array();
		$seconds  = array();
		$total    = 0;
		$attached = false;
		$stuck    = 0;
		$last_pos = '';
		$hung     = false;
		$capped   = false;

		$started = microtime( true );
		$scanner->full_scan( false );
		$types = UNMAM_Scanner::get_active_scan_types();
		$step  = 0;
		while ( $step < count( $types ) ) {
			if ( ++$total > $ceiling ) {
				$capped = true;
				break;
			}
			$type = $types[ $step ];
			if ( 'files' === $type && ! $attached ) {
				$set_settings( array( 'scan_files' => true, 'auto_attach' => true ) );
				$attached = true;
			}

			$t0     = microtime( true );
			$result = $scanner->run_batch( $type );
			$seconds[ $type ] = ( isset( $seconds[ $type ] ) ? $seconds[ $type ] : 0 ) + microtime( true ) - $t0;
			$batches[ $type ] = ( isset( $batches[ $type ] ) ? $batches[ $type ] : 0 ) + 1;

			if ( 'files' === $type ) {
				$pos = $position();
				if ( $pos === $last_pos ) {
					if ( ++$stuck >= 50 ) {
						$hung = true;
						break;
					}
				} else {
					$stuck    = 0;
					$last_pos = $pos;
				}
			}
			if ( 'completed' === $result['status'] ) {
				$step++;
			}
		}
		$elapsed = microtime( true ) - $started;

		foreach ( $types as $type ) {
			printf( "info  %-14s %6d batches %8.1f s\n", $type, isset( $batches[ $type ] ) ? $batches[ $type ] : 0, isset( $seconds[ $type ] ) ? $seconds[ $type ] : 0 );
		}
		printf( "info  whole pass %.1f s\n", $elapsed );

		$ok( ! $capped, "chain finished inside $ceiling batches ($total)" );
		$ok( ! $hung, 'files cursor never sat still for 50 batches' );

		if ( ! $capped && ! $hung ) {
			$types_now = UNMAM_Scanner::get_active_scan_types();
			$last_type = end( $types_now );
			$ok( in_array( 'files', $types_now, true ), 'files step active' );
			$ok( 'files' === $last_type, 'files step last' );

			// The bad-UTF-8 name cannot exist on disk here, so write its row directly.
			$components = UNMAM_File_Scanner::components();
			$keys       = wp_list_pluck( $components, 'key' );
			$ok( in_array( $theme, $keys, true ) && in_array( 'plugin:unmam-fixture-plugin', $keys, true ) && in_array( 'mu', $keys, true ), 'components: fixture theme, plugin and mu-plugins' );
			$sorted = $keys;
			usort( $sorted, 'strcmp' );
			$ok( $sorted === $keys, 'components: byte-ordered by key' );
			$ok( ! in_array( 'plugin:' . dirname( UNMAM_PLUGIN_BASENAME ), $keys, true ), 'components: own folder left out' );

			foreach ( $fx['found'] as $case => $img ) {
				if ( 'badutf8' === $case ) {
					continue;
				}
				$ok( UNMAM_Database::get_reference_count( $img ) > 0, "found: $case" );
			}
			foreach ( $fx['ignored'] as $case => $img ) {
				$ok( 0 === UNMAM_Database::get_reference_count( $img ), "ignored: $case" );
			}
			$ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE attachment_id = %d", $fx['bad_id'] ) ), 'ignored: wp_get_attachment_image( ' . $fx['bad_id'] . ' ), not an attachment' );

			$theme_component = $components[ array_search( $theme, $keys, true ) ];
			$bad_row         = UNMAM_File_Scanner::write_reference( $theme_component, "css/caf\xE9.css", $fx['found']['badutf8'], array( 'type' => 'url', 'value' => "https://flp.local/caf\xE9.png" ) );
			$bad_key         = $key_for( $fx['found']['badutf8'] );
			$ok( UNMAM_Database::get_reference_count( $fx['found']['badutf8'] ) > 0 && $bad_row, 'bad utf8 name still credited' );
			$ok( 0 === strpos( $bad_key, $theme . '|' ) && false !== strpos( $bad_key, 'css/caf' ) && '.css' === substr( $bad_key, -4 ) && mb_check_encoding( $bad_key, 'UTF-8' ), 'bad utf8 name: stored key keeps the component and the readable path, and is valid utf8 (' . $bad_key . ')' );
			$bad_value = (string) $wpdb->get_var( $wpdb->prepare( "SELECT reference_value FROM $table WHERE attachment_id = %d AND source_type = 'file' ORDER BY id LIMIT 1", $fx['found']['badutf8'] ) );
			$ok( 0 === strpos( $bad_value, 'https://flp.local/caf' ) && '.png' === substr( $bad_value, -4 ) && mb_check_encoding( $bad_value, 'UTF-8' ), 'bad utf8 name: stored value keeps its readable text, and is valid utf8 (' . $bad_value . ')' );

			$ok( 'theme:unmam-fixture-theme|parts/sidebar.html' === $key_for( $fx['found']['sidebar'] ), 'context_key format' );
			$ok( 'theme_file' === $ctx_for( $fx['found']['sidebar'] ) && 'mu_plugin_file' === $ctx_for( $fx['found']['mu'] ), 'contexts' );
			$ok( 'plugin_file' === $ctx_for( $fx['found']['plugin'] ) && 'plugin:unmam-fixture-plugin|unmam-fixture-plugin.php' === $key_for( $fx['found']['plugin'] ), 'plugin context and key' );
			$ok( 'mu|unmamfix-mu.php' === $key_for( $fx['found']['mu'] ), 'mu key' );

			// context_key() on its own.
			$k = UNMAM_File_Scanner::context_key( 'theme:x', 'a/b.css' );
			$ok( 'theme:x|a/b.css' === $k, 'context_key: short key is component|path' );
			$k = UNMAM_File_Scanner::context_key( 'theme:x', str_repeat( 'd/', 200 ) . 'end.css' );
			$ok( 255 === mb_strlen( $k ) && 0 === strpos( $k, "theme:x|\u{2026}" ) && 'end.css' === substr( $k, -7 ), 'context_key: long path keeps component and the end of the path, 255 characters' );
			$k = UNMAM_File_Scanner::context_key( 'theme:x', str_repeat( "\u{e9}", 300 ) . '.css' );
			$ok( 255 === mb_strlen( $k ) && wp_check_invalid_utf8( $k ) === $k, 'context_key: multibyte path is cut by characters' );
			$k = UNMAM_File_Scanner::context_key( "theme:caf\xE9", "a\xE9/b.css" );
			$ok( 0 === strpos( $k, 'theme:caf' ) && false !== strpos( $k, '|a' ) && '/b.css' === substr( $k, -6 ) && mb_check_encoding( $k, 'UTF-8' ), 'context_key: invalid bytes are replaced, the rest kept (' . $k . ')' );

			// The scrub helper. It is private; the fallback tiers cannot be forced on this core, so
			// mb_scrub()'s answer for the same bytes is pinned here as well.
			$ok( method_exists( 'UNMAM_File_Scanner', 'scrub' ), 'scrub helper exists' );
			if ( method_exists( 'UNMAM_File_Scanner', 'scrub' ) ) {
				$scrub = new ReflectionMethod( 'UNMAM_File_Scanner', 'scrub' );
				$scrub->setAccessible( true );
				$s = $scrub->invoke( null, "css/caf\xE9.css" );
				$ok( 0 === strpos( $s, 'css/caf' ) && '.css' === substr( $s, -4 ) && mb_check_encoding( $s, 'UTF-8' ), 'scrub: bad byte replaced, the rest kept (' . $s . ')' );
				$ok( "ok/caf\u{e9}.css" === $scrub->invoke( null, "ok/caf\u{e9}.css" ), 'scrub: valid input comes back unchanged' );
			}
			if ( function_exists( 'mb_scrub' ) ) {
				$m = mb_scrub( "css/caf\xE9.css", 'UTF-8' );
				$ok( 'css/caf?.css' === $m, 'mb_scrub fallback: same bytes give css/caf?.css' );
			}
			$k = UNMAM_File_Scanner::context_key( 'plugin:' . str_repeat( 'c', 300 ), 'a.css' );
			$ok( 255 >= mb_strlen( $k ), 'context_key: an oversized component key still fits' );

			// A refused insert is tried again under a hashed key.
			$refuse_once = true;
			$refuse      = function ( $query ) use ( &$refuse_once, $table ) {
				if ( $refuse_once && 0 === strpos( ltrim( $query ), 'INSERT INTO `' . $table . '`' ) ) {
					$refuse_once = false;
					return str_replace( 'INSERT INTO', 'INSERT INTX', $query );
				}
				return $query;
			};
			$spare_img = $fx['spare']['rel2'];
			$wpdb->suppress_errors( true );
			add_filter( 'query', $refuse );
			$retry_row = UNMAM_File_Scanner::write_reference( $theme_component, 'css/retry.css', $spare_img, array( 'type' => 'id', 'value' => $spare_img ) );
			remove_filter( 'query', $refuse );
			$wpdb->suppress_errors( false );
			$ok( ! $refuse_once && $retry_row > 0 && $theme . '|#' . md5( 'css/retry.css' ) === $key_for( $spare_img ), 'refused insert: retried once under component|#md5(path)' );
			$wpdb->delete( $table, array( 'attachment_id' => $spare_img, 'source_type' => 'file' ), array( '%d', '%s' ) );

			// Both the insert and its retry refused: no row, and the caller is told.
			$both = null;
			$with_inserts_refused( function () use ( &$both, $theme_component, $spare_img ) {
				$both = UNMAM_File_Scanner::write_reference( $theme_component, 'css/refused.css', $spare_img, array( 'type' => 'id', 'value' => $spare_img ) );
			} );
			$ok( false === $both && 0 === $file_rows_for( $spare_img ), 'both inserts refused: write_reference returns false and writes nothing' );

			$deep_rel = '';
			foreach ( $fx['paths'] as $path ) {
				if ( '/deep.css' === substr( $path, -9 ) ) {
					$deep_rel = substr( $path, strlen( $fx['theme'] ) + 1 );
				}
			}
			$long_key = $key_for( $fx['found']['longpath'] );
			$ok( strlen( $deep_rel ) > 255, 'precondition: the long path is over 255 characters on disk (' . strlen( $deep_rel ) . ')' );
			$ok( '' !== $long_key && 255 >= mb_strlen( $long_key ), 'long path: key fits' );
			$ok( 0 === strpos( $long_key, 'theme:unmam-fixture-theme|' ) && '/deep.css' === substr( $long_key, -9 ), 'long path: key keeps component and file name' );

			$skipped = UNMAM_File_Scanner::skipped();
			$items   = $skipped['items'];
			$huge    = array_filter( $items, function ( $item ) {
				return 'huge.css' === $item['path'];
			} );
			$ok( in_array( 'huge.css', wp_list_pluck( $items, 'path' ), true ), 'over 10 MB reported' );
			$ok( $huge && 'too_big' === reset( $huge )['reason'] && $theme === reset( $huge )['key'], 'over 10 MB: reason too_big, keyed to the theme' );
			$ok( $skipped['total'] >= count( $items ), 'skipped total counts the items' );
			printf( "info  skipped: %d (%d kept)\n", $skipped['total'], count( $items ) );

			$fx_ids = implode( ',', array_map( 'intval', array_merge( array_values( $fx['found'] ), array_values( $fx['ignored'] ), array_values( $fx['spare'] ) ) ) );
			$ok( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN ($fx_ids) AND post_parent > 0" ), 'never attached' );

			$progress = UNMAM_Database::get_scan_progress( 'files' );
			$ok( $progress && 'completed' === $progress['status'] && (int) $progress['processed_items'] === (int) $progress['total_items'] && (int) $progress['total_items'] === count( $components ), 'progress row: completed, one item per component' );
			$ok( (int) get_option( 'unmam_files_scanned_at' ) >= (int) $started, 'unmam_files_scanned_at written' );
			$state = $state_of();
			$ok( $state && $state['files'] > 0 && $state['refs'] > 0 && 0 === $state['attempts'], 'state: counts kept, no attempt pending' );

			$before = $count_file_rows();
			$ok( 'completed' === $scanner->run_batch( 'files' )['status'] && $before === $count_file_rows(), 'late batch after completion changes nothing' );
			$ok( $position() === $state['index'] . '|' . $state['path'] . '|' . $state['candidate'], 'late batch leaves the state alone' );
		}

		// Cursor cases: each is a small pass of its own, on the fixture theme only.
		if ( '' === $mode ) {
			$base = untrailingslashit( wp_get_upload_dir()['baseurl'] );
			$root = $fx['theme'];

			// The first lookups of data/many.json run before the first real image (the 51st URL), so a
			// stop one lookup in cannot tell a resume from a restart. Move the saved index to 1,000.
			$rows_in = function ( array $ids ) use ( $file_rows_for ) {
				return count( array_filter( $ids, function ( $id ) use ( $file_rows_for ) {
					return $file_rows_for( $id ) > 0;
				} ) );
			};
			$many = "$root/data/many.json";
			$orig = file_get_contents( $many );

			echo "-- cursor resumes inside the file\n";
			$start_at_fixture( array( 'seconds' => 0 ) );
			$stopped = $stop_in_many();
			$ok( $stopped, 'cursor resumes inside the file: a batch stopped inside data/many.json' );
			if ( $stopped ) {
				$state              = $state_of();
				$state['candidate'] = 1000;
				update_option( UNMAM_File_Scanner::STATE_OPTION, $state, false );
				$override = array();
				$ok( $finish(), 'cursor resumes inside the file: pass finishes' );
				$ok( 0 === $rows_in( array_slice( $many_ids, 0, 10 ) ) && 10 === $rows_in( array_slice( $many_ids, 10 ) ), 'cursor resumes inside the file: only the URLs from the cursor on were looked up' );
			}

			echo "-- cursor file edited\n";
			try {
				$start_at_fixture( array( 'seconds' => 0 ) );
				$stopped = $stop_in_many();
				$ok( $stopped, 'cursor file edited: a batch stopped inside data/many.json' );
				if ( $stopped ) {
					$state              = $state_of();
					$state['candidate'] = 1000;
					update_option( UNMAM_File_Scanner::STATE_OPTION, $state, false );
					file_put_contents( $many, "\n[\"$base/2026/11/unmamfix-nope-extra.jpg\"]", FILE_APPEND );
					$override = array();
					$ok( $finish(), 'cursor file edited: pass finishes' );
					$ok( 20 === $rows_in( $many_ids ), 'cursor file edited: read again from the start, every image in many.json found (' . $rows_in( $many_ids ) . ' of 20)' );
				}
			} finally {
				file_put_contents( $many, $orig );
			}

			echo "-- cursor neighbours changed\n";
			$a      = "$root/10/a.css";
			$zero   = "$root/10/0.css";
			$orig_a = file_get_contents( $a );
			try {
				$start_at_fixture( array( 'files' => 1 ) );
				$state = array();
				for ( $n = 0; $n < 30 && ( ! $state || '10/a.css' !== $state['path'] ); $n++ ) {
					$state = $one_batch();
				}
				$ok( '10/a.css' === $state['path'] && 0 === $state['candidate'], 'cursor neighbours changed: cursor sits on 10/a.css' );
				unlink( $a );
				file_put_contents( $zero, $orig_a );
				$override = array();
				$ok( $finish(), 'cursor neighbours changed: pass finishes' );
				foreach ( array( 'n10b' => '10/b.css', 'n9a' => '9/a.css', 'n9b' => '9/b.css' ) as $case => $file ) {
					$ok( $file_rows_for( $fx['found'][ $case ] ) > 0, "cursor neighbours changed: $file found" );
				}
			} finally {
				file_put_contents( $a, $orig_a );
				if ( file_exists( $zero ) ) {
					unlink( $zero );
				}
			}

			echo "-- cursor on a name that is not valid UTF-8\n";
			$ok( false === update_option( 'unmamfix_probe', array( 'p' => "caf\xE9" ), false ), 'control: update_option refuses a value with invalid UTF-8' );
			delete_option( 'unmamfix_probe' );
			$save_state = new ReflectionMethod( 'UNMAM_File_Scanner', 'save_state' );
			$save_state->setAccessible( true );
			$start_at_fixture( array( 'files' => 1 ) );
			$state         = $state_of();
			$state['path'] = "css/caf\xE9.css";
			$state['skipped'][] = array( 'key' => $theme, 'path' => "css/caf\xE9.css", 'reason' => 'unreadable' );
			$state['skipped_total']++;
			$save_state->invoke( null, $state );
			$stored = get_option( UNMAM_File_Scanner::STATE_OPTION );
			$ok( is_array( $stored ) && "\0" === substr( $stored['path'], 0, 1 ), 'bad-name cursor: saved, packed behind a NUL byte' );
			$bad_items = array_filter( UNMAM_File_Scanner::skipped()['items'], function ( $item ) {
				return 0 === strpos( $item['path'], 'css/caf' );
			} );
			$bad_item  = $bad_items ? reset( $bad_items ) : array( 'path' => '' );
			$ok( '.css' === substr( $bad_item['path'], -4 ) && mb_check_encoding( $bad_item['path'], 'UTF-8' ), 'bad-name skipped path: readable and valid utf8 (' . $bad_item['path'] . ')' );
			$after = $one_batch();
			$ok( 'css/rel.css' === $after['path'], 'bad-name cursor: the next batch resumed after it (css/rel.css)' );

			echo "-- third attempt skips\n";
			$start_at_fixture( array( 'files' => 1 ) );
			$state            = $one_batch();
			$victim           = UNMAM_File_Walker::walk( $root, $state['path'], 1, 5000 )['files'][0];
			$state['attempt_at'] = $state['index'] . '|' . $state['path'] . '|' . $state['candidate'];
			$state['attempts']   = 2;
			update_option( UNMAM_File_Scanner::STATE_OPTION, $state, false );
			$after   = $one_batch();
			$stopped = array_filter( UNMAM_File_Scanner::skipped()['items'], function ( $item ) {
				return 'stopped' === $item['reason'];
			} );
			$ok( in_array( $victim, wp_list_pluck( $stopped, 'path' ), true ), "third attempt skips: $victim reported as stopped" );
			$ok( 0 === $after['attempts'] && $after['path'] !== $state['path'], 'third attempt skips: attempts cleared and the pass moved on' );

			echo "-- third attempt skips, mid-file\n";
			$start_at_fixture( array( 'seconds' => 0 ) );
			$ok( $stop_in_many(), 'third attempt skips, mid-file: stopped inside data/many.json' );
			$state               = $state_of();
			$state['attempt_at'] = $state['index'] . '|' . $state['path'] . '|' . $state['candidate'];
			$state['attempts']   = 2;
			update_option( UNMAM_File_Scanner::STATE_OPTION, $state, false );
			$after   = $one_batch();
			$stopped = wp_list_pluck( array_filter( UNMAM_File_Scanner::skipped()['items'], function ( $item ) {
				return 'stopped' === $item['reason'];
			} ), 'path' );
			$ok( in_array( 'data/many.json', $stopped, true ), 'third attempt skips, mid-file: the cursor file is reported as stopped' );
			$ok( 'data/many.json' !== $after['path'] || 0 === $after['candidate'], 'third attempt skips, mid-file: the cursor left the file' );

			echo "-- fifth attempt skips the component\n";
			$start_at_fixture( array( 'files' => 1 ) );
			$state               = $state_of();
			$theme_index         = $state['index'];
			$state['attempt_at'] = $state['index'] . '|' . $state['path'] . '|' . $state['candidate'];
			$state['attempts']   = 4;
			update_option( UNMAM_File_Scanner::STATE_OPTION, $state, false );
			$after   = $one_batch();
			$stopped = array_filter( UNMAM_File_Scanner::skipped()['items'], function ( $item ) use ( $theme ) {
				return 'stopped' === $item['reason'] && $theme === $item['key'] && '' === $item['path'];
			} );
			$ok( (bool) $stopped, 'fifth attempt skips the component: reported as stopped, keyed to the theme' );
			$ok( $after['index'] > $theme_index && 0 === $after['attempts'], 'fifth attempt skips the component: moved to the next one, attempts cleared' );
			$ok( 0 === $file_rows_for( $fx['found']['n10a'] ) && 0 === $file_rows_for( $fx['found']['n10b'] ) && 0 === $file_rows_for( $fx['found']['cssabs'] ) && 0 === $file_rows_for( $fx['found']['sidebar'] ), 'fifth attempt skips the component: none of its files were read, not even the first ones in order' );

			echo "-- a find that cannot be written\n";
			$start_at_fixture( array() );
			$refs_before = $state_of()['refs'];
			$override    = array();
			$finished    = false;
			$with_inserts_refused( function () use ( &$finished, $finish ) {
				$finished = $finish();
			} );
			$state_now = $state_of();
			$unwritten = array_filter( UNMAM_File_Scanner::skipped()['items'], function ( $item ) use ( $theme ) {
				return 'unreadable' === $item['reason'] && $theme === $item['key'] && 'style.css' === $item['path'];
			} );
			$ok( $finished, 'a find that cannot be written: pass finishes' );
			$ok( (bool) $unwritten, 'a find that cannot be written: the file is reported as unreadable' );
			$ok( $refs_before === $state_now['refs'], 'a find that cannot be written: not counted in refs' );
			$ok( 0 === $file_rows_for( $fx['found']['cssabs'] ), 'a find that cannot be written: no row' );

			echo "-- rescan without reset\n";
			$rel      = "$root/css/rel.css";
			$orig_rel = file_get_contents( $rel );
			$old      = $fx['found']['cssrel'];
			$new      = $fx['spare']['rel2'];
			try {
				$start_at_fixture( array() );
				$ok( $finish() && $file_rows_for( $old ) > 0 && 0 === $file_rows_for( $new ), 'rescan without reset: before, the old image has a file row and the new one none' );

				file_put_contents( $rel, '.r{background:url(../../../uploads/' . $tail_of( $new ) . ")}\n" );
				$done = false;
				$keep_posts_row( function () use ( $scanner, $start_at_fixture, $finish, &$override, &$done ) {
					$scanner->full_scan( false );
					$start_at_fixture( array(), false );
					$override = array();
					$done     = $finish();
				} );
				$ok( $done, 'rescan without reset: pass finishes' );
				$ok( 0 === $file_rows_for( $old ), 'rescan without reset: old image file row gone' );
				$ok( $file_rows_for( $new ) > 0, 'rescan without reset: new image file row present' );
			} finally {
				file_put_contents( $rel, $orig_rel );
			}
		}
	} finally {
		update_option( 'unmam_settings', $saved_settings );
	}

	$ok( ! $notices, 'no notices or warnings from the plugin' . ( $notices ? ': ' . $notices[0] : '' ) );
	echo "\nfailures: $fail\n";
	return;
}

// settings mode
echo "-- settings\n";
if ( ! class_exists( 'UNMAM_Admin' ) ) {
	require_once UNMAM_PLUGIN_DIR . 'includes/admin/class-unmam-admin.php';
}
if ( ! function_exists( 'add_settings_error' ) ) {
	require_once ABSPATH . 'wp-admin/includes/template.php';
}

$form = array(
	'unmam_batch_size'           => '200',
	'unmam_scan_post_content'    => '1',
	'unmam_scan_featured_images' => '1',
	'unmam_scan_gutenberg'       => '1',
	'unmam_scan_acf_fields'      => '1',
	'unmam_scan_widgets'         => '1',
	'unmam_scan_options'         => '1',
	'unmam_scan_post_type'       => isset( $saved_settings['scan_post_types'] ) ? $saved_settings['scan_post_types'] : array(),
	'unmam_scan_taxonomy'        => isset( $saved_settings['scan_taxonomies'] ) ? $saved_settings['scan_taxonomies'] : array(),
	'unmam_resource_mode'        => 'auto',
	'unmam_processing_mode'      => 'frontend',
);
$save = function ( array $post ) {
	$_POST   = $post;
	$admin   = UNMAM_Admin::instance();
	$method  = new ReflectionMethod( $admin, 'save_settings' );
	$method->setAccessible( true );
	$method->invoke( $admin );
	$_POST = array();
};
$build_rows = function () use ( $scanner, $start_at_fixture, $finish, &$override, $keep_posts_row ) {
	$done = false;
	$keep_posts_row( function () use ( $scanner, $start_at_fixture, $finish, &$override, &$done ) {
		$scanner->full_scan( false );
		$start_at_fixture( array(), false );
		$override = array();
		$done     = $finish();
	} );
	return $done;
};

try {
	$set_settings( array( 'scan_files' => true ) );
	$ok( $build_rows() && $count_file_rows() > 0, 'precondition: a files pass left file rows' );
	$ok( UNMAM_Database::get_reference_count( $fx['found']['sidebar'] ) > 0, 'precondition: the sidebar image is credited' );
	$ok( (int) get_option( 'unmam_files_scanned_at' ) > 0, 'precondition: the completed pass stored its time' );

	// Untick through the settings form.
	$save( $form );
	$ok( false === Unattached_Media_Manager::get_setting( 'scan_files' ), 'untick: saved as false' );
	$ok( 0 === $count_file_rows(), 'untick: file rows gone at once' );
	$ok( false === get_option( UNMAM_File_Scanner::STATE_OPTION ), 'untick: state gone' );
	$ok( false === get_option( 'unmam_files_scanned_at' ), 'untick: the last-scanned time is gone' );
	$ok( ! in_array( 'files', UNMAM_Scanner::get_active_scan_types(), true ), 'untick: files not in the chain' );
	$ok( 0 === UNMAM_Database::get_reference_count( $fx['found']['sidebar'] ), 'untick: the sidebar image is unused again' );

	// Unticked while the step is running, by a path that does not clear anything itself.
	$set_settings( array( 'scan_files' => true ) );
	UNMAM_Database::reset_scan_progress( 'files' );
	delete_option( UNMAM_File_Scanner::STATE_OPTION );
	$override = array( 'files' => 3 );
	$scanner->run_batch( 'files' );
	$ok( false !== get_option( UNMAM_File_Scanner::STATE_OPTION ) && 'running' === UNMAM_Database::get_scan_progress( 'files' )['status'], 'mid-pass: the step is running with state' );
	update_option( 'unmam_files_scanned_at', time(), false );
	$set_settings( array( 'scan_files' => false ) );
	$override = array();
	$result   = $scanner->run_batch( 'files' );
	$ok( 'completed' === $result['status'], 'mid-pass untick: one files batch returns completed' );
	$ok( false === get_option( 'unmam_files_scanned_at' ), 'mid-pass untick: the last-scanned time is gone' );
	$ok( 0 === $count_file_rows() && false === get_option( UNMAM_File_Scanner::STATE_OPTION ), 'mid-pass untick: rows and state cleared' );
	$ok( 'completed' === UNMAM_Database::get_scan_progress( 'files' )['status'], 'mid-pass untick: progress row completed' );

	// Retick through the form, then scan.
	$form['unmam_scan_files'] = '1';
	$save( $form );
	$ok( true === Unattached_Media_Manager::get_setting( 'scan_files' ), 'retick: saved as true' );
	$types_now = UNMAM_Scanner::get_active_scan_types();
	$ok( 'files' === end( $types_now ), 'retick: files is the last step again' );
	$ok( 0 === $count_file_rows(), 'retick: no rows until a scan runs' );
	$ok( $build_rows() && UNMAM_Database::get_reference_count( $fx['found']['sidebar'] ) > 0, 'retick and scan: the rows are back' );

	// Upgrade shape and a saved false.
	$old = $saved_settings;
	unset( $old['scan_files'] );
	update_option( 'unmam_settings', $old );
	$ok( true === Unattached_Media_Manager::get_setting( 'scan_files' ), '1.3.3-shaped settings read back true' );
	$stored = get_option( 'unmam_settings' );
	$ok( array_key_exists( 'scan_files', $stored ) && true === $stored['scan_files'], '1.3.3-shaped settings: the migration saved the key' );

	update_option( 'unmam_settings', array_merge( $old, array( 'scan_files' => false ) ) );
	$reads = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$reads[] = Unattached_Media_Manager::get_setting( 'scan_files' );
	}
	$ok( array( false, false, false ) === $reads, 'a saved false stays false over three reads' );
} finally {
	// The settings as they were before 1.4.0 touched them.
	$snapshot = '/tmp/unmam140-settings.txt';
	$restore  = is_readable( $snapshot ) ? unserialize( (string) file_get_contents( $snapshot ), array( 'allowed_classes' => false ) ) : $saved_settings;
	update_option( 'unmam_settings', is_array( $restore ) ? $restore : $saved_settings );
	$_POST = array();
}

$ok( ! $notices, 'no notices or warnings from the plugin' . ( $notices ? ': ' . $notices[0] : '' ) );
echo "\nfailures: $fail\n";
