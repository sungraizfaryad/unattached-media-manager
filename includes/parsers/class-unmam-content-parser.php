<?php
/**
 * Content Parser for Media Usage Inspector
 *
 * Parses post_content for media references (classic editor, HTML images, etc.)
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Content Parser class
 */
class UNMAM_Content_Parser implements UNMAM_Parser_Interface {

    /**
     * Get parser name
     *
     * @return string
     */
    public function get_name() {
        return 'content';
    }

    /**
     * Parse post content for media references
     *
     * @param WP_Post $post Post object.
     * @return array
     */
    public function parse_post( $post ) {
        $references = array();
        $content    = $post->post_content;

        if ( empty( $content ) ) {
            return $references;
        }

        // Find image tags
        $image_refs = $this->find_images( $content, $post->ID );
        $references = array_merge( $references, $image_refs );

        // Find links to media files
        $link_refs = $this->find_media_links( $content, $post->ID );
        $references = array_merge( $references, $link_refs );

        // Find shortcode references
        $shortcode_refs = $this->find_shortcode_media( $content, $post->ID );
        $references = array_merge( $references, $shortcode_refs );

        // Find inline attachment references
        $inline_refs = $this->find_inline_attachments( $content, $post->ID );
        $references = array_merge( $references, $inline_refs );

        // Find video/audio sources using DOM parsing
        $media_refs = $this->find_media_elements( $content, $post->ID );
        $references = array_merge( $references, $media_refs );

        // Find background images in inline styles
        $bg_refs = $this->find_background_images( $content, $post->ID );
        $references = array_merge( $references, $bg_refs );

        // Find srcset images
        $srcset_refs = $this->find_srcset_images( $content, $post->ID );
        $references = array_merge( $references, $srcset_refs );

        // Find poster images on video elements
        $poster_refs = $this->find_video_posters( $content, $post->ID );
        $references = array_merge( $references, $poster_refs );

        return $references;
    }

    /**
     * Find image references in content
     *
     * @param string $content Content to parse.
     * @param int    $post_id Post ID.
     * @return array
     */
    private function find_images( $content, $post_id ) {
        $references = array();

        // Match <img> tags
        if ( preg_match_all( '/<img[^>]+>/i', $content, $matches ) ) {
            foreach ( $matches[0] as $img_tag ) {
                $attachment_id = $this->get_attachment_id_from_img( $img_tag );

                if ( $attachment_id ) {
                    $references[] = array(
                        'attachment_id'   => $attachment_id,
                        'source_id'       => $post_id,
                        'source_type'     => 'post',
                        'context_type'    => 'post_content',
                        'context_key'     => 'img_tag',
                        'context_label'   => __( 'Image in Content', 'unattached-media-manager' ),
                        'reference_type'  => 'id',
                        'reference_value' => $img_tag,
                    );
                }
            }
        }

        return $references;
    }

    /**
     * Get attachment ID from img tag
     *
     * @param string $img_tag Image tag HTML.
     * @return int Attachment ID or 0.
     */
    private function get_attachment_id_from_img( $img_tag ) {
        // Try wp-image-XXX class first (most reliable)
        if ( preg_match( '/wp-image-(\d+)/i', $img_tag, $matches ) ) {
            return (int) $matches[1];
        }

        // Try data-id attribute
        if ( preg_match( '/data-id=["\'](\d+)["\']/i', $img_tag, $matches ) ) {
            return (int) $matches[1];
        }

        // Try to resolve from src URL
        if ( preg_match( '/src=["\']([^"\']+)["\']/i', $img_tag, $matches ) ) {
            $url           = $matches[1];
            $attachment_id = UNMAM_Database::url_to_attachment_id( $url );
            if ( $attachment_id ) {
                return $attachment_id;
            }
        }

        return 0;
    }

