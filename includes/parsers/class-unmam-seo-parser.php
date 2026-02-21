<?php
/**
 * SEO Parser for Media Usage Inspector
 *
 * Parses SEO plugin data for media references (Yoast SEO, Rank Math, etc.)
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * SEO Parser class
 */
class UNMAM_SEO_Parser implements UNMAM_Parser_Interface {

    /**
     * Get parser name
     *
     * @return string
     */
    public function get_name() {
        return 'seo';
    }

    /**
     * Check if any supported SEO plugin is active
     *
     * @return bool
     */
    public function is_active() {
        return defined( 'WPSEO_VERSION' ) ||       // Yoast SEO
               defined( 'RANK_MATH_VERSION' ) ||   // Rank Math
               defined( 'AIOSEO_VERSION' ) ||      // All in One SEO
               class_exists( 'JEGS_JEGS_SEO' );    // SEOPress
    }

    /**
     * Parse post for SEO media references
     *
     * @param WP_Post $post Post object.
     * @return array
     */
    public function parse_post( $post ) {
        $references = array();

        // Yoast SEO
        if ( defined( 'WPSEO_VERSION' ) ) {
            $yoast_refs = $this->parse_yoast_post( $post );
            $references = array_merge( $references, $yoast_refs );
        }

        // Rank Math
        if ( defined( 'RANK_MATH_VERSION' ) ) {
            $rankmath_refs = $this->parse_rankmath_post( $post );
            $references = array_merge( $references, $rankmath_refs );
        }

        // All in One SEO
        if ( defined( 'AIOSEO_VERSION' ) ) {
            $aioseo_refs = $this->parse_aioseo_post( $post );
            $references = array_merge( $references, $aioseo_refs );
        }

        // SEOPress
        if ( class_exists( 'JEGS_JEGS_SEO' ) || defined( 'SEOPRESS_VERSION' ) ) {
            $seopress_refs = $this->parse_seopress_post( $post );
            $references = array_merge( $references, $seopress_refs );
        }

        return $references;
    }

    /**
     * Parse Yoast SEO post meta
     *
     * @param WP_Post $post Post object.
     * @return array
     */
    private function parse_yoast_post( $post ) {
        $references = array();

        // OpenGraph image
        $og_image_id = get_post_meta( $post->ID, '_yoast_wpseo_opengraph-image-id', true );
        if ( $og_image_id ) {
            $references[] = array(
                'attachment_id'   => (int) $og_image_id,
                'source_id'       => $post->ID,
                'source_type'     => 'post',
                'context_type'    => 'seo',
                'context_key'     => 'yoast_opengraph',
                'context_label'   => __( 'Yoast SEO OpenGraph Image', 'unattached-media-manager' ),
                'reference_type'  => 'id',
            );
        }

        // OpenGraph image URL (if ID not set)
        if ( ! $og_image_id ) {
            $og_image_url = get_post_meta( $post->ID, '_yoast_wpseo_opengraph-image', true );
            if ( $og_image_url ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $og_image_url );
                if ( $attachment_id ) {
                    $references[] = array(
                        'attachment_id'   => $attachment_id,
                        'source_id'       => $post->ID,
                        'source_type'     => 'post',
                        'context_type'    => 'seo',
                        'context_key'     => 'yoast_opengraph',
                        'context_label'   => __( 'Yoast SEO OpenGraph Image', 'unattached-media-manager' ),
                        'reference_type'  => 'url',
                        'reference_value' => $og_image_url,
                    );
                }
            }
        }

        // Twitter image
        $twitter_image_id = get_post_meta( $post->ID, '_yoast_wpseo_twitter-image-id', true );
        if ( $twitter_image_id ) {
            $references[] = array(
                'attachment_id'   => (int) $twitter_image_id,
                'source_id'       => $post->ID,
                'source_type'     => 'post',
                'context_type'    => 'seo',
                'context_key'     => 'yoast_twitter',
                'context_label'   => __( 'Yoast SEO Twitter Image', 'unattached-media-manager' ),
                'reference_type'  => 'id',
            );
        }

