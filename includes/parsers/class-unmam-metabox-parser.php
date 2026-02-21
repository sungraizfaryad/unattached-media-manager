<?php
/**
 * MetaBox Parser for Media Usage Inspector
 *
 * Parses Meta Box plugin fields for media references.
 * This is a FREE feature - unlike other plugins that charge for this support.
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MetaBox Parser class
 */
class UNMAM_MetaBox_Parser implements UNMAM_Parser_Interface {

    /**
     * Meta Box image field types
     *
     * @var array
     */
    private $image_field_types = array(
        'image',
        'image_advanced',
        'image_upload',
        'single_image',
        'plupload_image',
        'thickbox_image',
        'file',
        'file_advanced',
        'file_upload',
        'file_input',
        'video',
        'media',
    );

    /**
     * Get parser name
     *
     * @return string
     */
    public function get_name() {
        return 'metabox';
    }

    /**
     * Check if Meta Box is active
     *
     * @return bool
     */
    public function is_active() {
        return class_exists( 'RWMB_Loader' ) || defined( 'RWMB_VER' );
    }

    /**
     * Parse post for Meta Box media references
     *
     * @param WP_Post $post Post object.
     * @return array
     */
    public function parse_post( $post ) {
        $references = array();

        // Check if Meta Box is active
        if ( ! $this->is_active() ) {
            return $references;
        }

        // Get all registered meta boxes for this post type
        $meta_boxes = $this->get_meta_boxes_for_post_type( $post->post_type );

        if ( empty( $meta_boxes ) ) {
            // Fall back to scanning all post meta
            return $this->scan_all_post_meta( $post->ID );
        }

        // Parse each meta box
        foreach ( $meta_boxes as $meta_box ) {
            if ( empty( $meta_box['fields'] ) ) {
                continue;
            }

            $this->parse_fields( $meta_box['fields'], $post->ID, $references );
        }

        return $references;
    }

    /**
     * Get registered meta boxes for a post type
     *
     * @param string $post_type Post type.
     * @return array
     */
    private function get_meta_boxes_for_post_type( $post_type ) {
        $meta_boxes = array();

        // Try to get from Meta Box registry
        if ( class_exists( 'RWMB_Core' ) && method_exists( 'RWMB_Core', 'get_meta_boxes' ) ) {
            $all_meta_boxes = RWMB_Core::get_meta_boxes();

            foreach ( $all_meta_boxes as $meta_box ) {
                if ( empty( $meta_box['post_types'] ) ) {
                    continue;
                }

                $box_post_types = (array) $meta_box['post_types'];
                if ( in_array( $post_type, $box_post_types, true ) ) {
                    $meta_boxes[] = $meta_box;
                }
            }
        }

        // Also check via the Meta Box plugin's own filter (third-party hook, not ours)
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Consuming Meta Box plugin's filter
        $filtered_boxes = apply_filters( 'rwmb_meta_boxes', array() );
        foreach ( $filtered_boxes as $meta_box ) {
            if ( empty( $meta_box['post_types'] ) ) {
                continue;
            }

            $box_post_types = (array) $meta_box['post_types'];
            if ( in_array( $post_type, $box_post_types, true ) ) {
                $meta_boxes[] = $meta_box;
            }
        }

        return $meta_boxes;
    }

