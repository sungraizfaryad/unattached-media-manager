<?php
/**
 * File Walker for Unattached Media Manager
 *
 * Lists the eligible files of one theme or plugin in byte order, a slice at a
 * time, so the file scan can stop on a budget and pick up where it left off.
 *
 * @package UnattachedMediaManager
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * File Walker class.
 */
class UNMAM_File_Walker {

    const MAX_FILE_BYTES = 10485760; // 10 MB.
    const MAX_DEPTH      = 25;
    const EXTENSIONS     = array( 'css', 'scss', 'sass', 'less', 'php', 'html', 'htm', 'js', 'json', 'twig' );
    const SKIP_DIRS      = array( 'node_modules', 'vendor', 'bower_components' );

    /**
     * Walk a component and return the next slice of its files.
     *
     * Names are compared with strcmp() only. A loose comparison puts '9' before
     * '10', and a resumed walk would skip whole folders.
     *
     * $after is a relative path ('' to start). At each depth, names sorting before
     * its segment are passed over without counting, the matching name is entered
     * (or ignored, when it is a file or a folder that is never walked) and also
     * not counted, and everything after is taken. The cursor entry was counted by
     * the call that returned it, so a call always makes progress.
     *
     * 'last' is the relative path of the last entry looked at and counted, whether
     * it was taken, ignored, skipped or a folder; '' when nothing was. Resume with
     * it. 'entries' counts every name looked at, eligible or not.
     *
     * @param string $root        Absolute path to a folder, or to a single file.
     * @param string $after       Relative path to resume after.
     * @param int    $max_files   Stop once this many files were returned.
     * @param int    $max_entries Stop once this many names were looked at.
     * @return array {files: string[], done: bool, entries: int, skipped: array[], last: string}
     */
    public static function walk( $root, $after, $max_files, $max_entries ) {
        $out   = array(
            'files'   => array(),
            'done'    => true,
            'entries' => 0,
            'skipped' => array(),
            'last'    => '',
        );
        $after = (string) $after;

        // Sizes and link targets can change between calls in one request.
        clearstatcache( true );

        if ( is_file( $root ) ) {
            return self::walk_file( $root, $after, $out );
        }

        $real = realpath( $root );
        if ( false === $real || ! is_dir( $real ) ) {
            return $out;
        }
        $real = untrailingslashit( wp_normalize_path( $real ) );

        $state = array(
            'files'       => array(),
            'skipped'     => array(),
            'entries'     => 0,
            'last'        => '',
            'stop'        => false,
            'max_files'   => max( 1, (int) $max_files ),
            'max_entries' => max( 1, (int) $max_entries ),
            'prefix'      => trailingslashit( $real ),
            'visited'     => array( $real => true ),
        );

        $cursor = array_values( array_filter( explode( '/', $after ), 'strlen' ) );
        self::walk_dir( $real, '', 0, $cursor, '' === $after, $state );

        return array(
            'files'   => $state['files'],
            'done'    => ! $state['stop'],
            'entries' => $state['entries'],
            'skipped' => $state['skipped'],
            'last'    => $state['last'],
        );
    }

    /**
     * A component that is one file, such as a plugin sitting loose in the plugins folder.
     */
    private static function walk_file( $path, $after, $out ) {
        $name = basename( $path );
        if ( strcmp( $name, $after ) <= 0 ) {
            return $out;
        }

        $out['entries'] = 1;
        $out['last']    = $name;

        if ( ! self::is_eligible_name( $name ) ) {
            return $out;
        }

        $problem = self::file_problem( $path );
        if ( '' !== $problem ) {
            $out['skipped'][] = array( 'path' => $name, 'reason' => $problem );
        } else {
            $out['files'][] = $name;
        }
        return $out;
    }

