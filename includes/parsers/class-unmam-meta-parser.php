<?php
/**
 * Meta Parser for Media Usage Inspector
 *
 * Parses generic postmeta and term meta for media references
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Meta Parser class
 */
class UNMAM_Meta_Parser implements UNMAM_Parser_Interface, UNMAM_Term_Parser_Interface {

    /**
     * Meta values larger than this are skipped by the text-extraction fallback.
     *
     * @var int
     */
    const MAX_EXTRACTABLE_LENGTH = 1048576; // 1MB.

    /**
     * Known meta keys that store attachment IDs
     *
     * @var array
     */
    private $known_id_meta_keys = array(
        '_thumbnail_id',          // Featured image (handled separately but listed for reference)
        '_product_image_gallery', // WooCommerce
        '_yoast_wpseo_opengraph-image-id',
        '_yoast_wpseo_twitter-image-id',
        'rank_math_facebook_image_id',
        'rank_math_twitter_image_id',
        '_elementor_css',         // Skip - not media
    );

    /**
     * Meta key patterns to skip
     *
     * @var array
     */
    private $skip_patterns = array(
        '/^_edit_/',
        '/^_wp_/',
        '/^_oembed/',
        '/^_transient/',
        '/^_menu_item/',
        '/^_mui_/',
        '/^_elementor_/',
        '/^_mfrh_history$/',     // Media File Renamer history (filenames only, not references)
        '/^_original_filename$/', // Media File Renamer original filename
    );

    /**
     * Get parser name
     *
     * @return string
     */
    public function get_name() {
        return 'meta';
    }

    /**
     * Parse post meta for media references
     *
     * @param WP_Post $post Post object.
     * @return array
     */
    public function parse_post( $post ) {
        $meta = get_post_meta( $post->ID );

        if ( empty( $meta ) ) {
            return array();
        }

        return $this->parse_meta_array( $meta, $this->get_post_source( $post->ID ) );
    }

    /**
     * Parse term meta for media references
     *
     * @param WP_Term $term Term object.
     * @return array
     */
    public function parse_term( $term ) {
        $meta = get_term_meta( $term->term_id );

        if ( empty( $meta ) ) {
            return array();
        }

        return $this->parse_meta_array( $meta, $this->get_term_source( $term->term_id ) );
    }

    /**
     * Source descriptor for post meta rows
     *
     * @param int $post_id Post ID.
     * @return array id, source_type, context_type, label_format.
     */
    private function get_post_source( $post_id ) {
        return array(
            'id'           => $post_id,
            'source_type'  => 'post',
            'context_type' => 'postmeta',
            /* translators: %s: meta key */
            'label_format' => __( 'Post Meta: %s', 'unattached-media-manager' ),
        );
    }

    /**
     * Source descriptor for term meta rows
     *
     * @param int $term_id Term ID.
     * @return array id, source_type, context_type, label_format.
     */
    private function get_term_source( $term_id ) {
        return array(
            'id'           => $term_id,
            'source_type'  => 'term',
            'context_type' => 'term_meta',
            /* translators: %s: meta key */
            'label_format' => __( 'Term Meta: %s', 'unattached-media-manager' ),
        );
    }

    /**
     * Walk a get_post_meta()/get_term_meta() style array (key => array of values)
     *
     * @param array $meta   Meta array, keyed by meta key.
     * @param array $source Source descriptor, see get_post_source()/get_term_source().
     * @return array
     */
    private function parse_meta_array( $meta, $source ) {
        $references = array();

        foreach ( $meta as $meta_key => $meta_values ) {
            if ( $this->should_skip_key( $meta_key ) ) {
                continue;
            }

            foreach ( $meta_values as $meta_value ) {
                $refs       = $this->parse_meta_value( $meta_key, $meta_value, $source );
                $references = array_merge( $references, $refs );
            }
        }

        return $references;
    }