    /**
     * Find links to media files
     *
     * @param string $content Content to parse.
     * @param int    $post_id Post ID.
     * @return array
     */
    private function find_media_links( $content, $post_id ) {
        $references = array();

        // Match <a> tags with href pointing to uploads
        $upload_dir = wp_upload_dir();
        $upload_url = $upload_dir['baseurl'];

        if ( preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
            foreach ( $matches[1] as $url ) {
                // Check if URL points to uploads directory
                if ( strpos( $url, $upload_url ) !== false || strpos( $url, '/wp-content/uploads/' ) !== false ) {
                    $attachment_id = UNMAM_Database::url_to_attachment_id( $url );

                    if ( $attachment_id ) {
                        $references[] = array(
                            'attachment_id'   => $attachment_id,
                            'source_id'       => $post_id,
                            'source_type'     => 'post',
                            'context_type'    => 'post_content',
                            'context_key'     => 'media_link',
                            'context_label'   => __( 'Media Link in Content', 'unattached-media-manager' ),
                            'reference_type'  => 'url',
                            'reference_value' => $url,
                        );
                    }
                }
            }
        }

        return $references;
    }

    /**
     * Find media references in shortcodes
     *
     * @param string $content Content to parse.
     * @param int    $post_id Post ID.
     * @return array
     */
    private function find_shortcode_media( $content, $post_id ) {
        $references = array();

        // WordPress gallery shortcode
        if ( preg_match_all( '/\[gallery[^\]]*ids=["\']([^"\']+)["\'][^\]]*\]/i', $content, $matches ) ) {
            foreach ( $matches[1] as $ids_string ) {
                $ids = array_map( 'intval', explode( ',', $ids_string ) );
                foreach ( $ids as $attachment_id ) {
                    if ( $attachment_id > 0 ) {
                        $references[] = array(
                            'attachment_id'   => $attachment_id,
                            'source_id'       => $post_id,
                            'source_type'     => 'post',
                            'context_type'    => 'shortcode',
                            'context_key'     => 'gallery',
                            'context_label'   => __( 'Gallery Shortcode', 'unattached-media-manager' ),
                            'reference_type'  => 'id',
                        );
                    }
                }
            }
        }

        // WordPress audio/video shortcodes with ids
        $media_shortcodes = array( 'audio', 'video', 'playlist' );
        foreach ( $media_shortcodes as $shortcode ) {
            $pattern = '/\[' . $shortcode . '[^\]]*ids=["\']([^"\']+)["\'][^\]]*\]/i';
            if ( preg_match_all( $pattern, $content, $matches ) ) {
                foreach ( $matches[1] as $ids_string ) {
                    $ids = array_map( 'intval', explode( ',', $ids_string ) );
                    foreach ( $ids as $attachment_id ) {
                        if ( $attachment_id > 0 ) {
                            $references[] = array(
                                'attachment_id'   => $attachment_id,
                                'source_id'       => $post_id,
                                'source_type'     => 'post',
                                'context_type'    => 'shortcode',
                                'context_key'     => $shortcode,
                                'context_label'   => sprintf(
                                    /* translators: %s: shortcode name */
                                    __( '%s Shortcode', 'unattached-media-manager' ),
                                    ucfirst( $shortcode )
                                ),
                                'reference_type'  => 'id',
                            );
                        }
                    }
                }
            }
        }

        // Caption shortcode
        if ( preg_match_all( '/\[caption[^\]]*id=["\']attachment_(\d+)["\'][^\]]*\]/i', $content, $matches ) ) {
            foreach ( $matches[1] as $attachment_id ) {
                $references[] = array(
                    'attachment_id'   => (int) $attachment_id,
                    'source_id'       => $post_id,
                    'source_type'     => 'post',
                    'context_type'    => 'shortcode',
                    'context_key'     => 'caption',
                    'context_label'   => __( 'Caption Shortcode', 'unattached-media-manager' ),
                    'reference_type'  => 'id',
                );
            }
        }

        /**
         * Filter shortcode references
         *
         * Allows adding custom shortcode parsing
         *
         * @param array  $references Found references.
         * @param string $content    Post content.
         * @param int    $post_id    Post ID.
         */
        return apply_filters( 'unmam_shortcode_references', $references, $content, $post_id );
    }

