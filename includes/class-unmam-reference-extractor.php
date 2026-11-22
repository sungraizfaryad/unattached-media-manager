<?php
/**
 * Reference Extractor for Unattached Media Manager
 *
 * Shared text-scanning logic for finding attachment references inside a raw
 * string: `wp-image-{ID}` editor classes and direct uploaded-media URLs, plus
 * (for the file scan) uploads tails and literal IDs in core attachment calls.
 * Originally lived only in UNMAM_Custom_Table_Parser; pulled out here because
 * the meta parser and ACF parser both need the same fallback for freeform
 * HTML/text field values.
 *
 * @package UnattachedMediaManager
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reference Extractor class.
 */
class UNMAM_Reference_Extractor {

    /**
     * A blob larger than this is skipped outright, so one oversized serialized
     * value cannot stall a batch.
     */
    const MAX_TEXT_LENGTH = 1048576; // 1MB.

    /**
     * File extensions treated as media when matching URLs.
     */
    const MEDIA_EXTENSIONS = 'jpe?g|png|gif|webp|svg|bmp|ico|avif|mp4|m4v|webm|ogg|ogv|mp3|wav|m4a|pdf|docx?|pptx?|xlsx?|zip';

    /**
     * Cheap pre-filter: does this string contain anything worth regex-scanning?
     *
     * Not a matcher on its own. Callers should still validate whatever
     * extract_from_text() returns before trusting it.
     *
     * @param mixed $text Candidate value.
     * @return bool
     */
    public static function looks_extractable( $text ) {
        if ( ! is_string( $text ) || '' === $text ) {
            return false;
        }

        return false !== strpos( $text, 'wp-image-' ) || false !== strpos( $text, '/wp-content/uploads/' );
    }

    /**
     * Extract attachment references from a text blob.
     *
     * Detects both `wp-image-{ID}` editor classes and uploaded-media URLs,
     * resolving URLs to attachment IDs via the shared resolver. Finding and resolving
     * are separate steps (see find_candidates() and resolve_candidate()); this runs both.
     *
     * @param mixed $text           Text to scan.
     * @param bool  $allow_relative Also match `/wp-content/uploads/...` and `//host/...`
     *                              forms, which CSS often uses. Off for every caller except
     *                              the design parser, so their results don't change.
     * @return array Map of attachment_id => matched URL (string) or attachment_id (int).
     */
    public static function extract_from_text( $text, $allow_relative = false ) {
        $results = array();

        if ( ! is_string( $text ) ) {
            return $results;
        }

        foreach ( self::collect( $text, array( 'relative' => $allow_relative ) ) as $candidate ) {
            $attachment_id = self::resolve_candidate( $candidate );
            if ( ! $attachment_id ) {
                continue;
            }

            if ( 'id' === $candidate['type'] ) {
                $results[ $attachment_id ] = $attachment_id;
            } elseif ( empty( $candidate['relative'] ) || ! isset( $results[ $attachment_id ] ) ) {
                // An absolute URL replaces an id entry for the same file, a relative one never does.
                $results[ $attachment_id ] = $candidate['value'];
            }
        }

        return $results;
    }

    /**
     * Find things in a text blob that may point at an attachment, without resolving any.
     *
     * Resolving costs database queries, so a caller that has to stop between lookups
     * (the file scan) finds first and resolves one candidate at a time.
     *
     * Options, all off by default:
     *   relative  Root- and protocol-relative upload URLs, made absolute.
     *   tails     Paths that start at the uploads folder name, as in `../../uploads/2024/a.jpg`.
     *   calls     Literal IDs in core attachment function calls.
     *
     * A regex that fails (PCRE gives up on a hostile blob) leaves its pass out of the result.
     * Pass $incomplete to learn that: it is set to true then, and never set back to false.
     *
     * @param string    $text       Text to scan.
     * @param array     $options    See above.
     * @param bool|null $incomplete Set to true when any pattern match failed.
     * @return array List of array( 'type' => 'id'|'url'|'exact_url', 'value' => int|string ),
     *               in the order found and without repeats. Empty for an oversized blob.
     */
    public static function find_candidates( string $text, array $options = array(), &$incomplete = null ): array {
        $found = array();

        foreach ( self::collect( $text, $options, $incomplete ) as $candidate ) {
            $found[] = array(
                'type'  => $candidate['type'],
                'value' => $candidate['value'],
            );
        }

        return $found;
    }

