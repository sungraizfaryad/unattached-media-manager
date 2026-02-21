<?php
/**
 * WooCommerce Parser for Media Usage Inspector
 *
 * Parses WooCommerce product data for media references.
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WooCommerce Parser class
 */
class UNMAM_WooCommerce_Parser implements UNMAM_Parser_Interface {

    /**
     * Get parser name
     *
     * @return string
     */
    public function get_name() {
        return 'woocommerce';
    }

    /**
     * Check if WooCommerce is active
     *
     * @return bool
     */
    public function is_active() {
        return class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' );
    }

    /**
     * Parse post for WooCommerce media references
     *
     * @param WP_Post $post Post object.
     * @return array
     */
    public function parse_post( $post ) {
        $references = array();

        // Check if WooCommerce is active
        if ( ! $this->is_active() ) {
            return $references;
        }

        // Only process products
        if ( 'product' !== $post->post_type ) {
            return $references;
        }

        // Get product object
        $product = wc_get_product( $post->ID );
        if ( ! $product ) {
            return $references;
        }

        // Product gallery images
        $gallery_ids = $product->get_gallery_image_ids();
        foreach ( $gallery_ids as $attachment_id ) {
            if ( $attachment_id ) {
                $references[] = array(
                    'attachment_id'   => (int) $attachment_id,
                    'source_id'       => $post->ID,
                    'source_type'     => 'post',
                    'context_type'    => 'woocommerce',
                    'context_key'     => 'product_gallery',
                    'context_label'   => __( 'WooCommerce Product Gallery', 'unattached-media-manager' ),
                    'reference_type'  => 'id',
                );
            }
        }

        // Variation images (for variable products)
        if ( $product->is_type( 'variable' ) ) {
            $variations = $product->get_available_variations();
            foreach ( $variations as $variation ) {
                if ( ! empty( $variation['image_id'] ) ) {
                    $references[] = array(
                        'attachment_id'   => (int) $variation['image_id'],
                        'source_id'       => $post->ID,
                        'source_type'     => 'post',
                        'context_type'    => 'woocommerce',
                        'context_key'     => 'variation_image',
                        'context_label'   => __( 'WooCommerce Variation Image', 'unattached-media-manager' ),
                        'reference_type'  => 'id',
                    );
                }
            }
        }

        // Downloadable files
        if ( $product->is_downloadable() ) {
            $downloads = $product->get_downloads();
            foreach ( $downloads as $download ) {
                $file_url = $download->get_file();
                if ( ! empty( $file_url ) ) {
                    $attachment_id = UNMAM_Database::url_to_attachment_id( $file_url );
                    if ( $attachment_id ) {
                        $references[] = array(
                            'attachment_id'   => $attachment_id,
                            'source_id'       => $post->ID,
                            'source_type'     => 'post',
                            'context_type'    => 'woocommerce',
                            'context_key'     => 'downloadable_file',
                            'context_label'   => __( 'WooCommerce Downloadable File', 'unattached-media-manager' ),
                            'reference_type'  => 'url',
                            'reference_value' => $file_url,
                        );
                    }
                }
            }
        }

        return $references;
    }

    /**
     * Parse options for WooCommerce-specific media
     *
     * @return array
     */
    public function parse_options() {
        $references = array();

        if ( ! $this->is_active() ) {
            return $references;
        }

        // WooCommerce placeholder image
        $placeholder_id = get_option( 'woocommerce_placeholder_image' );
        if ( $placeholder_id ) {
            $references[] = array(
                'attachment_id'   => (int) $placeholder_id,
                'source_id'       => 0,
                'source_type'     => 'option',
                'context_type'    => 'woocommerce',
                'context_key'     => 'placeholder_image',
                'context_label'   => __( 'WooCommerce Placeholder Image', 'unattached-media-manager' ),
                'reference_type'  => 'id',
            );
        }

        // Category thumbnails
        $categories = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ) );

        if ( ! is_wp_error( $categories ) ) {
            foreach ( $categories as $category ) {
                $thumbnail_id = get_term_meta( $category->term_id, 'thumbnail_id', true );
                if ( $thumbnail_id ) {
                    $references[] = array(
                        'attachment_id'   => (int) $thumbnail_id,
                        'source_id'       => $category->term_id,
                        'source_type'     => 'term',
                        'context_type'    => 'woocommerce',
                        'context_key'     => 'category_thumbnail',
                        'context_label'   => sprintf(
                            /* translators: %s: category name */
                            __( 'Category: %s', 'unattached-media-manager' ),
                            $category->name
                        ),
                        'reference_type'  => 'id',
                    );
                }
            }
        }

        return $references;
    }
}
