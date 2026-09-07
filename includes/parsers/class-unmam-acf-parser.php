<?php
/**
 * ACF Parser for Media Usage Inspector
 *
 * Parses Advanced Custom Fields for media references, on posts and on taxonomy terms.
 * Handles image, gallery, file, link, url, oembed, icon_picker, wysiwyg/textarea/text,
 * repeater, flexible content, and group fields.
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ACF Parser class
 */
class UNMAM_ACF_Parser implements UNMAM_Parser_Interface, UNMAM_Term_Parser_Interface {

    /**
     * ACF field types that contain media directly
     *
     * @var array
     */
    private $media_field_types = array(
        'image',
        'gallery',
        'file',
    );

    /**
     * ACF container field types (can contain media fields)
     *
     * @var array
     */
    private $container_field_types = array(
        'repeater',
        'flexible_content',
        'group',
    );

    /**
     * ACF field types that may hold media only as text or a URL
     *
     * @var array
     */
    private $text_field_types = array(
        'wysiwyg',
        'textarea',
        'text',
        'url',
        'link',
        'oembed',
        'icon_picker',
    );

    /**
     * Get parser name
     *
     * @return string
     */
    public function get_name() {
        return 'acf';
    }

    /**
     * Check if ACF is active
     *
     * @return bool
     */
    private function is_acf_active() {
        return class_exists( 'ACF' ) || function_exists( 'get_field' );
    }

    /**
     * Parse post for ACF media references
     *
     * @param WP_Post $post Post object.
     * @return array
     */
    public function parse_post( $post ) {
        if ( ! $this->is_acf_active() ) {
            // ACF not active, try to parse serialized meta anyway
            return $this->parse_serialized_acf_meta( $post->ID );
        }

        $references   = array();
        $field_groups = acf_get_field_groups( array( 'post_id' => $post->ID ) );
        $source       = array(
            'source_id'    => $post->ID,
            'source_type'  => 'post',
            'context_type' => 'acf',
        );

        foreach ( $field_groups as $field_group ) {
            $fields = acf_get_fields( $field_group );
            if ( $fields ) {
                $refs       = $this->parse_fields( $fields, $post->ID, $source );
                $references = array_merge( $references, $refs );
            }
        }

        return $references;
    }

    /**
     * Parse a taxonomy term for ACF media references
     *
     * @param WP_Term $term Term object.
     * @return array
     */
    public function parse_term( $term ) {
        if ( ! $this->is_acf_active() ) {
            // The generic term meta parser already reads raw term meta; nothing to add here.
            return array();
        }

        $references   = array();
        $field_groups = acf_get_field_groups( array( 'taxonomy' => $term->taxonomy ) );
        $source       = array(
            'source_id'    => $term->term_id,
            'source_type'  => 'term',
            'context_type' => 'term_acf',
        );

        foreach ( $field_groups as $field_group ) {
            $fields = acf_get_fields( $field_group );
            if ( $fields ) {
                $refs       = $this->parse_fields( $fields, 'term_' . $term->term_id, $source );
                $references = array_merge( $references, $refs );
            }
        }

        return $references;
    }

    /**
     * Parse ACF fields recursively
     *
     * @param array  $fields  Array of field objects.
     * @param mixed  $acf_id  ACF object id (post ID, or 'term_{id}').
     * @param array  $source  Source descriptor: source_id, source_type, context_type.
     * @param string $prefix  Field name prefix for nested fields.
     * @return array
     */
    private function parse_fields( $fields, $acf_id, $source, $prefix = '' ) {
        $references = array();

        foreach ( $fields as $field ) {
            $field_name = $prefix ? $prefix . '_' . $field['name'] : $field['name'];
            $field_type = $field['type'];

            if ( in_array( $field_type, $this->media_field_types, true ) ) {
                $refs = $this->parse_media_field( $field, $field_name, $acf_id, $source );
            } elseif ( in_array( $field_type, $this->container_field_types, true ) ) {
                $refs = $this->parse_container_field( $field, $field_name, $acf_id, $source );
            } elseif ( in_array( $field_type, $this->text_field_types, true ) ) {
                $refs = $this->parse_text_field( $field, $field_name, $acf_id, $source );
            } else {
                $refs = array();
            }

            $references = array_merge( $references, $refs );
        }

        return $references;
    }

