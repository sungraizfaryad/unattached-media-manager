<?php
/**
 * Reference Extractor for Unattached Media Manager
 *
 * Shared text-scanning logic for finding attachment references inside a raw
 * string: `wp-image-{ID}` editor classes and direct uploaded-media URLs.
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
     * resolving URLs to attachment IDs via the shared resolver.
     *
     * @param mixed $text Text to scan.
     * @return array      Map of attachment_id => matched URL (string) or attachment_id (int).
     */
    public static function extract_from_text( $text ) {
        $results = array();

        if ( ! is_string( $text ) || '' === $text || strlen( $text ) > self::MAX_TEXT_LENGTH ) {
            return $results;
        }

        // 1. wp-image-{ID} classes (editor-inserted images). Every ID lifted out
        // of markup is untrusted, so it must resolve to a real attachment.
        if ( preg_match_all( '/wp-image-(\d+)/', $text, $matches ) ) {
            foreach ( $matches[1] as $id ) {
                $id = (int) $id;
                if ( UNMAM_Database::is_attachment_id( $id ) ) {
                    $results[ $id ] = $id;
                }
            }
        }

        // 2. Direct media URLs.
        $url_re = '#https?://[^\s"\'<>()]+?\.(?:jpe?g|png|gif|webp|svg|bmp|ico|avif|mp4|m4v|webm|ogg|ogv|mp3|wav|m4a|pdf|docx?|pptx?|xlsx?|zip)#i';
        if ( preg_match_all( $url_re, $text, $url_matches ) ) {
            foreach ( array_unique( $url_matches[0] ) as $url ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $url );
                if ( $attachment_id ) {
                    $results[ (int) $attachment_id ] = $url;
                }
            }
        }

        return $results;
    }
}
