<?php
/**
 * ACF Parser for Media Usage Inspector
 *
 * Parses Advanced Custom Fields for media references
 * Handles image, gallery, file, repeaters, flexible content, and group fields
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
class UNMAM_ACF_Parser implements UNMAM_Parser_Interface {

    /**
     * ACF field types that contain media
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
        $references = array();

        if ( ! $this->is_acf_active() ) {
            // ACF not active, try to parse serialized meta anyway
            return $this->parse_serialized_acf_meta( $post->ID );
        }

        // Get all ACF field groups for this post
        $field_groups = acf_get_field_groups( array( 'post_id' => $post->ID ) );

        foreach ( $field_groups as $field_group ) {
            $fields = acf_get_fields( $field_group );
            if ( $fields ) {
                $refs = $this->parse_fields( $fields, $post->ID );
                $references = array_merge( $references, $refs );
            }
        }

        return $references;
    }

    /**
     * Parse ACF fields recursively
     *
     * @param array  $fields  Array of field objects.
     * @param int    $post_id Post ID.
     * @param string $prefix  Field name prefix for nested fields.
     * @return array
     */
    private function parse_fields( $fields, $post_id, $prefix = '' ) {
        $references = array();

        foreach ( $fields as $field ) {
            $field_name = $prefix ? $prefix . '_' . $field['name'] : $field['name'];
            $field_type = $field['type'];

            if ( in_array( $field_type, $this->media_field_types, true ) ) {
                // Direct media field
                $refs = $this->parse_media_field( $field, $field_name, $post_id );
                $references = array_merge( $references, $refs );
            } elseif ( in_array( $field_type, $this->container_field_types, true ) ) {
                // Container field - parse recursively
                $refs = $this->parse_container_field( $field, $field_name, $post_id );
                $references = array_merge( $references, $refs );
            }
        }

        return $references;
    }

    /**
     * Parse a media field (image, gallery, file)
     *
     * @param array  $field      Field object.
     * @param string $field_name Full field name.
     * @param int    $post_id    Post ID.
     * @return array
     */
    private function parse_media_field( $field, $field_name, $post_id ) {
        $references = array();
        $value      = get_field( $field_name, $post_id, false ); // false = return raw value

        if ( empty( $value ) ) {
            return $references;
        }

        switch ( $field['type'] ) {
            case 'image':
            case 'file':
                $attachment_id = $this->extract_attachment_id( $value );
                if ( $attachment_id ) {
                    $references[] = array(
                        'attachment_id'   => $attachment_id,
                        'source_id'       => $post_id,
                        'source_type'     => 'post',
                        'context_type'    => 'acf',
                        'context_key'     => $field_name,
                        'context_label'   => sprintf(
                            /* translators: 1: field type, 2: field label */
                            __( 'ACF %1$s: %2$s', 'unattached-media-manager' ),
                            ucfirst( $field['type'] ),
                            $field['label']
                        ),
                        'reference_type'  => is_numeric( $value ) ? 'id' : 'url',
                        'reference_value' => is_numeric( $value ) ? null : $value,
                    );
                }
                break;

            case 'gallery':
                $ids = $this->extract_gallery_ids( $value );
                foreach ( $ids as $attachment_id ) {
                    $references[] = array(
                        'attachment_id'   => $attachment_id,
                        'source_id'       => $post_id,
                        'source_type'     => 'post',
                        'context_type'    => 'acf',
                        'context_key'     => $field_name,
                        'context_label'   => sprintf(
                            /* translators: %s: field label */
                            __( 'ACF Gallery: %s', 'unattached-media-manager' ),
                            $field['label']
                        ),
                        'reference_type'  => 'id',
                    );
                }
                break;
        }

        return $references;
    }

    /**
     * Parse container field (repeater, flexible content, group)
     *
     * @param array  $field      Field object.
     * @param string $field_name Full field name.
     * @param int    $post_id    Post ID.
     * @return array
     */
    private function parse_container_field( $field, $field_name, $post_id ) {
        $references = array();

        switch ( $field['type'] ) {
            case 'repeater':
                $refs = $this->parse_repeater_field( $field, $field_name, $post_id );
                break;

            case 'flexible_content':
                $refs = $this->parse_flexible_content_field( $field, $field_name, $post_id );
                break;

            case 'group':
                $refs = $this->parse_group_field( $field, $field_name, $post_id );
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
     * @param int    $post_id    Post ID.
     * @return array
     */
    private function parse_repeater_field( $field, $field_name, $post_id ) {
        $references = array();
        $rows       = get_field( $field_name, $post_id, false );

        if ( ! is_array( $rows ) ) {
            // Try to get row count
            $row_count = (int) get_post_meta( $post_id, $field_name, true );
            if ( $row_count > 0 && isset( $field['sub_fields'] ) ) {
                for ( $i = 0; $i < $row_count; $i++ ) {
                    $row_prefix = $field_name . '_' . $i;
                    $refs       = $this->parse_fields( $field['sub_fields'], $post_id, $row_prefix );
                    $references = array_merge( $references, $refs );
                }
            }
            return $references;
        }

        if ( isset( $field['sub_fields'] ) ) {
            foreach ( $rows as $row_index => $row ) {
                $row_prefix = $field_name . '_' . $row_index;
                $refs       = $this->parse_fields( $field['sub_fields'], $post_id, $row_prefix );
                $references = array_merge( $references, $refs );
            }
        }

        return $references;
    }

    /**
     * Parse flexible content field
     *
     * @param array  $field      Field object.
     * @param string $field_name Full field name.
     * @param int    $post_id    Post ID.
     * @return array
     */
    private function parse_flexible_content_field( $field, $field_name, $post_id ) {
        $references = array();
        $layouts    = get_field( $field_name, $post_id, false );

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
                $refs       = $this->parse_fields( $layout['sub_fields'], $post_id, $row_prefix );
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
     * @param int    $post_id    Post ID.
     * @return array
     */
    private function parse_group_field( $field, $field_name, $post_id ) {
        $references = array();

        if ( isset( $field['sub_fields'] ) ) {
            $refs       = $this->parse_fields( $field['sub_fields'], $post_id, $field_name );
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
