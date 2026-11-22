<?php
/**
 * Checks for the 1.4.0 file scan step, against what files-seed.php made.
 *
 * Without an argument: one full pass through full_scan() and a run_batch() loop over the
 * active scan types at the normal limits, then the cursor cases.
 *   tiny      same pass, files step limited to 3 files, 50 entries and 1 second a batch
 *   pause     same pass, with the files step told to stop after one unit every batch
 *   settings  the scan_files setting: untick, untick mid-pass, retick, upgrade shape
 *
 * Every mode puts settings and fixture files back before it exits.
 * Run: dev/flpwp.sh eval-file dev/fixture/files-check.php [tiny|pause|settings]
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
			$ok( '' !== $bad_key && wp_check_invalid_utf8( $bad_key ) === $bad_key, 'bad utf8 name: stored key is valid utf8' );

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
			$ok( '' !== $k && wp_check_invalid_utf8( $k ) === $k, 'context_key: invalid bytes are replaced' );
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
			$save_state->invoke( null, $state );
			$stored = get_option( UNMAM_File_Scanner::STATE_OPTION );
			$ok( is_array( $stored ) && "\0" === substr( $stored['path'], 0, 1 ), 'bad-name cursor: saved, packed behind a NUL byte' );
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

	// Untick through the settings form.
	$save( $form );
	$ok( false === Unattached_Media_Manager::get_setting( 'scan_files' ), 'untick: saved as false' );
	$ok( 0 === $count_file_rows(), 'untick: file rows gone at once' );
	$ok( false === get_option( UNMAM_File_Scanner::STATE_OPTION ), 'untick: state gone' );
	$ok( ! in_array( 'files', UNMAM_Scanner::get_active_scan_types(), true ), 'untick: files not in the chain' );
	$ok( 0 === UNMAM_Database::get_reference_count( $fx['found']['sidebar'] ), 'untick: the sidebar image is unused again' );

	// Unticked while the step is running, by a path that does not clear anything itself.
	$set_settings( array( 'scan_files' => true ) );
	UNMAM_Database::reset_scan_progress( 'files' );
	delete_option( UNMAM_File_Scanner::STATE_OPTION );
	$override = array( 'files' => 3 );
	$scanner->run_batch( 'files' );
	$ok( false !== get_option( UNMAM_File_Scanner::STATE_OPTION ) && 'running' === UNMAM_Database::get_scan_progress( 'files' )['status'], 'mid-pass: the step is running with state' );
	$set_settings( array( 'scan_files' => false ) );
	$override = array();
	$result   = $scanner->run_batch( 'files' );
	$ok( 'completed' === $result['status'], 'mid-pass untick: one files batch returns completed' );
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