    /**
     * Parse a media field (image, gallery, file)
     *
     * @param array  $field      Field object.
     * @param string $field_name Full field name.
     * @param mixed  $acf_id     ACF object id.
     * @param array  $source     Source descriptor.
     * @return array
     */
    private function parse_media_field( $field, $field_name, $acf_id, $source ) {
        $references = array();
        $value      = get_field( $field_name, $acf_id, false ); // false = return raw value

        if ( empty( $value ) ) {
            return $references;
        }

        switch ( $field['type'] ) {
            case 'image':
            case 'file':
                $attachment_id = $this->extract_attachment_id( $value );
                if ( $attachment_id ) {
                    $references[] = $this->create_reference(
                        $attachment_id,
                        $field_name,
                        $source,
                        sprintf(
                            /* translators: 1: field type, 2: field label */
                            __( 'ACF %1$s: %2$s', 'unattached-media-manager' ),
                            ucfirst( $field['type'] ),
                            $field['label']
                        ),
                        is_numeric( $value ) ? 'id' : 'url',
                        is_numeric( $value ) ? null : $value
                    );
                }
                break;

            case 'gallery':
                $ids = $this->extract_gallery_ids( $value );
                foreach ( $ids as $attachment_id ) {
                    $references[] = $this->create_reference(
                        $attachment_id,
                        $field_name,
                        $source,
                        sprintf(
                            /* translators: %s: field label */
                            __( 'ACF Gallery: %s', 'unattached-media-manager' ),
                            $field['label']
                        ),
                        'id'
                    );
                }
                break;
        }

        return $references;
    }

    /**
     * Parse a text-ish field that may only hold media as a URL or embedded markup
     *
     * @param array  $field      Field object.
     * @param string $field_name Full field name.
     * @param mixed  $acf_id     ACF object id.
     * @param array  $source     Source descriptor.
     * @return array
     */
    private function parse_text_field( $field, $field_name, $acf_id, $source ) {
        $references = array();
        $value      = get_field( $field_name, $acf_id, false );

        if ( empty( $value ) ) {
            return $references;
        }

        switch ( $field['type'] ) {
            case 'link':
                if ( is_array( $value ) && ! empty( $value['url'] ) ) {
                    $references = $this->resolve_url_field( $value['url'], $field, $field_name, $source );
                }
                break;

            case 'icon_picker':
                $references = $this->parse_icon_picker_field( $value, $field, $field_name, $source );
                break;

            case 'url':
            case 'oembed':
                if ( is_string( $value ) ) {
                    $references = $this->resolve_url_field( $value, $field, $field_name, $source );
                }
                break;

            case 'wysiwyg':
            case 'textarea':
            case 'text':
                if ( is_string( $value ) && UNMAM_Reference_Extractor::looks_extractable( $value ) ) {
                    $references = $this->build_text_extraction_references( $value, $field, $field_name, $source );
                }
                break;
        }

        return $references;
    }

    /**
     * Resolve a plain URL value (link, url, oembed) to an attachment reference
     *
     * @param string $url        The URL value.
     * @param array  $field      Field object.
     * @param string $field_name Full field name.
     * @param array  $source     Source descriptor.
     * @return array
     */
    private function resolve_url_field( $url, $field, $field_name, $source ) {
        $label = sprintf(
            /* translators: 1: field type, 2: field label */
            __( 'ACF %1$s: %2$s', 'unattached-media-manager' ),
            ucfirst( $field['type'] ),
            $field['label']
        );

        return $this->url_to_reference( $url, $field_name, $source, $label );
    }

    /**
     * Resolve a URL to an attachment reference, or drop it if it points off-site
     *
     * @param string $url        The URL value.
     * @param string $field_name Full field name.
     * @param array  $source     Source descriptor.
     * @param string $label      Context label.
     * @return array
     */
    private function url_to_reference( $url, $field_name, $source, $label ) {
        // These fields hold whatever URL an editor typed, which is often a link to another
        // site. Let the exact matches run either way, but deny the filename-only fallback for
        // a genuinely external URL, or a link to someone else's banner.jpg credits ours.
        $attachment_id = UNMAM_Database::url_to_attachment_id(
            $url,
            UNMAM_Database::url_points_at_this_site( $url )
        );

        // An external URL resolves to 0 and is simply dropped, not recorded.
        if ( ! $attachment_id ) {
            return array();
        }

        return array( $this->create_reference( $attachment_id, $field_name, $source, $label, 'url', $url ) );
    }