        // Twitter image URL (if ID not set)
        if ( ! $twitter_image_id ) {
            $twitter_image_url = get_post_meta( $post->ID, '_yoast_wpseo_twitter-image', true );
            if ( $twitter_image_url ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $twitter_image_url );
                if ( $attachment_id ) {
                    $references[] = array(
                        'attachment_id'   => $attachment_id,
                        'source_id'       => $post->ID,
                        'source_type'     => 'post',
                        'context_type'    => 'seo',
                        'context_key'     => 'yoast_twitter',
                        'context_label'   => __( 'Yoast SEO Twitter Image', 'unattached-media-manager' ),
                        'reference_type'  => 'url',
                        'reference_value' => $twitter_image_url,
                    );
                }
            }
        }

        return $references;
    }

    /**
     * Parse Rank Math post meta
     *
     * @param WP_Post $post Post object.
     * @return array
     */
    private function parse_rankmath_post( $post ) {
        $references = array();

        // Facebook image
        $fb_image_id = get_post_meta( $post->ID, 'rank_math_facebook_image_id', true );
        if ( $fb_image_id ) {
            $references[] = array(
                'attachment_id'   => (int) $fb_image_id,
                'source_id'       => $post->ID,
                'source_type'     => 'post',
                'context_type'    => 'seo',
                'context_key'     => 'rankmath_facebook',
                'context_label'   => __( 'Rank Math Facebook Image', 'unattached-media-manager' ),
                'reference_type'  => 'id',
            );
        }

        // Facebook image URL
        if ( ! $fb_image_id ) {
            $fb_image_url = get_post_meta( $post->ID, 'rank_math_facebook_image', true );
            if ( $fb_image_url ) {
                $attachment_id = UNMAM_Database::url_to_attachment_id( $fb_image_url );
                if ( $attachment_id ) {
                    $references[] = array(
                        'attachment_id'   => $attachment_id,
                        'source_id'       => $post->ID,
                        'source_type'     => 'post',
                        'context_type'    => 'seo',
                        'context_key'     => 'rankmath_facebook',
                        'context_label'   => __( 'Rank Math Facebook Image', 'unattached-media-manager' ),
                        'reference_type'  => 'url',
                        'reference_value' => $fb_image_url,
                    );
                }
            }
        }

        // Twitter image
        $twitter_image_id = get_post_meta( $post->ID, 'rank_math_twitter_image_id', true );
        if ( $twitter_image_id ) {
            $references[] = array(
                'attachment_id'   => (int) $twitter_image_id,
                'source_id'       => $post->ID,
                'source_type'     => 'post',
                'context_type'    => 'seo',
                'context_key'     => 'rankmath_twitter',
                'context_label'   => __( 'Rank Math Twitter Image', 'unattached-media-manager' ),
                'reference_type'  => 'id',
            );
        }

        return $references;
    }

    /**
     * Parse All in One SEO post meta
     *
     * @param WP_Post $post Post object.
     * @return array
     */
    private function parse_aioseo_post( $post ) {
        $references = array();

        // AIOSEO stores data in a custom table or in meta
        $og_image_url = get_post_meta( $post->ID, '_aioseo_og_image_custom_url', true );
        if ( $og_image_url ) {
            $attachment_id = UNMAM_Database::url_to_attachment_id( $og_image_url );
            if ( $attachment_id ) {
                $references[] = array(
                    'attachment_id'   => $attachment_id,
                    'source_id'       => $post->ID,
                    'source_type'     => 'post',
                    'context_type'    => 'seo',
                    'context_key'     => 'aioseo_opengraph',
                    'context_label'   => __( 'AIOSEO OpenGraph Image', 'unattached-media-manager' ),
                    'reference_type'  => 'url',
                    'reference_value' => $og_image_url,
                );
            }
        }

        // Twitter image
        $twitter_image_url = get_post_meta( $post->ID, '_aioseo_twitter_image_custom_url', true );
        if ( $twitter_image_url ) {
            $attachment_id = UNMAM_Database::url_to_attachment_id( $twitter_image_url );
            if ( $attachment_id ) {
                $references[] = array(
                    'attachment_id'   => $attachment_id,
                    'source_id'       => $post->ID,
                    'source_type'     => 'post',
                    'context_type'    => 'seo',
                    'context_key'     => 'aioseo_twitter',
                    'context_label'   => __( 'AIOSEO Twitter Image', 'unattached-media-manager' ),
                    'reference_type'  => 'url',
                    'reference_value' => $twitter_image_url,
                );
            }
        }

        return $references;
    }

    /**
     * Parse SEOPress post meta
     *
     * @param WP_Post $post Post object.
     * @return array
     */
    private function parse_seopress_post( $post ) {
        $references = array();

        // Social image
        $social_image = get_post_meta( $post->ID, '_seopress_social_fb_img', true );
        if ( $social_image ) {
            $attachment_id = UNMAM_Database::url_to_attachment_id( $social_image );
            if ( $attachment_id ) {
                $references[] = array(
                    'attachment_id'   => $attachment_id,
                    'source_id'       => $post->ID,
                    'source_type'     => 'post',
                    'context_type'    => 'seo',
                    'context_key'     => 'seopress_social',
                    'context_label'   => __( 'SEOPress Social Image', 'unattached-media-manager' ),
                    'reference_type'  => 'url',
                    'reference_value' => $social_image,
                );
            }
        }

        // Twitter image
        $twitter_image = get_post_meta( $post->ID, '_seopress_social_twitter_img', true );
        if ( $twitter_image ) {
            $attachment_id = UNMAM_Database::url_to_attachment_id( $twitter_image );
            if ( $attachment_id ) {
                $references[] = array(
                    'attachment_id'   => $attachment_id,
                    'source_id'       => $post->ID,
                    'source_type'     => 'post',
                    'context_type'    => 'seo',
                    'context_key'     => 'seopress_twitter',
                    'context_label'   => __( 'SEOPress Twitter Image', 'unattached-media-manager' ),
                    'reference_type'  => 'url',
                    'reference_value' => $twitter_image,
                );
            }
        }

        return $references;
    }

    /**
     * Parse global SEO options
     *
     * @return array
     */
    public function parse_options() {
        $references = array();

        // Yoast SEO global defaults
        if ( defined( 'WPSEO_VERSION' ) ) {
            $wpseo_social = get_option( 'wpseo_social' );
            if ( ! empty( $wpseo_social['og_default_image_id'] ) ) {
                $references[] = array(
                    'attachment_id'   => (int) $wpseo_social['og_default_image_id'],
                    'source_id'       => 0,
                    'source_type'     => 'option',
                    'context_type'    => 'seo',
                    'context_key'     => 'yoast_default_og',
                    'context_label'   => __( 'Yoast Default OpenGraph Image', 'unattached-media-manager' ),
                    'reference_type'  => 'id',
                );
            }
        }

        // Rank Math global defaults
        if ( defined( 'RANK_MATH_VERSION' ) ) {
            $rm_titles = get_option( 'rank-math-options-titles' );
            if ( ! empty( $rm_titles['open_graph_image_id'] ) ) {
                $references[] = array(
                    'attachment_id'   => (int) $rm_titles['open_graph_image_id'],
                    'source_id'       => 0,
                    'source_type'     => 'option',
                    'context_type'    => 'seo',
                    'context_key'     => 'rankmath_default_og',
                    'context_label'   => __( 'Rank Math Default OpenGraph Image', 'unattached-media-manager' ),
                    'reference_type'  => 'id',
                );
            }
        }

        return $references;
    }
}
