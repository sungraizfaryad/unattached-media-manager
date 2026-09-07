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

        // Variations. get_children() rather than get_available_variations(): the latter only
        // returns purchasable, visible variations, so media on a disabled or hidden one looked
        // unused, and it builds a full display array per variation for no reason here.
        if ( $product->is_type( 'variable' ) ) {
            foreach ( $product->get_children() as $variation_id ) {
                $variation = wc_get_product( $variation_id );
                if ( ! $variation ) {
                    continue;
                }

                $image_id = $variation->get_image_id();
                if ( $image_id ) {
                    $references[] = array(
                        'attachment_id'   => (int) $image_id,
                        'source_id'       => $post->ID,
                        'source_type'     => 'post',
                        'context_type'    => 'woocommerce',
                        'context_key'     => 'variation_image',
                        'context_label'   => __( 'WooCommerce Variation Image', 'unattached-media-manager' ),
                        'reference_type'  => 'id',
                    );
                }

                // A variable parent is never itself downloadable, so the parent-level check
                // below never sees these. Files sold through a variation were reported unused.
                $references = array_merge(
                    $references,
                    $this->collect_downloads(
                        $variation,
                        $post->ID,
                        'variation_downloadable_file',
                        __( 'WooCommerce Variation Download', 'unattached-media-manager' )
                    )
                );
            }
        }

        // Downloadable files on the product itself (simple, external, grouped).
        $references = array_merge(
            $references,
            $this->collect_downloads(
                $product,
                $post->ID,
                'downloadable_file',
                __( 'WooCommerce Downloadable File', 'unattached-media-manager' )
            )
        );

        return $references;
    }

    /**
     * Collect download references from a product or a variation.
     *
     * Shared so the parent and variation paths cannot drift apart again.
     *
     * @param WC_Product $product   Product or variation.
     * @param int        $source_id Post ID to credit, always the parent product.
     * @param string     $key       Context key.
     * @param string     $label     Context label.
     * @return array
     */
    private function collect_downloads( $product, $source_id, $key, $label ) {
        $references = array();

        if ( ! $product->is_downloadable() ) {
            return $references;
        }

        foreach ( $product->get_downloads() as $download ) {
            $file_url = $download->get_file();
            if ( empty( $file_url ) ) {
                continue;
            }

            $attachment_id = UNMAM_Database::url_to_attachment_id( $file_url );
            if ( ! $attachment_id ) {
                continue;
            }

            $references[] = array(
                'attachment_id'   => $attachment_id,
                'source_id'       => $source_id,
                'source_type'     => 'post',
                'context_type'    => 'woocommerce',
                'context_key'     => $key,
                'context_label'   => $label,
                'reference_type'  => 'url',
                'reference_value' => $file_url,
            );
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
