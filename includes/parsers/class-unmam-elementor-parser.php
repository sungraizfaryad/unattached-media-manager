<?php
/**
 * Elementor Parser for Media Usage Inspector
 *
 * Parses Elementor page builder data for media references.
 * This is a FREE feature - unlike other plugins that charge for page builder support.
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Elementor Parser class
 */
class UNMAM_Elementor_Parser implements UNMAM_Parser_Interface {

    /**
     * Image-related widget types
     *
     * @var array
     */
    private $image_widgets = array(
        'image',
        'image-box',
        'image-carousel',
        'image-gallery',
        'media-carousel',
        'slides',
        'testimonial',
        'testimonial-carousel',
        'reviews',
        'call-to-action',
        'flip-box',
        'price-table',
        'price-list',
        'hotspot',
        'image-comparison',
    );

    /**
     * Background-related settings
     *
     * @var array
     */
    private $background_keys = array(
        'background_image',
        'background_overlay_image',
        'background_video_fallback',
        '_background_image',
        'background_slideshow_gallery',
    );

    /**
     * Get parser name
     *
     * @return string
     */
    public function get_name() {
        return 'elementor';
    }

    /**
     * Check if Elementor is active
     *
     * @return bool
     */
    public function is_active() {
        return defined( 'ELEMENTOR_VERSION' ) || class_exists( 'Elementor\Plugin' );
    }

    /**
     * Parse post for Elementor media references
     *
     * @param WP_Post $post Post object.
     * @return array
     */
    public function parse_post( $post ) {
        $references = array();

        // Check if Elementor is active
        if ( ! $this->is_active() ) {
            return $references;
        }

        // Get Elementor data from post meta
        $elementor_data = get_post_meta( $post->ID, '_elementor_data', true );

        if ( empty( $elementor_data ) ) {
            return $references;
        }

        // Decode JSON data
        $data = is_string( $elementor_data ) ? json_decode( $elementor_data, true ) : $elementor_data;

        if ( empty( $data ) || ! is_array( $data ) ) {
            return $references;
        }

        // Recursively parse all elements
        $this->parse_elements( $data, $post->ID, $references );

        // Also check for Elementor-specific post meta
        $this->parse_elementor_meta( $post->ID, $references );

        return $references;
    }