    /**
     * Parse Meta Box fields
     *
     * @param array $fields     Fields configuration.
     * @param int   $post_id    Post ID.
     * @param array $references References array (passed by reference).
     * @param array $parent     Parent field info for nested fields.
     */
    private function parse_fields( $fields, $post_id, &$references, $parent = array() ) {
        foreach ( $fields as $field ) {
            $field_type = isset( $field['type'] ) ? $field['type'] : '';
            $field_id   = isset( $field['id'] ) ? $field['id'] : '';

            if ( empty( $field_id ) ) {
                continue;
            }

            // Handle image/file field types
            if ( in_array( $field_type, $this->image_field_types, true ) ) {
                $this->parse_media_field( $field, $post_id, $references );
            }

            // Handle group fields (nested)
            if ( 'group' === $field_type && ! empty( $field['fields'] ) ) {
                $this->parse_group_field( $field, $post_id, $references );
            }

            // Handle background field
            if ( 'background' === $field_type ) {
                $this->parse_background_field( $field, $post_id, $references );
            }

            // Handle URL fields that might contain media URLs
            if ( in_array( $field_type, array( 'url', 'text', 'textarea' ), true ) ) {
                $this->parse_url_field( $field, $post_id, $references );
            }

            // Handle WYSIWYG/editor fields
            if ( 'wysiwyg' === $field_type ) {
                $this->parse_wysiwyg_field( $field, $post_id, $references );
            }

            // Handle oEmbed fields
            if ( 'oembed' === $field_type ) {
                $this->parse_oembed_field( $field, $post_id, $references );
            }
        }
    }

    /**
     * Parse media field (image, file, video, etc.)
     *
     * @param array $field      Field configuration.
     * @param int   $post_id    Post ID.
     * @param array $references References array (passed by reference).
     */
    private function parse_media_field( $field, $post_id, &$references ) {
        $field_id = $field['id'];

        // Use rwmb_meta if available for proper value retrieval
        if ( function_exists( 'rwmb_meta' ) ) {
            $value = rwmb_meta( $field_id, array(), $post_id );
        } else {
            $value = get_post_meta( $post_id, $field_id, true );
        }

        if ( empty( $value ) ) {
            return;
        }

        // Handle different return formats
        $attachment_ids = $this->extract_attachment_ids( $value );

        foreach ( $attachment_ids as $attachment_id ) {
            $references[] = array(
                'attachment_id'   => $attachment_id,
                'source_id'       => $post_id,
                'source_type'     => 'post',
                'context_type'    => 'metabox',
                'context_key'     => $field_id,
                'context_label'   => sprintf(
                    /* translators: %s: field name */
                    __( 'Meta Box: %s', 'unattached-media-manager' ),
                    isset( $field['name'] ) ? $field['name'] : $field_id
                ),
                'reference_type'  => 'id',
            );
        }
    }

    /**
     * Parse group field (nested fields)
     *
     * @param array $field      Field configuration.
     * @param int   $post_id    Post ID.
     * @param array $references References array (passed by reference).
     */
    private function parse_group_field( $field, $post_id, &$references ) {
        $field_id = $field['id'];

        // Get group value
        if ( function_exists( 'rwmb_meta' ) ) {
            $groups = rwmb_meta( $field_id, array(), $post_id );
        } else {
            $groups = get_post_meta( $post_id, $field_id, true );
        }

        if ( empty( $groups ) || ! is_array( $groups ) ) {
            return;
        }

        // Handle cloneable groups (array of groups)
        if ( ! empty( $field['clone'] ) ) {
            foreach ( $groups as $group_data ) {
                $this->parse_group_data( $group_data, $field['fields'], $post_id, $field_id, $references );
            }
        } else {
            $this->parse_group_data( $groups, $field['fields'], $post_id, $field_id, $references );
        }
    }

