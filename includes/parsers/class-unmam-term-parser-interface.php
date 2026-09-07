<?php
/**
 * Term Parser Interface for Media Usage Inspector
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Term Parser Interface
 *
 * Separate from UNMAM_Parser_Interface so third-party parsers registered
 * through the unmam_parsers filter, which only know about parse_post(),
 * keep working untouched. A parser implements this one too when it also
 * knows how to read a taxonomy term.
 */
interface UNMAM_Term_Parser_Interface {

    /**
     * Parse a term for media references
     *
     * @param WP_Term $term Term object.
     * @return array Array of reference data.
     */
    public function parse_term( $term );
}