    /**
     * Turn one candidate into an attachment ID.
     *
     * An `exact_url` has to match a library file by its full path. A plain `url` may also
     * match by file name, which is what lets a CDN or a changed domain resolve.
     *
     * @param array $candidate Item from find_candidates().
     * @return int Attachment ID, or 0.
     */
    public static function resolve_candidate( array $candidate ): int {
        $type  = isset( $candidate['type'] ) ? $candidate['type'] : '';
        $value = isset( $candidate['value'] ) ? $candidate['value'] : '';

        if ( 'id' === $type ) {
            return UNMAM_Database::is_attachment_id( $value ) ? (int) $value : 0;
        }

        if ( 'url' === $type ) {
            return (int) UNMAM_Database::url_to_attachment_id( $value );
        }

        if ( 'exact_url' === $type ) {
            return (int) UNMAM_Database::url_to_attachment_id( $value, false );
        }

        return 0;
    }

    /**
     * The uploads folder as it appears in a path inside wp-content, such as `uploads`.
     *
     * That is the baseurl path with the content path taken off the front, or the last segment
     * of it when the uploads folder lives somewhere else. Empty when uploads is served from a
     * domain root. Only paths are compared, since the content and uploads URLs can differ in
     * scheme. Read on every call: multisite and `upload_dir` filters change the answer.
     *
     * @return string
     */
    public static function tail_anchor(): string {
        $uploads = untrailingslashit( (string) wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_PATH ) );
        if ( '' === $uploads ) {
            return '';
        }

        $content = untrailingslashit( (string) wp_parse_url( content_url(), PHP_URL_PATH ) );
        if ( 0 === strpos( $uploads, $content . '/' ) ) {
            return substr( $uploads, strlen( $content ) + 1 );
        }

