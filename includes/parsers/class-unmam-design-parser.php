<?php
/**
 * Design Parser for Unattached Media Manager
 *
 * Additional CSS and Site Editor styles aren't markup, so the content and block parsers
 * find nothing in them. Templates and template parts are block markup and are left to
 * those parsers.
 *
 * @package UnattachedMediaManager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UNMAM_Design_Parser implements UNMAM_Parser_Interface {

    public function get_name() {
        return 'design';
    }

    /**
     * @param WP_Post|object $post Batch scans pass a partial row: ID, post_type, post_content, post_title.
     * @return array
     */
    public function parse_post( $post ) {
        if ( 'custom_css' === $post->post_type ) {
            $label = __( 'Additional CSS', 'unattached-media-manager' );
        } elseif ( 'wp_global_styles' === $post->post_type ) {
            $label = __( 'Styles', 'unattached-media-manager' );
        } else {
            return array();
        }

        // Styles saved before core switched to JSON_UNESCAPED_SLASHES have \/ throughout.
        $text = str_replace( '\/', '/', (string) $post->post_content );

        $references = array();
        foreach ( UNMAM_Reference_Extractor::extract_from_text( $text, true ) as $attachment_id => $matched ) {
            $references[] = array(
                'attachment_id'   => (int) $attachment_id,
                'source_id'       => (int) $post->ID,
                'source_type'     => 'post',
                'context_type'    => 'post_content',
                'context_key'     => null,
                'context_label'   => $label,
                'reference_type'  => is_string( $matched ) ? 'url' : 'id',
                'reference_value' => is_string( $matched ) ? $matched : null,
            );
        }

        return $references;
    }
}