    /**
     * Parse an icon_picker field (ACF 6.3+)
     *
     * type media_library -> value is an attachment ID (validated).
     * type url           -> value is a URL.
     * type dashicons     -> not a media reference, skipped.
     *
     * @param array  $value      Raw field value.
     * @param array  $field      Field object.
     * @param string $field_name Full field name.
     * @param array  $source     Source descriptor.
     * @return array
     */
    private function parse_icon_picker_field( $value, $field, $field_name, $source ) {
        if ( ! is_array( $value ) || empty( $value['type'] ) || ! isset( $value['value'] ) ) {
            return array();
        }

        $label = sprintf(
            /* translators: %s: field label */
            __( 'ACF Icon Picker: %s', 'unattached-media-manager' ),
            $field['label']
        );

        if ( 'media_library' === $value['type'] ) {
            $attachment_id = (int) $value['value'];
            if ( ! UNMAM_Database::is_attachment_id( $attachment_id ) ) {
                return array();
            }

            return array( $this->create_reference( $attachment_id, $field_name, $source, $label, 'id' ) );
        }

        if ( 'url' === $value['type'] && is_string( $value['value'] ) ) {
            return $this->url_to_reference( $value['value'], $field_name, $source, $label );
        }

        return array();
    }

    /**
     * Build references from a text-extraction pass over a wysiwyg/textarea/text value
     *
     * @param string $text       Field value.
     * @param array  $field      Field object.
     * @param string $field_name Full field name.
     * @param array  $source     Source descriptor.
     * @return array
     */
    private function build_text_extraction_references( $text, $field, $field_name, $source ) {
        $matches = UNMAM_Reference_Extractor::extract_from_text( $text );
        if ( empty( $matches ) ) {
            return array();
        }

        $label = sprintf(
            /* translators: 1: field type, 2: field label */
            __( 'ACF %1$s: %2$s', 'unattached-media-manager' ),
            ucfirst( $field['type'] ),
            $field['label']
        );

        $references = array();
        foreach ( $matches as $attachment_id => $matched ) {
            $references[] = $this->create_reference(
                $attachment_id,
                $field_name,
                $source,
                $label,
                is_int( $matched ) ? 'id' : 'url',
                is_int( $matched ) ? null : $matched
            );
        }

        return $references;
    }

    /**
     * Build one reference row
     *
     * @param int    $attachment_id   Attachment ID.
     * @param string $field_name      Full field name, used as context_key.
     * @param array  $source          Source descriptor: source_id, source_type, context_type.
     * @param string $label           Context label.
     * @param string $reference_type  'id' or 'url'.
     * @param string $reference_value Matched URL, when reference_type is 'url'.
     * @return array
     */
    private function create_reference( $attachment_id, $field_name, $source, $label, $reference_type, $reference_value = null ) {
        return array(
            'attachment_id'   => $attachment_id,
            'source_id'       => $source['source_id'],
            'source_type'     => $source['source_type'],
            'context_type'    => $source['context_type'],
            'context_key'     => $field_name,
            'context_label'   => $label,
            'reference_type'  => $reference_type,
            'reference_value' => $reference_value,
        );
    }

    /**
     * Parse container field (repeater, flexible content, group)
     *
     * @param array  $field      Field object.
     * @param string $field_name Full field name.
     * @param mixed  $acf_id     ACF object id.
     * @param array  $source     Source descriptor.
     * @return array
     */
    private function parse_container_field( $field, $field_name, $acf_id, $source ) {
        $references = array();

        switch ( $field['type'] ) {
            case 'repeater':
                $refs = $this->parse_repeater_field( $field, $field_name, $acf_id, $source );
                break;

            case 'flexible_content':
                $refs = $this->parse_flexible_content_field( $field, $field_name, $acf_id, $source );
                break;

            case 'group':
                $refs = $this->parse_group_field( $field, $field_name, $acf_id, $source );
                break;

            default:
                $refs = array();
                break;
        }

        $references = array_merge( $references, $refs );
        return $references;
    }

