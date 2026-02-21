<?php
/**
 * Widget Parser for Media Usage Inspector
 *
 * Parses widget data for media references
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Widget Parser class
 */
class UNMAM_Widget_Parser {

    /**
     * Get parser name
     *
     * @return string
     */
    public function get_name() {
        return 'widgets';
    }

    /**
     * Parse all widgets for media references
     *
     * @return array
     */
    public function parse_widgets() {
        $references = array();

        // Get all widget instances
        $widget_types = $this->get_widget_types();

        foreach ( $widget_types as $widget_type ) {
            $widget_data = get_option( "widget_{$widget_type}" );

            if ( ! is_array( $widget_data ) ) {
                continue;
            }

            foreach ( $widget_data as $instance_id => $instance ) {
                // Skip non-numeric keys (like _multiwidget)
                if ( ! is_numeric( $instance_id ) || ! is_array( $instance ) ) {
                    continue;
                }

                $refs = $this->parse_widget_instance( $widget_type, $instance_id, $instance );
                $references = array_merge( $references, $refs );
            }
        }

        // Parse block widgets (WordPress 5.8+)
        $block_refs = $this->parse_block_widgets();
        $references = array_merge( $references, $block_refs );

        return $references;
    }

    /**
     * Get list of widget types
     *
     * @return array
     */
    private function get_widget_types() {
        global $wpdb;

        // Get all widget options
        $options = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'widget_%'"
        );

        $types = array();
        foreach ( $options as $option ) {
            $type = str_replace( 'widget_', '', $option );
            if ( ! empty( $type ) ) {
                $types[] = $type;
            }
        }