    /**
     * Find inline attachment references (like attachment page links)
     *
     * @param string $content Content to parse.
     * @param int    $post_id Post ID.
     * @return array
     */
    private function find_inline_attachments( $content, $post_id ) {
        $references = array();

        // Find ?attachment_id=XXX patterns
        if ( preg_match_all( '/[?&]attachment_id=(\d+)/i', $content, $matches ) ) {
            foreach ( $matches[1] as $attachment_id ) {
                $references[] = array(
                    'attachment_id'   => (int) $attachment_id,
                    'source_id'       => $post_id,
                    'source_type'     => 'post',
                    'context_type'    => 'post_content',
                    'context_key'     => 'attachment_link',
                    'context_label'   => __( 'Attachment Link', 'unattached-media-manager' ),
                    'reference_type'  => 'id',
                );
            }
        }

        return $references;
    }

    /**
     * Find video and audio elements with source tags
     *
     * @param string $content Content to parse.
     * @param int    $post_id Post ID.
     * @return array
     */
    private function find_media_elements( $content, $post_id ) {
        $references = array();

        // Check if DOMDocument is available
        if ( ! class_exists( 'DOMDocument' ) ) {
            return $references;
        }

        // Suppress errors for malformed HTML
        libxml_use_internal_errors( true );

        $dom = new DOMDocument();
        @$dom->loadHTML( '<?xml encoding="UTF-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

        libxml_clear_errors();

        // Find video elements
        $videos = $dom->getElementsByTagName( 'video' );
        foreach ( $videos as $video ) {
            // Check src attribute on video tag itself
            $src = $video->getAttribute( 'src' );
            if ( ! empty( $src ) ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $src );
                if ( $attachment_id ) {
                    $references[] = array(
                        'attachment_id'   => $attachment_id,
                        'source_id'       => $post_id,
                        'source_type'     => 'post',
                        'context_type'    => 'post_content',
                        'context_key'     => 'video',
                        'context_label'   => __( 'Video in Content', 'unattached-media-manager' ),
                        'reference_type'  => 'url',
                        'reference_value' => $src,
                    );
                }
            }
        }

        // Find audio elements
        $audios = $dom->getElementsByTagName( 'audio' );
        foreach ( $audios as $audio ) {
            $src = $audio->getAttribute( 'src' );
            if ( ! empty( $src ) ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $src );
                if ( $attachment_id ) {
                    $references[] = array(
                        'attachment_id'   => $attachment_id,
                        'source_id'       => $post_id,
                        'source_type'     => 'post',
                        'context_type'    => 'post_content',
                        'context_key'     => 'audio',
                        'context_label'   => __( 'Audio in Content', 'unattached-media-manager' ),
                        'reference_type'  => 'url',
                        'reference_value' => $src,
                    );
                }
            }
        }