    /**
     * Parse repeater field
     *
     * @param array  $field      Field object.
     * @param string $field_name Full field name.
     * @param mixed  $acf_id     ACF object id.
     * @param array  $source     Source descriptor.
     * @return array
     */
    private function parse_repeater_field( $field, $field_name, $acf_id, $source ) {
        $references = array();
        $rows       = get_field( $field_name, $acf_id, false );

        if ( ! is_array( $rows ) ) {
            $row_count = $this->get_repeater_row_count( $acf_id, $field_name );
            if ( $row_count > 0 && isset( $field['sub_fields'] ) ) {
                for ( $i = 0; $i < $row_count; $i++ ) {
                    $row_prefix = $field_name . '_' . $i;
                    $refs       = $this->parse_fields( $field['sub_fields'], $acf_id, $source, $row_prefix );
                    $references = array_merge( $references, $refs );
                }
            }
            return $references;
        }

        if ( isset( $field['sub_fields'] ) ) {
            foreach ( $rows as $row_index => $row ) {
                $row_prefix = $field_name . '_' . $row_index;
                $refs       = $this->parse_fields( $field['sub_fields'], $acf_id, $source, $row_prefix );
                $references = array_merge( $references, $refs );
            }
        }

        return $references;
    }

    /**
     * Row count fallback for a repeater that get_field() couldn't return as an array
     *
     * acf_get_metadata() understands both a post ID and a 'term_N' id; get_post_meta()
     * only understands a post ID, so it is only tried when $acf_id is actually one.
     *
     * @param mixed  $acf_id     ACF object id.
     * @param string $field_name Full field name.
     * @return int
     */
    private function get_repeater_row_count( $acf_id, $field_name ) {
        if ( function_exists( 'acf_get_metadata' ) ) {
            return (int) acf_get_metadata( $acf_id, $field_name );
        }

        if ( is_int( $acf_id ) ) {
            return (int) get_post_meta( $acf_id, $field_name, true );
        }

        return 0;
    }

    /**
     * Parse flexible content field
     *
     * @param array  $field      Field object.
     * @param string $field_name Full field name.
     * @param mixed  $acf_id     ACF object id.
     * @param array  $source     Source descriptor.
     * @return array
     */
    private function parse_flexible_content_field( $field, $field_name, $acf_id, $source ) {
        $references = array();
        $layouts    = get_field( $field_name, $acf_id, false );

        if ( ! is_array( $layouts ) || ! isset( $field['layouts'] ) ) {
            return $references;
        }

        // Create layout lookup
        $layout_lookup = array();
        foreach ( $field['layouts'] as $layout ) {
            $layout_lookup[ $layout['name'] ] = $layout;
        }

        foreach ( $layouts as $layout_index => $layout_data ) {
            if ( ! isset( $layout_data['acf_fc_layout'] ) ) {
                continue;
            }

            $layout_name = $layout_data['acf_fc_layout'];
            if ( ! isset( $layout_lookup[ $layout_name ] ) ) {
                continue;
            }

            $layout     = $layout_lookup[ $layout_name ];
            $row_prefix = $field_name . '_' . $layout_index;

            if ( isset( $layout['sub_fields'] ) ) {
                $refs       = $this->parse_fields( $layout['sub_fields'], $acf_id, $source, $row_prefix );
                $references = array_merge( $references, $refs );
            }
        }

        return $references;
    }

    /**
     * Parse group field
     *
     * @param array  $field      Field object.
     * @param string $field_name Full field name.
     * @param mixed  $acf_id     ACF object id.
     * @param array  $source     Source descriptor.
     * @return array
     */
    private function parse_group_field( $field, $field_name, $acf_id, $source ) {
        $references = array();

        if ( isset( $field['sub_fields'] ) ) {
            $refs       = $this->parse_fields( $field['sub_fields'], $acf_id, $source, $field_name );
            $references = array_merge( $references, $refs );
        }

        return $references;
    }

    /**
     * Extract attachment ID from various value formats
     *
     * @param mixed $value Field value.
     * @return int Attachment ID or 0.
     */
    private function extract_attachment_id( $value ) {
        // Direct ID
        if ( is_numeric( $value ) ) {
            return (int) $value;
        }

        // Array with ID
        if ( is_array( $value ) && isset( $value['ID'] ) ) {
            return (int) $value['ID'];
        }

        if ( is_array( $value ) && isset( $value['id'] ) ) {
            return (int) $value['id'];
        }

        // URL
        if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
            return UNMAM_Database::url_to_attachment_id( $value );
        }

