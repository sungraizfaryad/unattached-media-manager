<?php
/**
 * Block Parser for Media Usage Inspector
 *
 * Parses Gutenberg blocks for media references
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Block Parser class
 */
class UNMAM_Block_Parser implements UNMAM_Parser_Interface {

    /**
     * Block types that commonly contain media
     *
     * @var array
     */
    private $media_blocks = array(
        'core/image',
        'core/gallery',
        'core/cover',
        'core/media-text',
        'core/video',
        'core/audio',
        'core/file',
        'core/post-featured-image',
    );

    /**
     * Get parser name
     *
     * @return string
     */
    public function get_name() {
        return 'blocks';
    }

    /**
     * Parse post for block-based media references
     *
     * @param WP_Post $post Post object.
     * @return array
     */
    public function parse_post( $post ) {
        $references = array();
        $content    = $post->post_content;

        if ( empty( $content ) || ! has_blocks( $content ) ) {
            return $references;
        }

        $blocks = parse_blocks( $content );
        $references = $this->parse_blocks_recursive( $blocks, $post->ID );

        return $references;
    }

    /**
     * Recursively parse blocks
     *
     * @param array $blocks  Array of blocks.
     * @param int   $post_id Post ID.
     * @return array
     */
    private function parse_blocks_recursive( $blocks, $post_id ) {
        $references = array();

        foreach ( $blocks as $block ) {
            if ( empty( $block['blockName'] ) ) {
                continue;
            }

            // Parse this block
            $block_refs = $this->parse_single_block( $block, $post_id );
            $references = array_merge( $references, $block_refs );

            // Parse inner blocks
            if ( ! empty( $block['innerBlocks'] ) ) {
                $inner_refs = $this->parse_blocks_recursive( $block['innerBlocks'], $post_id );
                $references = array_merge( $references, $inner_refs );
            }
        }

        return $references;
    }

    /**
     * Parse a single block for media references
     *
     * @param array $block   Block data.
     * @param int   $post_id Post ID.
     * @return array
     */
    private function parse_single_block( $block, $post_id ) {
        $references = array();
        $block_name = $block['blockName'];
        $attrs      = isset( $block['attrs'] ) ? $block['attrs'] : array();

        switch ( $block_name ) {
            case 'core/image':
                $refs = $this->parse_image_block( $attrs, $post_id );
                break;

            case 'core/gallery':
                $refs = $this->parse_gallery_block( $attrs, $post_id );
                break;

            case 'core/cover':
                $refs = $this->parse_cover_block( $attrs, $post_id );
                break;

            case 'core/media-text':
                $refs = $this->parse_media_text_block( $attrs, $post_id );
                break;

            case 'core/video':
                $refs = $this->parse_video_block( $attrs, $post_id );
                break;

            case 'core/audio':
                $refs = $this->parse_audio_block( $attrs, $post_id );
                break;

            case 'core/file':
                $refs = $this->parse_file_block( $attrs, $post_id );
                break;

            default:
                // Check for custom/third-party blocks with media attributes
                $refs = $this->parse_generic_block( $block_name, $attrs, $post_id );
                break;
        }

        if ( ! empty( $refs ) ) {
            $references = array_merge( $references, $refs );
        }

        /**
         * Filter block references
         *
         * @param array  $references Found references.
         * @param string $block_name Block name.
         * @param array  $attrs      Block attributes.
         * @param int    $post_id    Post ID.
         */
        return apply_filters( 'unmam_block_references', $references, $block_name, $attrs, $post_id );
    }

    /**
     * Parse core/image block
     *
     * @param array $attrs   Block attributes.
     * @param int   $post_id Post ID.
     * @return array
     */
    private function parse_image_block( $attrs, $post_id ) {
        $references = array();

        if ( ! empty( $attrs['id'] ) ) {
            $references[] = array(
                'attachment_id'   => (int) $attrs['id'],
                'source_id'       => $post_id,
                'source_type'     => 'post',
                'context_type'    => 'block',
                'context_key'     => 'core/image',
                'context_label'   => __( 'Image Block', 'unattached-media-manager' ),
                'reference_type'  => 'id',
            );
        } elseif ( ! empty( $attrs['url'] ) ) {
            $attachment_id = UNMAM_Database::url_to_attachment_id( $attrs['url'] );
            if ( $attachment_id ) {
                $references[] = array(
                    'attachment_id'   => $attachment_id,
                    'source_id'       => $post_id,
                    'source_type'     => 'post',
                    'context_type'    => 'block',
                    'context_key'     => 'core/image',
                    'context_label'   => __( 'Image Block', 'unattached-media-manager' ),
                    'reference_type'  => 'url',
                    'reference_value' => $attrs['url'],
                );
            }
        }

        return $references;
    }