    /**
     * Parse group data for media references
     *
     * @param array  $group_data Group data.
     * @param array  $fields     Fields configuration.
     * @param int    $post_id    Post ID.
     * @param string $parent_id  Parent field ID.
     * @param array  $references References array (passed by reference).
     */
    private function parse_group_data( $group_data, $fields, $post_id, $parent_id, &$references ) {
        if ( ! is_array( $group_data ) ) {
            return;
        }

        foreach ( $fields as $field ) {
            $field_id   = isset( $field['id'] ) ? $field['id'] : '';
            $field_type = isset( $field['type'] ) ? $field['type'] : '';

            if ( empty( $field_id ) || ! isset( $group_data[ $field_id ] ) ) {
                continue;
            }

            $value = $group_data[ $field_id ];

            if ( in_array( $field_type, $this->image_field_types, true ) ) {
                $attachment_ids = $this->extract_attachment_ids( $value );

                foreach ( $attachment_ids as $attachment_id ) {
                    $references[] = array(
                        'attachment_id'   => $attachment_id,
                        'source_id'       => $post_id,
                        'source_type'     => 'post',
                        'context_type'    => 'metabox',
                        'context_key'     => $parent_id . '.' . $field_id,
                        'context_label'   => sprintf(
                            /* translators: %s: field name */
                            __( 'Meta Box Group: %s', 'unattached-media-manager' ),
                            isset( $field['name'] ) ? $field['name'] : $field_id
                        ),
                        'reference_type'  => 'id',
                    );
                }
            }

            // Recursive for nested groups
            if ( 'group' === $field_type && ! empty( $field['fields'] ) && is_array( $value ) ) {
                if ( ! empty( $field['clone'] ) ) {
                    foreach ( $value as $sub_group ) {
                        $this->parse_group_data( $sub_group, $field['fields'], $post_id, $parent_id . '.' . $field_id, $references );
                    }
                } else {
                    $this->parse_group_data( $value, $field['fields'], $post_id, $parent_id . '.' . $field_id, $references );
                }
            }
        }
    }

    /**
     * Parse background field
     *
     * @param array $field      Field configuration.
     * @param int   $post_id    Post ID.
     * @param array $references References array (passed by reference).
     */
    private function parse_background_field( $field, $post_id, &$references ) {
        $field_id = $field['id'];

        if ( function_exists( 'rwmb_meta' ) ) {
            $value = rwmb_meta( $field_id, array(), $post_id );
        } else {
            $value = get_post_meta( $post_id, $field_id, true );
        }

        if ( empty( $value ) || ! is_array( $value ) ) {
            return;
        }

        // Background field stores image URL
        if ( ! empty( $value['image'] ) ) {
            $attachment_id = UNMAM_Database::url_to_attachment_id( $value['image'] );
            if ( $attachment_id ) {
                $references[] = array(
                    'attachment_id'   => $attachment_id,
                    'source_id'       => $post_id,
                    'source_type'     => 'post',
                    'context_type'    => 'metabox',
                    'context_key'     => $field_id,
                    'context_label'   => sprintf(
                        /* translators: %s: field name */
                        __( 'Meta Box Background: %s', 'unattached-media-manager' ),
                        isset( $field['name'] ) ? $field['name'] : $field_id
                    ),
                    'reference_type'  => 'url',
                    'reference_value' => $value['image'],
                );
            }
        }
    }

    /**
     * Parse URL field for media references
     *
     * @param array $field      Field configuration.
     * @param int   $post_id    Post ID.
     * @param array $references References array (passed by reference).
     */
    private function parse_url_field( $field, $post_id, &$references ) {
        $field_id = $field['id'];
        $value    = get_post_meta( $post_id, $field_id, true );

        if ( empty( $value ) || ! is_string( $value ) ) {
            return;
        }

        // Check if it looks like a media URL
        $upload_dir = wp_upload_dir();
        if ( strpos( $value, $upload_dir['baseurl'] ) === false && strpos( $value, '/wp-content/uploads/' ) === false ) {
            return;
        }

        $attachment_id = UNMAM_Database::url_to_attachment_id( $value );
        if ( $attachment_id ) {
            $references[] = array(
                'attachment_id'   => $attachment_id,
                'source_id'       => $post_id,
                'source_type'     => 'post',
                'context_type'    => 'metabox',
                'context_key'     => $field_id,
                'context_label'   => sprintf(
                    /* translators: %s: field name */
                    __( 'Meta Box URL: %s', 'unattached-media-manager' ),
                    isset( $field['name'] ) ? $field['name'] : $field_id
                ),
                'reference_type'  => 'url',
                'reference_value' => $value,
            );
        }
    }

