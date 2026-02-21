<?php
/**
 * Parser Interface for Media Usage Inspector
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parser Interface
 *
 * All parsers must implement this interface
 */
interface UNMAM_Parser_Interface {

    /**
     * Parse a post for media references
     *
     * @param WP_Post $post Post object.
     * @return array Array of reference data.
     */
    public function parse_post( $post );

    /**
     * Get parser name
     *
     * @return string
     */
    public function get_name();
}