    /**
     * Check if meta key should be skipped
     *
     * @param string $meta_key Meta key.
     * @return bool
     */
    private function should_skip_key( $meta_key ) {
        foreach ( $this->skip_patterns as $pattern ) {
            if ( preg_match( $pattern, $meta_key ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse a single meta value
     *
     * @param string $meta_key   Meta key.
     * @param mixed  $meta_value Meta value.
     * @param array  $source     Source descriptor, see get_post_source()/get_term_source().
     * @return array
     */
    private function parse_meta_value( $meta_key, $meta_value, $source ) {
        $references = array();

        // Direct numeric value (likely attachment ID)
        if ( is_numeric( $meta_value ) && (int) $meta_value > 0 ) {
            $attachment_id = (int) $meta_value;
            if ( $this->is_valid_attachment( $attachment_id ) ) {
                $references[] = $this->create_reference( $attachment_id, $source, $meta_key, 'id' );
            }
            return $references;
        }

        // Comma-separated IDs (like WooCommerce gallery)
        if ( is_string( $meta_value ) && preg_match( '/^\d+(,\d+)*$/', $meta_value ) ) {
            $ids = array_map( 'intval', explode( ',', $meta_value ) );
            foreach ( $ids as $id ) {
                if ( $this->is_valid_attachment( $id ) ) {
                    $references[] = $this->create_reference( $id, $source, $meta_key, 'id' );
                }
            }
            return $references;
        }

        // URL. looks_like_media_url() also passes a blob of markup that merely contains an
        // uploads path, and url_to_attachment_id() cannot resolve one of those. Claim the
        // value only when a reference really came out of it, otherwise fall through so the
        // extractor below still gets a look at the markup.
        if ( is_string( $meta_value ) && $this->looks_like_media_url( $meta_value ) ) {
            $attachment_id = UNMAM_Database::url_to_attachment_id( $meta_value );
            if ( $attachment_id ) {
                $references[] = $this->create_reference( $attachment_id, $source, $meta_key, 'url', $meta_value );
                return $references;
            }
        }

        // Serialized data
        if ( is_string( $meta_value ) && is_serialized( $meta_value ) ) {
            $unserialized = @unserialize( $meta_value );
            if ( false !== $unserialized ) {
                $refs       = $this->parse_complex_value( $meta_key, $unserialized, $source );
                $references = array_merge( $references, $refs );
            }
            return $references;
        }

        // JSON data
        if ( is_string( $meta_value ) ) {
            $decoded = json_decode( $meta_value, true );
            if ( null !== $decoded && json_last_error() === JSON_ERROR_NONE ) {
                return array_merge( $references, $this->parse_complex_value( $meta_key, $decoded, $source ) );
            }
        }

        // Already an array
        if ( is_array( $meta_value ) ) {
            return array_merge( $references, $this->parse_complex_value( $meta_key, $meta_value, $source ) );
        }

        // Last resort: a string that matched none of the above. Catches WYSIWYG
        // markup and inline uploads paths that ACF isn't around to normalize into
        // a clean field value - the case term meta hits directly since it has no
        // dedicated content parser.
        if ( is_string( $meta_value )
            && strlen( $meta_value ) <= self::MAX_EXTRACTABLE_LENGTH
            && UNMAM_Reference_Extractor::looks_extractable( $meta_value )
        ) {
            foreach ( UNMAM_Reference_Extractor::extract_from_text( $meta_value ) as $attachment_id => $matched ) {
                $references[] = $this->create_reference(
                    $attachment_id,
                    $source,
                    $meta_key,
                    is_string( $matched ) ? 'url' : 'id',
                    is_string( $matched ) ? $matched : null
                );
            }
        }

        return $references;
    }

    /**
     * Parse complex (array) value for media
     *
     * @param string $meta_key Meta key.
     * @param array  $data     Data array.
     * @param array  $source   Source descriptor, see get_post_source()/get_term_source().
     * @return array
     */
    private function parse_complex_value( $meta_key, $data, $source ) {
        $references = array();

        if ( ! is_array( $data ) ) {
            return $references;
        }

        // Recursively search for attachment IDs and URLs
        array_walk_recursive( $data, function( $value, $key ) use ( &$references, $meta_key, $source ) {
            // Check for numeric IDs with media-related keys
            $id_keys = array( 'id', 'ID', 'image_id', 'attachment_id', 'media_id', 'imageId', 'mediaId' );
            if ( in_array( $key, $id_keys, true ) && is_numeric( $value ) ) {
                $attachment_id = (int) $value;
                if ( $this->is_valid_attachment( $attachment_id ) ) {
                    $references[] = $this->create_reference( $attachment_id, $source, $meta_key, 'id' );
                }
            }

            // Check for URLs
            $url_keys = array( 'url', 'src', 'image', 'image_url', 'imageUrl', 'background', 'backgroundImage' );
            if ( in_array( $key, $url_keys, true ) && is_string( $value ) && $this->looks_like_media_url( $value ) ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $value );
                if ( $attachment_id ) {
                    $references[] = $this->create_reference( $attachment_id, $source, $meta_key, 'url', $value );
                }
            }

            // Check string values that might be URLs
            if ( is_string( $value ) && $this->looks_like_media_url( $value ) ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $value );
                if ( $attachment_id ) {
                    // Check we haven't already added this
                    foreach ( $references as $ref ) {
                        if ( $ref['attachment_id'] === $attachment_id && $ref['reference_value'] === $value ) {
                            return;
                        }
                    }
                    $references[] = $this->create_reference( $attachment_id, $source, $meta_key, 'url', $value );
                }
            }
        } );

        return $references;
    }

    /**
     * Check if string looks like a media URL
     *
     * @param string $value Value to check.
     * @return bool
     */
    private function looks_like_media_url( $value ) {
        if ( ! is_string( $value ) ) {
            return false;
        }

        // Check for common image extensions
        $media_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'mp4', 'webm', 'ogg', 'mp3', 'wav', 'pdf', 'doc', 'docx' );
        $pattern          = '/\.(' . implode( '|', $media_extensions ) . ')(\?.*)?$/i';

        if ( preg_match( $pattern, $value ) ) {
            return true;
        }

        // Check if URL contains uploads directory
        if ( strpos( $value, '/wp-content/uploads/' ) !== false ) {
            return true;
        }

        return false;
    }

    /**
     * Check if ID is a valid attachment
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    private function is_valid_attachment( $attachment_id ) {
        if ( $attachment_id <= 0 ) {
            return false;
        }

        $post_type = get_post_type( $attachment_id );
        return 'attachment' === $post_type;
    }

    /**
     * Create reference array
     *
     * @param int    $attachment_id   Attachment ID.
     * @param array  $source          Source descriptor, see get_post_source()/get_term_source().
     * @param string $meta_key        Meta key.
     * @param string $reference_type  Reference type (id or url).
     * @param string $reference_value Optional reference value.
     * @return array
     */
    private function create_reference( $attachment_id, $source, $meta_key, $reference_type, $reference_value = null ) {
        return array(
            'attachment_id'   => $attachment_id,
            'source_id'       => $source['id'],
            'source_type'     => $source['source_type'],
            'context_type'    => $source['context_type'],
            'context_key'     => $meta_key,
            'context_label'   => sprintf( $source['label_format'], $meta_key ),
            'reference_type'  => $reference_type,
            'reference_value' => $reference_value,
        );
    }
}