        return array_unique( $types );
    }

    /**
     * Parse a single widget instance
     *
     * @param string $widget_type Widget type.
     * @param int    $instance_id Instance ID.
     * @param array  $instance    Widget instance data.
     * @return array
     */
    private function parse_widget_instance( $widget_type, $instance_id, $instance ) {
        $references = array();

        // Known widget fields that contain media
        $media_fields = array(
            // WordPress core widgets
            'attachment_id',
            'image_id',
            'url',           // Media widget URL
            'ids',           // Gallery widget

            // Common third-party widget fields
            'image',
            'background_image',
            'logo',
            'icon',
        );

        foreach ( $instance as $key => $value ) {
            // Direct ID fields
            if ( in_array( $key, array( 'attachment_id', 'image_id' ), true ) && is_numeric( $value ) ) {
                $attachment_id = (int) $value;
                if ( $this->is_valid_attachment( $attachment_id ) ) {
                    $references[] = $this->create_widget_reference(
                        $attachment_id,
                        $widget_type,
                        $instance_id,
                        $key,
                        'id'
                    );
                }
                continue;
            }

            // Gallery IDs (comma-separated)
            if ( 'ids' === $key && is_string( $value ) && preg_match( '/^\d+(,\d+)*$/', $value ) ) {
                $ids = array_map( 'intval', explode( ',', $value ) );
                foreach ( $ids as $id ) {
                    if ( $this->is_valid_attachment( $id ) ) {
                        $references[] = $this->create_widget_reference(
                            $id,
                            $widget_type,
                            $instance_id,
                            'gallery',
                            'id'
                        );
                    }
                }
                continue;
            }

            // URL fields
            if ( in_array( $key, array( 'url', 'image', 'background_image', 'logo', 'icon' ), true ) ) {
                if ( is_string( $value ) && $this->looks_like_media_url( $value ) ) {
                    $attachment_id = UNMAM_Database::url_to_attachment_id( $value );
                    if ( $attachment_id ) {
                        $references[] = $this->create_widget_reference(
                            $attachment_id,
                            $widget_type,
                            $instance_id,
                            $key,
                            'url',
                            $value
                        );
                    }
                }
                continue;
            }

            // Content field (might contain images)
            if ( in_array( $key, array( 'content', 'text' ), true ) && is_string( $value ) ) {
                $content_refs = $this->parse_widget_content( $value, $widget_type, $instance_id );
                $references   = array_merge( $references, $content_refs );
                continue;
            }

            // Nested arrays
            if ( is_array( $value ) ) {
                $nested_refs = $this->parse_nested_widget_data( $value, $widget_type, $instance_id, $key );
                $references  = array_merge( $references, $nested_refs );
            }
        }

        return $references;
    }

    /**
     * Parse widget content for media
     *
     * @param string $content     Widget content.
     * @param string $widget_type Widget type.
     * @param int    $instance_id Instance ID.
     * @return array
     */
    private function parse_widget_content( $content, $widget_type, $instance_id ) {
        $references = array();

        // Find images in content
        if ( preg_match_all( '/<img[^>]+>/i', $content, $matches ) ) {
            foreach ( $matches[0] as $img ) {
                $attachment_id = $this->get_attachment_from_img( $img );
                if ( $attachment_id ) {
                    $references[] = $this->create_widget_reference(
                        $attachment_id,
                        $widget_type,
                        $instance_id,
                        'content',
                        'id'
                    );
                }
            }
        }

        // Find gallery shortcodes
        if ( preg_match_all( '/\[gallery[^\]]*ids=["\']([^"\']+)["\'][^\]]*\]/i', $content, $matches ) ) {
            foreach ( $matches[1] as $ids_string ) {
                $ids = array_map( 'intval', explode( ',', $ids_string ) );
                foreach ( $ids as $id ) {
                    if ( $this->is_valid_attachment( $id ) ) {
                        $references[] = $this->create_widget_reference(
                            $id,
                            $widget_type,
                            $instance_id,
                            'gallery_shortcode',
                            'id'
                        );
                    }
                }
            }
        }

        return $references;
    }

    /**
     * Parse nested widget data
     *
     * @param array  $data        Data array.
     * @param string $widget_type Widget type.
     * @param int    $instance_id Instance ID.
     * @param string $parent_key  Parent key.
     * @return array
     */
    private function parse_nested_widget_data( $data, $widget_type, $instance_id, $parent_key ) {
        $references = array();

        foreach ( $data as $key => $value ) {
            $full_key = "{$parent_key}.{$key}";

            // ID fields
            if ( in_array( $key, array( 'id', 'ID', 'attachment_id', 'image_id' ), true ) && is_numeric( $value ) ) {
                if ( $this->is_valid_attachment( (int) $value ) ) {
                    $references[] = $this->create_widget_reference(
                        (int) $value,
                        $widget_type,
                        $instance_id,
                        $full_key,
                        'id'
                    );
                }
                continue;
            }

            // URL fields
            if ( in_array( $key, array( 'url', 'src', 'image' ), true ) && is_string( $value ) && $this->looks_like_media_url( $value ) ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $value );
                if ( $attachment_id ) {
                    $references[] = $this->create_widget_reference(
                        $attachment_id,
                        $widget_type,
                        $instance_id,
                        $full_key,
                        'url',
                        $value
                    );
                }
                continue;
            }

            // Recurse
            if ( is_array( $value ) ) {
                $nested = $this->parse_nested_widget_data( $value, $widget_type, $instance_id, $full_key );
                $references = array_merge( $references, $nested );
            }
        }

        return $references;
    }

    /**
     * Parse block-based widgets (WordPress 5.8+)
     *
     * @return array
     */
    private function parse_block_widgets() {
        $references = array();

        // Get block widget data
        $block_widgets = get_option( 'widget_block' );
        if ( ! is_array( $block_widgets ) ) {
            return $references;
        }

        foreach ( $block_widgets as $instance_id => $instance ) {
            if ( ! is_numeric( $instance_id ) || ! is_array( $instance ) ) {
                continue;
            }

            if ( empty( $instance['content'] ) ) {
                continue;
            }

            $content = $instance['content'];

            // Parse blocks
            if ( has_blocks( $content ) ) {
                $blocks = parse_blocks( $content );
                $refs   = $this->parse_widget_blocks( $blocks, $instance_id );
                $references = array_merge( $references, $refs );
            }
        }

        return $references;
    }

    /**
     * Parse blocks within widget
     *
     * @param array $blocks      Blocks array.
     * @param int   $instance_id Widget instance ID.
     * @return array
     */
    private function parse_widget_blocks( $blocks, $instance_id ) {
        $references = array();

        foreach ( $blocks as $block ) {
            if ( empty( $block['blockName'] ) ) {
                continue;
            }

            $attrs = isset( $block['attrs'] ) ? $block['attrs'] : array();

            // Check for ID attribute
            if ( ! empty( $attrs['id'] ) && is_numeric( $attrs['id'] ) ) {
                if ( $this->is_valid_attachment( (int) $attrs['id'] ) ) {
                    $references[] = $this->create_widget_reference(
                        (int) $attrs['id'],
                        'block',
                        $instance_id,
                        $block['blockName'],
                        'id'
                    );
                }
            }

            // Check for URL attribute
            if ( ! empty( $attrs['url'] ) && $this->looks_like_media_url( $attrs['url'] ) ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $attrs['url'] );
                if ( $attachment_id ) {
                    $references[] = $this->create_widget_reference(
                        $attachment_id,
                        'block',
                        $instance_id,
                        $block['blockName'],
                        'url',
                        $attrs['url']
                    );
                }
            }

            // Check gallery images
            if ( ! empty( $attrs['images'] ) && is_array( $attrs['images'] ) ) {
                foreach ( $attrs['images'] as $image ) {
                    if ( ! empty( $image['id'] ) && $this->is_valid_attachment( (int) $image['id'] ) ) {
                        $references[] = $this->create_widget_reference(
                            (int) $image['id'],
                            'block',
                            $instance_id,
                            $block['blockName'] . ':gallery',
                            'id'
                        );
                    }
                }
            }

            // Parse inner blocks
            if ( ! empty( $block['innerBlocks'] ) ) {
                $inner_refs = $this->parse_widget_blocks( $block['innerBlocks'], $instance_id );
                $references = array_merge( $references, $inner_refs );
            }
        }

        return $references;
    }

    /**
     * Get attachment ID from img tag
     *
     * @param string $img_tag Image tag HTML.
     * @return int
     */
    private function get_attachment_from_img( $img_tag ) {
        // Try wp-image-XXX class
        if ( preg_match( '/wp-image-(\d+)/i', $img_tag, $matches ) ) {
            return (int) $matches[1];
        }

        // Try src URL
        if ( preg_match( '/src=["\']([^"\']+)["\']/i', $img_tag, $matches ) ) {
            return UNMAM_Database::url_to_attachment_id( $matches[1] );
        }

        return 0;
    }

    /**
     * Create widget reference array
     *
     * @param int    $attachment_id   Attachment ID.
     * @param string $widget_type     Widget type.
     * @param int    $instance_id     Instance ID.
     * @param string $field           Field name.
     * @param string $reference_type  Reference type.
     * @param string $reference_value Reference value.
     * @return array
     */
    private function create_widget_reference( $attachment_id, $widget_type, $instance_id, $field, $reference_type, $reference_value = null ) {
        return array(
            'attachment_id'   => $attachment_id,
            'source_id'       => 0,
            'source_type'     => 'widget',
            'context_type'    => 'widget',
            'context_key'     => "widget_{$widget_type}[{$instance_id}]",
            'context_label'   => sprintf(
                /* translators: 1: widget type, 2: field name */
                __( 'Widget %1$s: %2$s', 'unattached-media-manager' ),
                $this->format_widget_type( $widget_type ),
                $field
            ),
            'reference_type'  => $reference_type,
            'reference_value' => $reference_value,
        );
    }

    /**
     * Format widget type for display
     *
     * @param string $widget_type Widget type.
     * @return string
     */
    private function format_widget_type( $widget_type ) {
        $type = str_replace( array( '_', '-' ), ' ', $widget_type );
        return ucwords( $type );
    }

    /**
     * Check if URL looks like media
     *
     * @param string $value Value to check.
     * @return bool
     */
    private function looks_like_media_url( $value ) {
        if ( ! is_string( $value ) ) {
            return false;
        }

        $media_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp4', 'webm', 'mp3', 'pdf' );
        $pattern          = '/\.(' . implode( '|', $media_extensions ) . ')(\?.*)?$/i';

        return preg_match( $pattern, $value ) || strpos( $value, '/wp-content/uploads/' ) !== false;
    }

    /**
     * Check if ID is valid attachment
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    private function is_valid_attachment( $attachment_id ) {
        return $attachment_id > 0 && get_post_type( $attachment_id ) === 'attachment';
    }
}