    /**
     * Parse WYSIWYG field for media references
     *
     * @param array $field      Field configuration.
     * @param int   $post_id    Post ID.
     * @param array $references References array (passed by reference).
     */
    private function parse_wysiwyg_field( $field, $post_id, &$references ) {
        $field_id = $field['id'];
        $content  = get_post_meta( $post_id, $field_id, true );

        if ( empty( $content ) ) {
            return;
        }

        // Use content parser to find media in WYSIWYG content
        $content_parser = new UNMAM_Content_Parser();

        // Create a mock post object
        $mock_post               = new stdClass();
        $mock_post->ID           = $post_id;
        $mock_post->post_content = $content;

        $content_refs = $content_parser->parse_post( $mock_post );

        // Re-label references as coming from Meta Box
        foreach ( $content_refs as $ref ) {
            $ref['context_type']  = 'metabox';
            $ref['context_key']   = $field_id;
            $ref['context_label'] = sprintf(
                /* translators: %s: field name */
                __( 'Meta Box WYSIWYG: %s', 'unattached-media-manager' ),
                isset( $field['name'] ) ? $field['name'] : $field_id
            );
            $references[] = $ref;
        }
    }

    /**
     * Parse oEmbed field
     *
     * @param array $field      Field configuration.
     * @param int   $post_id    Post ID.
     * @param array $references References array (passed by reference).
     */
    private function parse_oembed_field( $field, $post_id, &$references ) {
        // oEmbed typically stores external URLs, but may have local media
        $this->parse_url_field( $field, $post_id, $references );
    }

    /**
     * Extract attachment IDs from various value formats
     *
     * @param mixed $value Field value.
     * @return array Array of attachment IDs.
     */
    private function extract_attachment_ids( $value ) {
        $ids = array();

        if ( empty( $value ) ) {
            return $ids;
        }

        // Single ID
        if ( is_numeric( $value ) ) {
            $ids[] = (int) $value;
            return $ids;
        }

        // Array of IDs
        if ( is_array( $value ) ) {
            foreach ( $value as $item ) {
                // Direct ID
                if ( is_numeric( $item ) ) {
                    $ids[] = (int) $item;
                    continue;
                }

                // Array with ID key (common Meta Box format)
                if ( is_array( $item ) && isset( $item['ID'] ) ) {
                    $ids[] = (int) $item['ID'];
                    continue;
                }

                // Array with id key (lowercase)
                if ( is_array( $item ) && isset( $item['id'] ) ) {
                    $ids[] = (int) $item['id'];
                    continue;
                }

                // URL in array
                if ( is_string( $item ) && filter_var( $item, FILTER_VALIDATE_URL ) ) {
                    $attachment_id = UNMAM_Database::url_to_attachment_id( $item );
                    if ( $attachment_id ) {
                        $ids[] = $attachment_id;
                    }
                }
            }
        }

        // Single URL string
        if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
            $attachment_id = UNMAM_Database::url_to_attachment_id( $value );
            if ( $attachment_id ) {
                $ids[] = $attachment_id;
            }
        }

        return array_unique( array_filter( $ids ) );
    }

    /**
     * Fallback: Scan all post meta for media references
     *
     * @param int $post_id Post ID.
     * @return array
     */
    private function scan_all_post_meta( $post_id ) {
        $references = array();
        $all_meta   = get_post_meta( $post_id );

        if ( empty( $all_meta ) ) {
            return $references;
        }

        foreach ( $all_meta as $meta_key => $meta_values ) {
            // Skip internal/WordPress keys
            if ( strpos( $meta_key, '_' ) === 0 && strpos( $meta_key, '_rwmb' ) !== 0 ) {
                continue;
            }

            foreach ( $meta_values as $meta_value ) {
                // Try to unserialize
                $unserialized = maybe_unserialize( $meta_value );
                $ids          = $this->extract_attachment_ids( $unserialized );

                foreach ( $ids as $attachment_id ) {
                    $references[] = array(
                        'attachment_id'   => $attachment_id,
                        'source_id'       => $post_id,
                        'source_type'     => 'post',
                        'context_type'    => 'metabox',
                        'context_key'     => $meta_key,
                        'context_label'   => sprintf(
                            /* translators: %s: meta key */
                            __( 'Meta Box Field: %s', 'unattached-media-manager' ),
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