    /**
     * Walk one folder. $cursor holds the rest of the resume path below it, and
     * $report says whether an unreadable folder should be reported (not when it
     * is only being re-entered on the way to the cursor).
     */
    private static function walk_dir( $dir, $rel, $depth, $cursor, $report, &$state ) {
        $names = self::list_names( $dir );
        if ( false === $names ) {
            if ( $report ) {
                $state['skipped'][] = array( 'path' => $rel, 'reason' => 'unreadable' );
            }
            return;
        }

        $segment = isset( $cursor[0] ) ? $cursor[0] : null;
        $rest    = array_slice( $cursor, 1 );

        foreach ( $names as $name ) {
            $is_cursor = false;
            if ( null !== $segment ) {
                if ( strcmp( $name, $segment ) < 0 ) {
                    continue;
                }
                $is_cursor = $name === $segment;
            }

            if ( ! $is_cursor ) {
                if ( count( $state['files'] ) >= $state['max_files'] || $state['entries'] >= $state['max_entries'] ) {
                    $state['stop'] = true;
                    return;
                }
                $state['entries']++;
            }

            $path = '' === $rel ? $name : $rel . '/' . $name;
            if ( ! $is_cursor ) {
                $state['last'] = $path;
            }

            $abs  = $dir . '/' . $name;
            $real = '';
            $kind = self::entry_kind( $dir, $name, $abs, $state, $real );

            if ( 'dir' === $kind ) {
                if ( $depth + 1 > self::MAX_DEPTH ) {
                    if ( ! $is_cursor ) {
                        $state['skipped'][] = array( 'path' => $path, 'reason' => 'too_deep' );
                    }
                    continue;
                }
                $state['visited'][ $real ] = true;
                self::walk_dir( $real, $path, $depth + 1, $is_cursor ? $rest : array(), ! $is_cursor, $state );
                if ( $state['stop'] ) {
                    return;
                }
            } elseif ( 'file' === $kind && ! $is_cursor ) {
                $problem = self::file_problem( $abs );
                if ( '' !== $problem ) {
                    $state['skipped'][] = array( 'path' => $path, 'reason' => $problem );
                } else {
                    $state['files'][] = $path;
                }
            }
        }
    }

    /**
     * What an entry is for the walk: 'dir' to enter, 'file' to read, '' to ignore.
     * $real gets the resolved folder path for a 'dir'.
     */
    private static function entry_kind( $dir, $name, $abs, $state, &$real ) {
        if ( 0 === strpos( $name, '.' ) ) {
            return '';
        }

        $linked = is_link( $abs );
        if ( $linked ) {
            $target = realpath( $abs );
            if ( false === $target ) {
                return '';
            }
            $real = wp_normalize_path( $target );
            // trailingslashit() in the prefix keeps foo-pro from passing for a folder inside foo.
            if ( 0 !== strpos( $real, $state['prefix'] ) ) {
                return '';
            }
        } else {
            $real = $dir . '/' . $name;
        }

        if ( is_dir( $abs ) ) {
            if ( in_array( $name, self::SKIP_DIRS, true ) ) {
                return '';
            }
            if ( $linked && isset( $state['visited'][ $real ] ) ) {
                return '';
            }
            return 'dir';
        }

        if ( is_file( $abs ) && self::is_eligible_name( $name ) ) {
            return 'file';
        }
        return '';
    }

    private static function is_eligible_name( $name ) {
        if ( 0 === strpos( $name, '.' ) ) {
            return false;
        }
        return in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), self::EXTENSIONS, true );
    }

    /**
     * '' when the file can be read, else the reason it is skipped.
     */
    private static function file_problem( $abs ) {
        if ( ! is_readable( $abs ) ) {
            return 'unreadable';
        }
        $size = filesize( $abs );
        if ( false === $size ) {
            return 'unreadable';
        }
        return $size > self::MAX_FILE_BYTES ? 'too_big' : '';
    }

    /**
     * Names in a folder, byte-sorted, or false when it cannot be listed.
     */
    private static function list_names( $dir ) {
        if ( ! is_readable( $dir ) ) {
            return false;
        }
        $list = @scandir( $dir, SCANDIR_SORT_NONE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the folder can vanish after is_readable(); false is handled.
        if ( ! is_array( $list ) ) {
            return false;
        }

        $names = array();
        foreach ( $list as $name ) {
            $name = (string) $name;
            if ( '.' !== $name && '..' !== $name ) {
                $names[] = $name;
            }
        }
        usort( $names, 'strcmp' );
        return $names;
    }
}