    /**
     * Parse core/gallery block
     *
     * @param array $attrs   Block attributes.
     * @param int   $post_id Post ID.
     * @return array
     */
    private function parse_gallery_block( $attrs, $post_id ) {
        $references = array();

        // New format: images array
        if ( ! empty( $attrs['images'] ) && is_array( $attrs['images'] ) ) {
            foreach ( $attrs['images'] as $image ) {
                if ( ! empty( $image['id'] ) ) {
                    $references[] = array(
                        'attachment_id'   => (int) $image['id'],
                        'source_id'       => $post_id,
                        'source_type'     => 'post',
                        'context_type'    => 'block',
                        'context_key'     => 'core/gallery',
                        'context_label'   => __( 'Gallery Block', 'unattached-media-manager' ),
                        'reference_type'  => 'id',
                    );
                }
            }
        }

        // Legacy format: ids array
        if ( ! empty( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
            foreach ( $attrs['ids'] as $id ) {
                $references[] = array(
                    'attachment_id'   => (int) $id,
                    'source_id'       => $post_id,
                    'source_type'     => 'post',
                    'context_type'    => 'block',
                    'context_key'     => 'core/gallery',
                    'context_label'   => __( 'Gallery Block', 'unattached-media-manager' ),
                    'reference_type'  => 'id',
                );
            }
        }

        return $references;
    }

    /**
     * Parse core/cover block
     *
     * @param array $attrs   Block attributes.
     * @param int   $post_id Post ID.
     * @return array
     */
    private function parse_cover_block( $attrs, $post_id ) {
        $references = array();

        if ( ! empty( $attrs['id'] ) ) {
            $references[] = array(
                'attachment_id'   => (int) $attrs['id'],
                'source_id'       => $post_id,
                'source_type'     => 'post',
                'context_type'    => 'block',
                'context_key'     => 'core/cover',
                'context_label'   => __( 'Cover Block', 'unattached-media-manager' ),
                'reference_type'  => 'id',
            );
        } elseif ( ! empty( $attrs['url'] ) ) {
            $attachment_id = UNMAM_Database::url_to_attachment_id( $attrs['url'] );
            if ( $attachment_id ) {
                $references[] = array(
                    'attachment_id'   => $attachment_id,
                    'source_id'       => $post_id,
                    'source_type'     => 'post',
                    'context_type'    => 'block',
                    'context_key'     => 'core/cover',
                    'context_label'   => __( 'Cover Block', 'unattached-media-manager' ),
                    'reference_type'  => 'url',
                    'reference_value' => $attrs['url'],
                );
            }
        }

        return $references;
    }

    /**
     * Parse core/media-text block
     *
     * @param array $attrs   Block attributes.
     * @param int   $post_id Post ID.
     * @return array
     */
    private function parse_media_text_block( $attrs, $post_id ) {
        $references = array();

        if ( ! empty( $attrs['mediaId'] ) ) {
            $references[] = array(
                'attachment_id'   => (int) $attrs['mediaId'],
                'source_id'       => $post_id,
                'source_type'     => 'post',
                'context_type'    => 'block',
                'context_key'     => 'core/media-text',
                'context_label'   => __( 'Media & Text Block', 'unattached-media-manager' ),
                'reference_type'  => 'id',
            );
        } elseif ( ! empty( $attrs['mediaUrl'] ) ) {
            $attachment_id = UNMAM_Database::url_to_attachment_id( $attrs['mediaUrl'] );
            if ( $attachment_id ) {
                $references[] = array(
                    'attachment_id'   => $attachment_id,
                    'source_id'       => $post_id,
                    'source_type'     => 'post',
                    'context_type'    => 'block',
                    'context_key'     => 'core/media-text',
                    'context_label'   => __( 'Media & Text Block', 'unattached-media-manager' ),
                    'reference_type'  => 'url',
                    'reference_value' => $attrs['mediaUrl'],
                );
            }
        }

        return $references;
    }

    /**
     * Parse core/video block
     *
     * @param array $attrs   Block attributes.
     * @param int   $post_id Post ID.
     * @return array
     */
    private function parse_video_block( $attrs, $post_id ) {
        $references = array();

        if ( ! empty( $attrs['id'] ) ) {
            $references[] = array(
                'attachment_id'   => (int) $attrs['id'],
                'source_id'       => $post_id,
                'source_type'     => 'post',
                'context_type'    => 'block',
                'context_key'     => 'core/video',
                'context_label'   => __( 'Video Block', 'unattached-media-manager' ),
                'reference_type'  => 'id',
            );
        }

        // Poster image
        if ( ! empty( $attrs['poster'] ) ) {
            $poster_id = UNMAM_Database::url_to_attachment_id( $attrs['poster'] );
            if ( $poster_id ) {
                $references[] = array(
                    'attachment_id'   => $poster_id,
                    'source_id'       => $post_id,
                    'source_type'     => 'post',
                    'context_type'    => 'block',
                    'context_key'     => 'core/video:poster',
                    'context_label'   => __( 'Video Poster Image', 'unattached-media-manager' ),
                    'reference_type'  => 'url',
                    'reference_value' => $attrs['poster'],
                );
            }
        }

        return $references;
    }

    /**
     * Parse core/audio block
     *
     * @param array $attrs   Block attributes.
     * @param int   $post_id Post ID.
     * @return array
     */
    private function parse_audio_block( $attrs, $post_id ) {
        $references = array();

        if ( ! empty( $attrs['id'] ) ) {
            $references[] = array(
                'attachment_id'   => (int) $attrs['id'],
                'source_id'       => $post_id,
                'source_type'     => 'post',
                'context_type'    => 'block',
                'context_key'     => 'core/audio',
                'context_label'   => __( 'Audio Block', 'unattached-media-manager' ),
                'reference_type'  => 'id',
            );
        }

        return $references;
    }

    /**
     * Parse core/file block
     *
     * @param array $attrs   Block attributes.
     * @param int   $post_id Post ID.
     * @return array
     */
    private function parse_file_block( $attrs, $post_id ) {
        $references = array();

        if ( ! empty( $attrs['id'] ) ) {
            $references[] = array(
                'attachment_id'   => (int) $attrs['id'],
                'source_id'       => $post_id,
                'source_type'     => 'post',
                'context_type'    => 'block',
                'context_key'     => 'core/file',
                'context_label'   => __( 'File Block', 'unattached-media-manager' ),
                'reference_type'  => 'id',
            );
        } elseif ( ! empty( $attrs['href'] ) ) {
            $attachment_id = UNMAM_Database::url_to_attachment_id( $attrs['href'] );
            if ( $attachment_id ) {
                $references[] = array(
                    'attachment_id'   => $attachment_id,
                    'source_id'       => $post_id,
                    'source_type'     => 'post',
                    'context_type'    => 'block',
                    'context_key'     => 'core/file',
                    'context_label'   => __( 'File Block', 'unattached-media-manager' ),
                    'reference_type'  => 'url',
                    'reference_value' => $attrs['href'],
                );
            }
        }

        return $references;
    }

    /**
     * Parse generic/custom blocks for media
     *
     * @param string $block_name Block name.
     * @param array  $attrs      Block attributes.
     * @param int    $post_id    Post ID.
     * @return array
     */
    private function parse_generic_block( $block_name, $attrs, $post_id ) {
        $references = array();

        // Common media attribute names
        $id_attrs  = array( 'id', 'mediaId', 'imageId', 'backgroundId', 'logoId', 'iconId', 'attachmentId' );
        $url_attrs = array( 'url', 'mediaUrl', 'imageUrl', 'backgroundUrl', 'src', 'href' );

        // Check ID attributes
        foreach ( $id_attrs as $attr ) {
            if ( ! empty( $attrs[ $attr ] ) && is_numeric( $attrs[ $attr ] ) ) {
                $references[] = array(
                    'attachment_id'   => (int) $attrs[ $attr ],
                    'source_id'       => $post_id,
                    'source_type'     => 'post',
                    'context_type'    => 'block',
                    'context_key'     => $block_name,
                    'context_label'   => sprintf(
                        /* translators: %s: block name */
                        __( '%s Block', 'unattached-media-manager' ),
                        $this->format_block_name( $block_name )
                    ),
                    'reference_type'  => 'id',
                );
            }
        }

        // Check URL attributes
        foreach ( $url_attrs as $attr ) {
            if ( ! empty( $attrs[ $attr ] ) && is_string( $attrs[ $attr ] ) ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $attrs[ $attr ] );
                if ( $attachment_id ) {
                    $references[] = array(
                        'attachment_id'   => $attachment_id,
                        'source_id'       => $post_id,
                        'source_type'     => 'post',
                        'context_type'    => 'block',
                        'context_key'     => $block_name,
                        'context_label'   => sprintf(
                            /* translators: %s: block name */
                            __( '%s Block', 'unattached-media-manager' ),
                            $this->format_block_name( $block_name )
                        ),
                        'reference_type'  => 'url',
                        'reference_value' => $attrs[ $attr ],
                    );
                }
            }
        }

        return $references;
    }

    /**
     * Format block name for display
     *
     * @param string $block_name Block name like core/image.
     * @return string Formatted name.
     */
    private function format_block_name( $block_name ) {
        // Remove namespace
        $name = preg_replace( '/^[^\/]+\//', '', $block_name );
        // Convert to title case
        $name = str_replace( array( '-', '_' ), ' ', $name );
        return ucwords( $name );
    }
}
