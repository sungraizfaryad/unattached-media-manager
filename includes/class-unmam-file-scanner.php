<?php
/**
 * File Scanner for Unattached Media Manager
 *
 * Reads theme and plugin files for image references. A file is read in
 * overlapping pieces of at most 1 MB, which is all the extractor will take
 * in one go, so a URL cut by one piece's end is whole in the next.
 *
 * @package UnattachedMediaManager
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * File Scanner class.
 */
class UNMAM_File_Scanner {

    const PIECE_BYTES   = 1048576; // Same as UNMAM_Reference_Extractor::MAX_TEXT_LENGTH.
    const OVERLAP_BYTES = 4096;

    /**
     * Byte ranges to read for a file of this size.
     *
     * Each piece starts OVERLAP_BYTES before the previous one ended and is never longer
     * than PIECE_BYTES, overlap included. The last one ends at $size.
     *
     * @param int $size File size in bytes.
     * @return array List of array( offset, length ). Empty for an empty file.
     */
    public static function piece_ranges( int $size ): array {
        $ranges = array();
        $offset = 0;

        while ( $offset < $size ) {
            $length   = min( $size - $offset, self::PIECE_BYTES );
            $ranges[] = array( $offset, $length );

            if ( $offset + $length >= $size ) {
                break;
            }
            $offset += $length - self::OVERLAP_BYTES;
        }

        return $ranges;
    }

    /**
     * Everything in one file that may point at an attachment, across all its pieces.
     *
     * The list is de-duplicated on type plus value and keeps the order in which candidates
     * were first seen, so it comes out the same on every read; the scan cursor counts into it.
     *
     * $incomplete is set to false on entry and to true when part of the file could not be
     * searched: the file or a piece could not be read, or a pattern match gave up. Whatever
     * was found is still returned, so a true flag means "there may be more", not "nothing".
     *
     * @param string    $abs_path   Absolute path to the file.
     * @param bool|null $incomplete See above.
     * @return array List of array( 'type', 'value' ), as find_candidates() returns.
     */
    public static function file_candidates( string $abs_path, &$incomplete = null ): array {
        $incomplete = false;

        clearstatcache( true, $abs_path );
        if ( ! is_file( $abs_path ) || ! is_readable( $abs_path ) ) {
            $incomplete = true;
            return array();
        }

        $size = @filesize( $abs_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file can vanish after is_readable(); false is handled.
        if ( false === $size ) {
            $incomplete = true;
            return array();
        }

        $options = array(
            'relative' => true,
            'tails'    => true,
            'calls'    => 'php' === strtolower( pathinfo( $abs_path, PATHINFO_EXTENSION ) ),
        );

        $found = array();
        $seen  = array();

        foreach ( self::piece_ranges( $size ) as $range ) {
            $piece = @file_get_contents( $abs_path, false, null, $range[0], $range[1] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the file can shrink or vanish since filesize(); false is handled.
            if ( false === $piece ) {
                $incomplete = true;
                continue;
            }

            // JSON escapes slashes, and JSON stored inside JSON escapes the escape.
            $plain = preg_replace( '#\\\\+/#', '/', $piece );
            if ( null === $plain ) {
                $incomplete = true;
                $plain      = $piece;
            }

            foreach ( UNMAM_Reference_Extractor::find_candidates( $plain, $options, $incomplete ) as $candidate ) {
                $key = $candidate['type'] . '|' . $candidate['value'];
                if ( isset( $seen[ $key ] ) ) {
                    continue;
                }
                $seen[ $key ] = true;
                $found[]      = $candidate;
            }
        }

        return $found;
    }
}