        // Find source elements (inside video/audio)
        $sources = $dom->getElementsByTagName( 'source' );
        foreach ( $sources as $source ) {
            $src = $source->getAttribute( 'src' );
            if ( ! empty( $src ) ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $src );
                if ( $attachment_id ) {
                    $type = $source->getAttribute( 'type' );
                    $is_video = strpos( $type, 'video' ) !== false || $source->parentNode->nodeName === 'video';

                    $references[] = array(
                        'attachment_id'   => $attachment_id,
                        'source_id'       => $post_id,
                        'source_type'     => 'post',
                        'context_type'    => 'post_content',
                        'context_key'     => $is_video ? 'video_source' : 'audio_source',
                        'context_label'   => $is_video ? __( 'Video Source', 'unattached-media-manager' ) : __( 'Audio Source', 'unattached-media-manager' ),
                        'reference_type'  => 'url',
                        'reference_value' => $src,
                    );
                }
            }
        }

        return $references;
    }

    /**
     * Find background images in inline styles
     *
     * @param string $content Content to parse.
     * @param int    $post_id Post ID.
     * @return array
     */
    private function find_background_images( $content, $post_id ) {
        $references = array();

        // Match background-image: url(...) in style attributes
        if ( preg_match_all( '/style=["\'][^"\']*background(?:-image)?:\s*url\(["\']?([^"\')\s]+)["\']?\)[^"\']*["\']/i', $content, $matches ) ) {
            foreach ( $matches[1] as $url ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $url );
                if ( $attachment_id ) {
                    $references[] = array(
                        'attachment_id'   => $attachment_id,
                        'source_id'       => $post_id,
                        'source_type'     => 'post',
                        'context_type'    => 'post_content',
                        'context_key'     => 'background_image',
                        'context_label'   => __( 'Background Image in Content', 'unattached-media-manager' ),
                        'reference_type'  => 'url',
                        'reference_value' => $url,
                    );
                }
            }
        }

        // Also check for data-background and similar attributes
        $bg_attributes = array( 'data-background', 'data-bg', 'data-src', 'data-lazy-src' );
        foreach ( $bg_attributes as $attr ) {
            if ( preg_match_all( '/' . preg_quote( $attr, '/' ) . '=["\']([^"\']+)["\']/i', $content, $matches ) ) {
                foreach ( $matches[1] as $url ) {
                    $attachment_id = UNMAM_Database::url_to_attachment_id( $url );
                    if ( $attachment_id ) {
                        $references[] = array(
                            'attachment_id'   => $attachment_id,
                            'source_id'       => $post_id,
                            'source_type'     => 'post',
                            'context_type'    => 'post_content',
                            'context_key'     => $attr,
                            'context_label'   => __( 'Data Attribute Image', 'unattached-media-manager' ),
                            'reference_type'  => 'url',
                            'reference_value' => $url,
                        );
                    }
                }
            }
        }

        return $references;
    }

    /**
     * Find srcset images
     *
     * @param string $content Content to parse.
     * @param int    $post_id Post ID.
     * @return array
     */
    private function find_srcset_images( $content, $post_id ) {
        $references = array();
        $found_ids  = array(); // Track to avoid duplicates

        // Match srcset attributes
        if ( preg_match_all( '/srcset=["\']([^"\']+)["\']/i', $content, $matches ) ) {
            foreach ( $matches[1] as $srcset ) {
                // Parse srcset: each entry is "url width" or "url density"
                $entries = explode( ',', $srcset );
                foreach ( $entries as $entry ) {
                    $entry = trim( $entry );
                    $parts = preg_split( '/\s+/', $entry );
                    if ( ! empty( $parts[0] ) ) {
                        $url = $parts[0];
                        $attachment_id = UNMAM_Database::url_to_attachment_id( $url );

                        if ( $attachment_id && ! isset( $found_ids[ $attachment_id ] ) ) {
                            $found_ids[ $attachment_id ] = true;
                            $references[] = array(
                                'attachment_id'   => $attachment_id,
                                'source_id'       => $post_id,
                                'source_type'     => 'post',
                                'context_type'    => 'post_content',
                                'context_key'     => 'srcset',
                                'context_label'   => __( 'Responsive Image (srcset)', 'unattached-media-manager' ),
                                'reference_type'  => 'url',
                                'reference_value' => $url,
                            );
                        }
                    }
                }
            }
        }

        return $references;
    }

    /**
     * Find video poster images
     *
     * @param string $content Content to parse.
     * @param int    $post_id Post ID.
     * @return array
     */
    private function find_video_posters( $content, $post_id ) {
        $references = array();

        // Match poster attribute on video tags
        if ( preg_match_all( '/<video[^>]+poster=["\']([^"\']+)["\']/i', $content, $matches ) ) {
            foreach ( $matches[1] as $url ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $url );
                if ( $attachment_id ) {
                    $references[] = array(
                        'attachment_id'   => $attachment_id,
                        'source_id'       => $post_id,
                        'source_type'     => 'post',
                        'context_type'    => 'post_content',
                        'context_key'     => 'video_poster',
                        'context_label'   => __( 'Video Poster Image', 'unattached-media-manager' ),
                        'reference_type'  => 'url',
                        'reference_value' => $url,
                    );
                }
            }
        }

        return $references;
    }
}