        return ltrim( substr( $uploads, (int) strrpos( $uploads, '/' ) ), '/' );
    }

    /**
     * The one place that finds candidates. Relative upload URLs carry a `relative` flag
     * so extract_from_text() can keep its precedence; find_candidates() drops it.
     *
     * @param string    $text       Text to scan.
     * @param array     $options    See find_candidates().
     * @param bool|null $incomplete See find_candidates().
     * @return array
     */
    private static function collect( $text, $options, &$incomplete = null ) {
        if ( '' === $text || strlen( $text ) > self::MAX_TEXT_LENGTH ) {
            return array();
        }

        $options = array_merge(
            array(
                'relative' => false,
                'tails'    => false,
                'calls'    => false,
            ),
            $options
        );

        $found = array();
        $seen  = array();
        $add   = function ( $type, $value, $relative = false ) use ( &$found, &$seen ) {
            $key = $type . '|' . $value;
            if ( isset( $seen[ $key ] ) ) {
                return;
            }
            $seen[ $key ] = true;

            $candidate = array(
                'type'  => $type,
                'value' => $value,
            );
            if ( $relative ) {
                $candidate['relative'] = true;
            }
            $found[] = $candidate;
        };

        // 1. wp-image-{ID} classes (editor-inserted images). Every ID lifted out
        // of markup is untrusted, so it must resolve to a real attachment.
        if ( self::match_all( '/wp-image-(\d+)/', $text, $matches, 0, $incomplete ) ) {
            foreach ( $matches[1] as $id ) {
                $add( 'id', (int) $id );
            }
        }

        // 2. Direct media URLs.
        $spans  = array();
        $url_re = '#https?://[^\s"\'<>()]+?\.(?:' . self::MEDIA_EXTENSIONS . ')#i';
        if ( self::match_all( $url_re, $text, $matches, PREG_OFFSET_CAPTURE, $incomplete ) ) {
            foreach ( $matches[0] as $match ) {
                $add( 'url', $match[0] );
                $spans[] = array( $match[1], strlen( $match[0] ) );
            }
        }

        if ( $options['relative'] ) {
            foreach ( self::relative_upload_urls( $text, $incomplete ) as $hit ) {
                $add( 'url', $hit['url'], true );
                $spans[] = array( $hit['offset'], $hit['length'] );
            }
        }

        // 3. Uploads tails, outside whatever the URL passes already claimed.
        $anchor = $options['tails'] ? self::tail_anchor() : '';
        if ( '' !== $anchor ) {
            $re = '#(?<![\w-])' . preg_quote( $anchor, '#' ) . '/([^\s"\'<>()]+?\.(?:' . self::MEDIA_EXTENSIONS . '))#i';
            if ( self::match_all( $re, self::blank_spans( $text, $spans ), $matches, 0, $incomplete ) ) {
                $baseurl = untrailingslashit( wp_get_upload_dir()['baseurl'] );
                foreach ( $matches[1] as $tail ) {
                    $add( 'exact_url', $baseurl . '/' . $tail );
                }
            }
        }

        // 4. Literal IDs handed to core attachment functions.
        if ( $options['calls'] ) {
            $re = '#\b(?:wp_get_attachment_image|wp_get_attachment_image_src|wp_get_attachment_image_url|wp_get_attachment_url|wp_get_attachment_link|wp_get_attachment_metadata|wp_get_attachment_caption|wp_get_attachment_image_srcset|wp_get_attachment_image_sizes|wp_get_attachment_thumb_url|wp_get_original_image_url|wp_get_original_image_path|get_attached_file|image_downsize)\s*\(\s*[\'"]?(\d+)[\'"]?\s*[,)]#';
            if ( self::match_all( $re, $text, $matches, 0, $incomplete ) ) {
                foreach ( $matches[1] as $id ) {
                    $add( 'id', (int) $id );
                }
            }
        }

        return $found;
    }

    /**
     * preg_match_all() that reports a failed match instead of passing it off as no match.
     *
     * @param string    $re         Pattern.
     * @param string    $text       Subject.
     * @param array     $matches    Filled as preg_match_all() would.
     * @param int       $flags      preg_match_all() flags.
     * @param bool|null $incomplete Set to true when the match failed.
     * @return int Number of matches, 0 on a failure.
     */
    private static function match_all( $re, $text, &$matches, $flags, &$incomplete ) {
        $count = preg_match_all( $re, $text, $matches, $flags );
        if ( false === $count ) {
            $incomplete = true;
            return 0;
        }

        return $count;
    }

    /**
     * Replace the given byte ranges with spaces, so the text keeps its length.
     *
     * @param string $text
     * @param array  $spans List of array( offset, length ).
     * @return string
     */
    private static function blank_spans( $text, $spans ) {
        if ( ! $spans ) {
            return $text;
        }

        usort(
            $spans,
            function ( $a, $b ) {
                return $a[0] - $b[0];
            }
        );

        $out = '';
        $pos = 0;
        foreach ( $spans as $span ) {
            $from = max( $span[0], $pos );
            $to   = $span[0] + $span[1];
            if ( $to <= $from ) {
                continue;
            }
            $out .= substr( $text, $pos, $from - $pos ) . str_repeat( ' ', $to - $from );
            $pos  = $to;
        }

        return $out . substr( $text, $pos );
    }

    /**
     * Upload URLs written without a scheme or host, made absolute.
     *
     * The lookbehind keeps this off the tail of an absolute URL, which the main pattern
     * has already handled.
     *
     * @param string    $text
     * @param bool|null $incomplete See find_candidates().
     * @return array List of array( 'url' => absolute URL, 'offset' => int, 'length' => int ),
     *               one per occurrence; the offset and length are those of the text as written.
     */
    private static function relative_upload_urls( $text, &$incomplete = null ) {
        $baseurl = untrailingslashit( wp_get_upload_dir()['baseurl'] );
        $path    = untrailingslashit( (string) wp_parse_url( $baseurl, PHP_URL_PATH ) );

        // Uploads served from a domain root leave nothing to anchor on.
        if ( '' === $path ) {
            return array();
        }

        $re = '#(?<![\w.:/-])(?://[^\s"\'<>()/]+)?' . preg_quote( $path, '#' ) . '/[^\s"\'<>()]+?\.(?:' . self::MEDIA_EXTENSIONS . ')#i';
        if ( ! self::match_all( $re, $text, $matches, PREG_OFFSET_CAPTURE, $incomplete ) ) {
            return array();
        }

        $origin = substr( $baseurl, 0, -strlen( $path ) );
        $scheme = (string) wp_parse_url( $baseurl, PHP_URL_SCHEME );

        $hits = array();
        foreach ( $matches[0] as $match ) {
            $hits[] = array(
                'url'    => 0 === strpos( $match[0], '//' ) ? $scheme . ':' . $match[0] : $origin . $match[0],
                'offset' => $match[1],
                'length' => strlen( $match[0] ),
            );
        }

        return $hits;
    }
}
