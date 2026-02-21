<?php
/**
 * Attachment Manager for Media Usage Inspector
 *
 * Handles attach, detach, replace, and delete operations
 *
 * @package MediaUsageInspector
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Attachment Manager class
 */
class UNMAM_Attachment_Manager {

    /**
     * Single instance
     *
     * @var UNMAM_Attachment_Manager|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return UNMAM_Attachment_Manager
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Attach media to a post
     *
     * @param int $attachment_id Attachment ID.
     * @param int $post_id       Post ID to attach to.
     * @return bool|WP_Error
     */
    public function attach( $attachment_id, $post_id ) {
        $attachment = get_post( $attachment_id );

        if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
            return new WP_Error( 'invalid_attachment', __( 'Invalid attachment ID.', 'unattached-media-manager' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'invalid_post', __( 'Invalid post ID.', 'unattached-media-manager' ) );
        }

        $result = wp_update_post( array(
            'ID'          => $attachment_id,
            'post_parent' => $post_id,
        ), true );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        /**
         * Fires after attachment is manually attached
         *
         * @param int $attachment_id Attachment ID.
         * @param int $post_id       Post ID.
         */
        do_action( 'unmam_attachment_attached', $attachment_id, $post_id );

        return true;
    }

    /**
     * Detach media from its parent
     *
     * @param int $attachment_id Attachment ID.
     * @return bool|WP_Error
     */
    public function detach( $attachment_id ) {
        $attachment = get_post( $attachment_id );

        if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
            return new WP_Error( 'invalid_attachment', __( 'Invalid attachment ID.', 'unattached-media-manager' ) );
        }

        $old_parent = $attachment->post_parent;

        $result = wp_update_post( array(
            'ID'          => $attachment_id,
            'post_parent' => 0,
        ), true );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        /**
         * Fires after attachment is detached
         *
         * @param int $attachment_id Attachment ID.
         * @param int $old_parent    Previous parent ID.
         */
        do_action( 'unmam_attachment_detached', $attachment_id, $old_parent );