        return 0;
    }

    /**
     * Extract attachment IDs from gallery value
     *
     * @param mixed $value Gallery field value.
     * @return array Array of attachment IDs.
     */
    private function extract_gallery_ids( $value ) {
        $ids = array();

        if ( ! is_array( $value ) ) {
            return $ids;
        }

        foreach ( $value as $item ) {
            $id = $this->extract_attachment_id( $item );
            if ( $id ) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Parse serialized ACF meta (fallback when ACF not active)
     *
     * @param int $post_id Post ID.
     * @return array
     */
    private function parse_serialized_acf_meta( $post_id ) {
        global $wpdb;

        $references = array();

        // Get all meta for this post
        $meta_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
                $post_id
            )
        );

        foreach ( $meta_rows as $meta ) {
            // Skip ACF field reference keys (start with _)
            if ( strpos( $meta->meta_key, '_' ) === 0 ) {
                // Check if this is an ACF field reference
                $field_key = $meta->meta_value;
                if ( strpos( $field_key, 'field_' ) === 0 ) {
                    continue;
                }
            }

            // Check if value contains attachment IDs or URLs
            $refs = $this->scan_meta_value_for_media( $meta->meta_key, $meta->meta_value, $post_id );
            $references = array_merge( $references, $refs );
        }

        return $references;
    }

    /**
     * Scan a meta value for media references
     *
     * @param string $meta_key   Meta key.
     * @param string $meta_value Meta value.
     * @param int    $post_id    Post ID.
     * @return array
     */
    private function scan_meta_value_for_media( $meta_key, $meta_value, $post_id ) {
        $references = array();

        // Try to unserialize
        $unserialized = @unserialize( $meta_value );
        if ( false !== $unserialized ) {
            $refs = $this->scan_array_for_media( $meta_key, $unserialized, $post_id );
            $references = array_merge( $references, $refs );
        }

        // Try JSON
        $decoded = json_decode( $meta_value, true );
        if ( null !== $decoded && json_last_error() === JSON_ERROR_NONE ) {
            $refs = $this->scan_array_for_media( $meta_key, $decoded, $post_id );
            $references = array_merge( $references, $refs );
        }

        return $references;
    }

    /**
     * Recursively scan array for media references
     *
     * @param string $meta_key Meta key.
     * @param mixed  $data     Data to scan.
     * @param int    $post_id  Post ID.
     * @return array
     */
    private function scan_array_for_media( $meta_key, $data, $post_id ) {
        $references = array();

        if ( ! is_array( $data ) ) {
            return $references;
        }

        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                // Check if this is an image array
                if ( isset( $value['ID'] ) || isset( $value['id'] ) ) {
                    $attachment_id = isset( $value['ID'] ) ? (int) $value['ID'] : (int) $value['id'];
                    if ( $attachment_id && get_post_type( $attachment_id ) === 'attachment' ) {
                        $references[] = array(
                            'attachment_id'   => $attachment_id,
                            'source_id'       => $post_id,
                            'source_type'     => 'post',
                            'context_type'    => 'acf',
                            'context_key'     => $meta_key,
                            'context_label'   => sprintf(
                                /* translators: %s: meta key */
                                __( 'ACF Field: %s', 'unattached-media-manager' ),
                                $meta_key
                            ),
                            'reference_type'  => 'id',
                        );
                    }
                } else {
                    // Recurse
                    $refs = $this->scan_array_for_media( $meta_key, $value, $post_id );
                    $references = array_merge( $references, $refs );
                }
            } elseif ( is_numeric( $value ) && (int) $value > 0 ) {
                // Check if this numeric value is an attachment ID
                if ( get_post_type( (int) $value ) === 'attachment' ) {
                    $references[] = array(
                        'attachment_id'   => (int) $value,
                        'source_id'       => $post_id,
                        'source_type'     => 'post',
                        'context_type'    => 'acf',
                        'context_key'     => $meta_key,
                        'context_label'   => sprintf(
                            /* translators: %s: meta key */
                            __( 'ACF Field: %s', 'unattached-media-manager' ),
                            $meta_key
                        ),
                        'reference_type'  => 'id',
                    );
                }
            }
        }

        return $references;
    }
}