    /**
     * Recursively parse Elementor elements
     *
     * @param array $elements   Elements array.
     * @param int   $post_id    Post ID.
     * @param array $references References array (passed by reference).
     * @param int   $depth      Current depth for debugging.
     */
    private function parse_elements( $elements, $post_id, &$references, $depth = 0 ) {
        foreach ( $elements as $element ) {
            // Parse element settings
            if ( ! empty( $element['settings'] ) ) {
                $this->parse_element_settings( $element, $post_id, $references );
            }

            // Recursively parse child elements
            if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
                $this->parse_elements( $element['elements'], $post_id, $references, $depth + 1 );
            }
        }
    }

    /**
     * Parse element settings for media references
     *
     * @param array $element    Element data.
     * @param int   $post_id    Post ID.
     * @param array $references References array (passed by reference).
     */
    private function parse_element_settings( $element, $post_id, &$references ) {
        $settings    = $element['settings'];
        $widget_type = isset( $element['widgetType'] ) ? $element['widgetType'] : '';
        $eltype      = isset( $element['elType'] ) ? $element['elType'] : '';

        // Parse image widgets
        if ( in_array( $widget_type, $this->image_widgets, true ) ) {
            $this->parse_image_widget( $settings, $post_id, $widget_type, $references );
        }

        // Parse background images (applies to sections, columns, and widgets)
        $this->parse_background_settings( $settings, $post_id, $eltype, $references );

        // Parse video widget
        if ( 'video' === $widget_type ) {
            $this->parse_video_widget( $settings, $post_id, $references );
        }

        // Parse icon box / icon list with custom images
        if ( in_array( $widget_type, array( 'icon-box', 'icon-list' ), true ) ) {
            $this->parse_icon_settings( $settings, $post_id, $widget_type, $references );
        }

        // Parse any remaining image-related settings generically
        $this->parse_generic_settings( $settings, $post_id, $widget_type, $references );
    }

    /**
     * Parse image widget settings
     *
     * @param array  $settings    Widget settings.
     * @param int    $post_id     Post ID.
     * @param string $widget_type Widget type.
     * @param array  $references  References array (passed by reference).
     */
    private function parse_image_widget( $settings, $post_id, $widget_type, &$references ) {
        // Single image
        if ( ! empty( $settings['image']['id'] ) ) {
            $references[] = $this->create_reference(
                (int) $settings['image']['id'],
                $post_id,
                'elementor_' . $widget_type,
                __( 'Elementor Image Widget', 'unattached-media-manager' )
            );
        }

        // Gallery/Carousel images
        $gallery_keys = array( 'gallery', 'carousel', 'slides', 'wp_gallery' );
        foreach ( $gallery_keys as $key ) {
            if ( ! empty( $settings[ $key ] ) && is_array( $settings[ $key ] ) ) {
                foreach ( $settings[ $key ] as $image ) {
                    if ( ! empty( $image['id'] ) ) {
                        $references[] = $this->create_reference(
                            (int) $image['id'],
                            $post_id,
                            'elementor_gallery',
                            __( 'Elementor Gallery', 'unattached-media-manager' )
                        );
                    }
                }
            }
        }

        // Image box specific
        if ( 'image-box' === $widget_type && ! empty( $settings['image']['id'] ) ) {
            // Already handled above
        }

        // Testimonial image
        if ( ! empty( $settings['testimonial_image']['id'] ) ) {
            $references[] = $this->create_reference(
                (int) $settings['testimonial_image']['id'],
                $post_id,
                'elementor_testimonial',
                __( 'Elementor Testimonial', 'unattached-media-manager' )
            );
        }

        // Person image (for testimonials/team members)
        if ( ! empty( $settings['person_image']['id'] ) ) {
            $references[] = $this->create_reference(
                (int) $settings['person_image']['id'],
                $post_id,
                'elementor_person',
                __( 'Elementor Person Image', 'unattached-media-manager' )
            );
        }
    }

    /**
     * Parse background settings
     *
     * @param array  $settings   Element settings.
     * @param int    $post_id    Post ID.
     * @param string $eltype     Element type.
     * @param array  $references References array (passed by reference).
     */
    private function parse_background_settings( $settings, $post_id, $eltype, &$references ) {
        foreach ( $this->background_keys as $key ) {
            if ( ! empty( $settings[ $key ]['id'] ) ) {
                $references[] = $this->create_reference(
                    (int) $settings[ $key ]['id'],
                    $post_id,
                    'elementor_background',
                    sprintf(
                        /* translators: %s: element type */
                        __( 'Elementor %s Background', 'unattached-media-manager' ),
                        ucfirst( $eltype )
                    )
                );
            }

            // Handle URL-only backgrounds
            if ( ! empty( $settings[ $key ]['url'] ) && empty( $settings[ $key ]['id'] ) ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $settings[ $key ]['url'] );
                if ( $attachment_id ) {
                    $references[] = $this->create_reference(
                        $attachment_id,
                        $post_id,
                        'elementor_background',
                        sprintf(
                            /* translators: %s: element type */
                            __( 'Elementor %s Background', 'unattached-media-manager' ),
                            ucfirst( $eltype )
                        ),
                        'url',
                        $settings[ $key ]['url']
                    );
                }
            }
        }

        // Handle slideshow gallery backgrounds
        if ( ! empty( $settings['background_slideshow_gallery'] ) && is_array( $settings['background_slideshow_gallery'] ) ) {
            foreach ( $settings['background_slideshow_gallery'] as $image ) {
                if ( ! empty( $image['id'] ) ) {
                    $references[] = $this->create_reference(
                        (int) $image['id'],
                        $post_id,
                        'elementor_slideshow',
                        __( 'Elementor Slideshow Background', 'unattached-media-manager' )
                    );
                }
            }
        }

        // Responsive background images
        $responsive_keys = array( '_tablet', '_mobile' );
        foreach ( $this->background_keys as $key ) {
            foreach ( $responsive_keys as $suffix ) {
                $responsive_key = $key . $suffix;
                if ( ! empty( $settings[ $responsive_key ]['id'] ) ) {
                    $references[] = $this->create_reference(
                        (int) $settings[ $responsive_key ]['id'],
                        $post_id,
                        'elementor_responsive_bg',
                        __( 'Elementor Responsive Background', 'unattached-media-manager' )
                    );
                }
            }
        }
    }

    /**
     * Parse video widget settings
     *
     * @param array $settings   Widget settings.
     * @param int   $post_id    Post ID.
     * @param array $references References array (passed by reference).
     */
    private function parse_video_widget( $settings, $post_id, &$references ) {
        // Self-hosted video
        if ( ! empty( $settings['hosted_url']['id'] ) ) {
            $references[] = $this->create_reference(
                (int) $settings['hosted_url']['id'],
                $post_id,
                'elementor_video',
                __( 'Elementor Video', 'unattached-media-manager' )
            );
        }

        // Video poster/thumbnail
        if ( ! empty( $settings['image_overlay']['id'] ) ) {
            $references[] = $this->create_reference(
                (int) $settings['image_overlay']['id'],
                $post_id,
                'elementor_video_poster',
                __( 'Elementor Video Poster', 'unattached-media-manager' )
            );
        }

        // External video poster
        if ( ! empty( $settings['video_poster']['id'] ) ) {
            $references[] = $this->create_reference(
                (int) $settings['video_poster']['id'],
                $post_id,
                'elementor_video_poster',
                __( 'Elementor Video Poster', 'unattached-media-manager' )
            );
        }
    }

    /**
     * Parse icon settings that may contain custom images
     *
     * @param array  $settings    Widget settings.
     * @param int    $post_id     Post ID.
     * @param string $widget_type Widget type.
     * @param array  $references  References array (passed by reference).
     */
    private function parse_icon_settings( $settings, $post_id, $widget_type, &$references ) {
        // Icon with custom uploaded SVG or image
        $icon_keys = array( 'selected_icon', 'icon', 'selected_active_icon' );

        foreach ( $icon_keys as $key ) {
            if ( ! empty( $settings[ $key ]['value']['id'] ) ) {
                $references[] = $this->create_reference(
                    (int) $settings[ $key ]['value']['id'],
                    $post_id,
                    'elementor_icon',
                    __( 'Elementor Custom Icon', 'unattached-media-manager' )
                );
            }
        }
    }

    /**
     * Parse generic settings for any remaining image fields
     *
     * @param array  $settings    Widget settings.
     * @param int    $post_id     Post ID.
     * @param string $widget_type Widget type.
     * @param array  $references  References array (passed by reference).
     */
    private function parse_generic_settings( $settings, $post_id, $widget_type, &$references ) {
        // Common image field patterns
        $image_patterns = array(
            'image',
            'logo',
            'avatar',
            'thumbnail',
            'photo',
            'picture',
            'graphic',
            'media',
            'icon_image',
            'before_image',
            'after_image',
        );

        foreach ( $settings as $key => $value ) {
            // Skip already processed keys
            if ( in_array( $key, $this->background_keys, true ) ) {
                continue;
            }

            // Check if this looks like an image field
            $is_image_field = false;
            foreach ( $image_patterns as $pattern ) {
                if ( stripos( $key, $pattern ) !== false ) {
                    $is_image_field = true;
                    break;
                }
            }

            if ( ! $is_image_field ) {
                continue;
            }

            // Handle array with id (standard Elementor image format)
            if ( is_array( $value ) && ! empty( $value['id'] ) ) {
                $references[] = $this->create_reference(
                    (int) $value['id'],
                    $post_id,
                    'elementor_' . $widget_type,
                    sprintf(
                        /* translators: %s: field name */
                        __( 'Elementor %s', 'unattached-media-manager' ),
                        ucwords( str_replace( '_', ' ', $key ) )
                    )
                );
            }

            // Handle array with url only
            if ( is_array( $value ) && ! empty( $value['url'] ) && empty( $value['id'] ) ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $value['url'] );
                if ( $attachment_id ) {
                    $references[] = $this->create_reference(
                        $attachment_id,
                        $post_id,
                        'elementor_' . $widget_type,
                        sprintf(
                            /* translators: %s: field name */
                            __( 'Elementor %s', 'unattached-media-manager' ),
                            ucwords( str_replace( '_', ' ', $key ) )
                        ),
                        'url',
                        $value['url']
                    );
                }
            }

            // Handle repeater fields (arrays of arrays)
            if ( is_array( $value ) && isset( $value[0] ) && is_array( $value[0] ) ) {
                foreach ( $value as $item ) {
                    if ( is_array( $item ) ) {
                        foreach ( $item as $sub_key => $sub_value ) {
                            if ( is_array( $sub_value ) && ! empty( $sub_value['id'] ) ) {
                                $references[] = $this->create_reference(
                                    (int) $sub_value['id'],
                                    $post_id,
                                    'elementor_repeater',
                                    __( 'Elementor Repeater Image', 'unattached-media-manager' )
                                );
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Parse Elementor-specific post meta
     *
     * @param int   $post_id    Post ID.
     * @param array $references References array (passed by reference).
     */
    private function parse_elementor_meta( $post_id, &$references ) {
        // Page settings thumbnail
        $page_settings = get_post_meta( $post_id, '_elementor_page_settings', true );

        if ( ! empty( $page_settings ) && is_array( $page_settings ) ) {
            // Custom CSS background images in page settings
            if ( ! empty( $page_settings['background_image']['id'] ) ) {
                $references[] = $this->create_reference(
                    (int) $page_settings['background_image']['id'],
                    $post_id,
                    'elementor_page_settings',
                    __( 'Elementor Page Background', 'unattached-media-manager' )
                );
            }
        }

        // Elementor template screenshot
        $template_screenshot = get_post_meta( $post_id, '_elementor_template_screenshot', true );
        if ( ! empty( $template_screenshot ) ) {
            $attachment_id = UNMAM_Database::url_to_attachment_id( $template_screenshot );
            if ( $attachment_id ) {
                $references[] = $this->create_reference(
                    $attachment_id,
                    $post_id,
                    'elementor_template',
                    __( 'Elementor Template Screenshot', 'unattached-media-manager' ),
                    'url',
                    $template_screenshot
                );
            }
        }
    }

    /**
     * Create a reference array
     *
     * @param int    $attachment_id Attachment ID.
     * @param int    $post_id       Post ID.
     * @param string $context_key   Context key.
     * @param string $context_label Context label.
     * @param string $ref_type      Reference type (id or url).
     * @param string $ref_value     Reference value.
     * @return array
     */
    private function create_reference( $attachment_id, $post_id, $context_key, $context_label, $ref_type = 'id', $ref_value = null ) {
        return array(
            'attachment_id'   => $attachment_id,
            'source_id'       => $post_id,
            'source_type'     => 'post',
            'context_type'    => 'elementor',
            'context_key'     => $context_key,
            'context_label'   => $context_label,
            'reference_type'  => $ref_type,
            'reference_value' => $ref_value,
        );
    }
}