        return true;
    }

    /**
     * Replace all references to one attachment with another
     *
     * @param int  $old_attachment_id Attachment ID to replace.
     * @param int  $new_attachment_id New attachment ID.
     * @param bool $dry_run           If true, only report what would be changed.
     * @return array Results array.
     */
    public function replace_references( $old_attachment_id, $new_attachment_id, $dry_run = false ) {
        global $wpdb;

        $old_attachment = get_post( $old_attachment_id );
        $new_attachment = get_post( $new_attachment_id );

        if ( ! $old_attachment || 'attachment' !== $old_attachment->post_type ) {
            return array(
                'success' => false,
                'error'   => __( 'Invalid source attachment ID.', 'unattached-media-manager' ),
            );
        }

        if ( ! $new_attachment || 'attachment' !== $new_attachment->post_type ) {
            return array(
                'success' => false,
                'error'   => __( 'Invalid target attachment ID.', 'unattached-media-manager' ),
            );
        }

        $results = array(
            'success'     => true,
            'dry_run'     => $dry_run,
            'replaced'    => array(),
            'errors'      => array(),
            'total_count' => 0,
        );

        // Get old attachment URLs (all sizes)
        $old_urls = $this->get_attachment_urls( $old_attachment_id );
        $new_url  = wp_get_attachment_url( $new_attachment_id );

        // Get references from our index
        $references = UNMAM_Database::get_references_for_attachment( $old_attachment_id, array( 'per_page' => 1000 ) );

        foreach ( $references as $ref ) {
            $replace_result = $this->replace_single_reference( $ref, $old_attachment_id, $new_attachment_id, $old_urls, $new_url, $dry_run );

            if ( $replace_result['success'] ) {
                $results['replaced'][] = $replace_result;
                $results['total_count']++;
            } else {
                $results['errors'][] = $replace_result;
            }
        }

        if ( ! $dry_run && $results['total_count'] > 0 ) {
            // Update our reference index
            $table = UNMAM_Database::get_table_name( 'references' );
            $wpdb->update(
                $table,
                array( 'attachment_id' => $new_attachment_id ),
                array( 'attachment_id' => $old_attachment_id ),
                array( '%d' ),
                array( '%d' )
            );

            /**
             * Fires after references are replaced
             *
             * @param int   $old_attachment_id Old attachment ID.
             * @param int   $new_attachment_id New attachment ID.
             * @param array $results           Results array.
             */
            do_action( 'unmam_references_replaced', $old_attachment_id, $new_attachment_id, $results );
        }

        return $results;
    }

    /**
     * Replace a single reference
     *
     * @param array  $ref               Reference data.
     * @param int    $old_attachment_id Old attachment ID.
     * @param int    $new_attachment_id New attachment ID.
     * @param array  $old_urls          Array of old URLs.
     * @param string $new_url           New URL.
     * @param bool   $dry_run           Dry run flag.
     * @return array
     */
    private function replace_single_reference( $ref, $old_attachment_id, $new_attachment_id, $old_urls, $new_url, $dry_run ) {
        $result = array(
            'success'      => false,
            'source_id'    => $ref['source_id'],
            'source_type'  => $ref['source_type'],
            'context_type' => $ref['context_type'],
            'context_key'  => $ref['context_key'],
        );

        switch ( $ref['context_type'] ) {
            case 'featured_image':
                if ( ! $dry_run ) {
                    set_post_thumbnail( $ref['source_id'], $new_attachment_id );
                }
                $result['success'] = true;
                $result['action']  = 'updated_featured_image';
                break;

            case 'post_content':
            case 'block':
                $post = get_post( $ref['source_id'] );
                if ( $post ) {
                    $new_content = $this->replace_in_content( $post->post_content, $old_attachment_id, $new_attachment_id, $old_urls, $new_url );
                    if ( $new_content !== $post->post_content ) {
                        if ( ! $dry_run ) {
                            wp_update_post( array(
                                'ID'           => $ref['source_id'],
                                'post_content' => $new_content,
                            ) );
                        }
                        $result['success'] = true;
                        $result['action']  = 'updated_content';
                    }
                }
                break;

            case 'postmeta':
            case 'acf':
                $meta_value = get_post_meta( $ref['source_id'], $ref['context_key'], true );
                if ( $meta_value ) {
                    $new_meta = $this->replace_in_meta( $meta_value, $old_attachment_id, $new_attachment_id, $old_urls, $new_url );
                    if ( $new_meta !== $meta_value ) {
                        if ( ! $dry_run ) {
                            update_post_meta( $ref['source_id'], $ref['context_key'], $new_meta );
                        }
                        $result['success'] = true;
                        $result['action']  = 'updated_meta';
                    }
                }
                break;

            case 'option':
                $option_value = get_option( $ref['context_key'] );
                if ( $option_value ) {
                    $new_option = $this->replace_in_meta( $option_value, $old_attachment_id, $new_attachment_id, $old_urls, $new_url );
                    if ( $new_option !== $option_value ) {
                        if ( ! $dry_run ) {
                            update_option( $ref['context_key'], $new_option );
                        }
                        $result['success'] = true;
                        $result['action']  = 'updated_option';
                    }
                }
                break;

            default:
                $result['error'] = __( 'Unsupported reference type.', 'unattached-media-manager' );
                break;
        }

        return $result;
    }

    /**
     * Replace references in content
     *
     * @param string $content           Content to search.
     * @param int    $old_attachment_id Old attachment ID.
     * @param int    $new_attachment_id New attachment ID.
     * @param array  $old_urls          Old URLs to replace.
     * @param string $new_url           New URL.
     * @return string
     */
    private function replace_in_content( $content, $old_attachment_id, $new_attachment_id, $old_urls, $new_url ) {
        // Replace attachment ID in wp:image blocks and other block attributes
        $content = preg_replace(
            '/("id"\s*:\s*)' . preg_quote( (string) $old_attachment_id, '/' ) . '(\s*[,}])/',
            '${1}' . $new_attachment_id . '${2}',
            $content
        );

        // Replace in class attributes (wp-image-XXX)
        $content = str_replace(
            'wp-image-' . $old_attachment_id,
            'wp-image-' . $new_attachment_id,
            $content
        );

        // Replace URLs
        foreach ( $old_urls as $old_url ) {
            $content = str_replace( $old_url, $new_url, $content );
        }

        return $content;
    }

    /**
     * Replace references in meta value
     *
     * @param mixed  $value             Meta value (can be array, serialized, etc).
     * @param int    $old_attachment_id Old attachment ID.
     * @param int    $new_attachment_id New attachment ID.
     * @param array  $old_urls          Old URLs.
     * @param string $new_url           New URL.
     * @return mixed
     */
    private function replace_in_meta( $value, $old_attachment_id, $new_attachment_id, $old_urls, $new_url ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $item ) {
                $value[ $key ] = $this->replace_in_meta( $item, $old_attachment_id, $new_attachment_id, $old_urls, $new_url );
            }
            return $value;
        }

        if ( is_string( $value ) ) {
            // Check if it's a serialized value
            $unserialized = @unserialize( $value );
            if ( false !== $unserialized ) {
                $replaced = $this->replace_in_meta( $unserialized, $old_attachment_id, $new_attachment_id, $old_urls, $new_url );
                return serialize( $replaced );
            }

            // Check if it's JSON
            $decoded = json_decode( $value, true );
            if ( null !== $decoded && json_last_error() === JSON_ERROR_NONE ) {
                $replaced = $this->replace_in_meta( $decoded, $old_attachment_id, $new_attachment_id, $old_urls, $new_url );
                return wp_json_encode( $replaced );
            }

            // Direct ID match
            if ( (string) $value === (string) $old_attachment_id ) {
                return (string) $new_attachment_id;
            }

            // URL replacement
            foreach ( $old_urls as $old_url ) {
                $value = str_replace( $old_url, $new_url, $value );
            }

            return $value;
        }

        if ( is_int( $value ) && $value === $old_attachment_id ) {
            return $new_attachment_id;
        }

        return $value;
    }

    /**
     * Get all URLs for an attachment (all sizes)
     *
     * @param int $attachment_id Attachment ID.
     * @return array
     */
    private function get_attachment_urls( $attachment_id ) {
        $urls = array();

        // Main URL
        $main_url = wp_get_attachment_url( $attachment_id );
        if ( $main_url ) {
            $urls[] = $main_url;
        }

        // Get all registered image sizes
        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $base_url = dirname( $main_url );
            foreach ( $metadata['sizes'] as $size => $size_data ) {
                if ( isset( $size_data['file'] ) ) {
                    $urls[] = $base_url . '/' . $size_data['file'];
                }
            }
        }

        return array_unique( $urls );
    }

    /**
     * Mark attachment as safe (won't be suggested for deletion)
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    public function mark_safe( $attachment_id ) {
        return (bool) update_post_meta( $attachment_id, '_mui_marked_safe', true );
    }

    /**
     * Unmark attachment as safe
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    public function unmark_safe( $attachment_id ) {
        return delete_post_meta( $attachment_id, '_mui_marked_safe' );
    }

    /**
     * Check if attachment is marked safe
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    public function is_marked_safe( $attachment_id ) {
        return (bool) get_post_meta( $attachment_id, '_mui_marked_safe', true );
    }

    /**
     * Mark attachment as unused (candidate for deletion)
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    public function mark_unused( $attachment_id ) {
        return (bool) update_post_meta( $attachment_id, '_mui_marked_unused', current_time( 'mysql' ) );
    }

    /**
     * Unmark as unused
     *
     * @param int $attachment_id Attachment ID.
     * @return bool
     */
    public function unmark_unused( $attachment_id ) {
        return delete_post_meta( $attachment_id, '_mui_marked_unused' );
    }

    /**
     * Safely delete attachment (moves to trash first if not already)
     *
     * @param int  $attachment_id Attachment ID.
     * @param bool $force_delete  Skip trash.
     * @return bool|WP_Error
     */
    public function safe_delete( $attachment_id, $force_delete = false ) {
        $attachment = get_post( $attachment_id );

        if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
            return new WP_Error( 'invalid_attachment', __( 'Invalid attachment ID.', 'unattached-media-manager' ) );
        }

        // Check if marked safe
        if ( $this->is_marked_safe( $attachment_id ) ) {
            return new WP_Error( 'marked_safe', __( 'This attachment is marked as safe and cannot be deleted.', 'unattached-media-manager' ) );
        }

        // Check for existing references
        $reference_count = UNMAM_Database::get_reference_count( $attachment_id );
        if ( $reference_count > 0 ) {
            return new WP_Error(
                'has_references',
                sprintf(
                    /* translators: %d: number of references */
                    __( 'This attachment has %d references and cannot be deleted.', 'unattached-media-manager' ),
                    $reference_count
                )
            );
        }

        // Delete
        $result = wp_delete_attachment( $attachment_id, $force_delete );

        if ( ! $result ) {
            return new WP_Error( 'delete_failed', __( 'Failed to delete attachment.', 'unattached-media-manager' ) );
        }

        /**
         * Fires after attachment is safely deleted
         *
         * @param int $attachment_id Attachment ID.
         */
        do_action( 'unmam_attachment_deleted', $attachment_id );

        return true;
    }

    /**
     * Bulk attach multiple attachments to a post
     *
     * @param array $attachment_ids Array of attachment IDs.
     * @param int   $post_id        Post ID.
     * @return array Results.
     */
    public function bulk_attach( $attachment_ids, $post_id ) {
        $results = array(
            'success' => array(),
            'errors'  => array(),
        );

        foreach ( $attachment_ids as $attachment_id ) {
            $result = $this->attach( $attachment_id, $post_id );
            if ( is_wp_error( $result ) ) {
                $results['errors'][ $attachment_id ] = $result->get_error_message();
            } else {
                $results['success'][] = $attachment_id;
            }
        }

        return $results;
    }

    /**
     * Bulk detach multiple attachments
     *
     * @param array $attachment_ids Array of attachment IDs.
     * @return array Results.
     */
    public function bulk_detach( $attachment_ids ) {
        $results = array(
            'success' => array(),
            'errors'  => array(),
        );

        foreach ( $attachment_ids as $attachment_id ) {
            $result = $this->detach( $attachment_id );
            if ( is_wp_error( $result ) ) {
                $results['errors'][ $attachment_id ] = $result->get_error_message();
            } else {
                $results['success'][] = $attachment_id;
            }
        }

        return $results;
    }

    /**
     * Export usage report for attachments
     *
     * @param array $args Export arguments.
     * @return array CSV-ready data.
     */
    public function export_report( $args = array() ) {
        $defaults = array(
            'attachment_ids' => array(), // Empty = all
            'include_unused' => true,
        );

        $args = wp_parse_args( $args, $defaults );

        $data = array();
        $data[] = array(
            __( 'Attachment ID', 'unattached-media-manager' ),
            __( 'Title', 'unattached-media-manager' ),
            __( 'File', 'unattached-media-manager' ),
            __( 'Reference Count', 'unattached-media-manager' ),
            __( 'Contexts', 'unattached-media-manager' ),
            __( 'Post Parent', 'unattached-media-manager' ),
            __( 'Marked Safe', 'unattached-media-manager' ),
        );

        // Get attachments
        $query_args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'any',
            'posts_per_page' => -1,
        );

        if ( ! empty( $args['attachment_ids'] ) ) {
            $query_args['post__in'] = $args['attachment_ids'];
        }

        $attachments = get_posts( $query_args );

        foreach ( $attachments as $attachment ) {
            $references = UNMAM_Database::get_references_for_attachment( $attachment->ID, array( 'per_page' => 1000 ) );
            $contexts   = array_unique( array_column( $references, 'context_type' ) );

            if ( ! $args['include_unused'] && empty( $references ) ) {
                continue;
            }

            $data[] = array(
                $attachment->ID,
                $attachment->post_title,
                basename( get_attached_file( $attachment->ID ) ),
                count( $references ),
                implode( ', ', $contexts ),
                $attachment->post_parent,
                $this->is_marked_safe( $attachment->ID ) ? __( 'Yes', 'unattached-media-manager' ) : __( 'No', 'unattached-media-manager' ),
            );
        }

        return $data;
    }
}
