<?php
/**
 * Theme design WordPress keeps in the database: Additional CSS, Site Editor templates and
 * template parts, and Site Editor styles. Each one belongs to a theme.
 *
 * @package UnattachedMediaManager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UNMAM_Design_Content {

    /**
     * @param string $post_type
     * @return bool
     */
    public static function is_design_type( $post_type ) {
        return in_array( $post_type, unmam_design_post_types(), true );
    }

    /**
     * The stylesheet a design post belongs to, as stored, or '' when it can't be told.
     *
     * Additional CSS keeps it in post_title (post_name is the sanitized copy). The other
     * three carry a wp_theme term named after the stylesheet.
     *
     * @param int    $post_id
     * @param string $post_type
     * @return string
     */
    public static function theme_of( $post_id, $post_type ) {
        if ( 'custom_css' === $post_type ) {
            $title = (string) get_post_field( 'post_title', $post_id, 'raw' );
            return '' !== $title ? $title : (string) get_post_field( 'post_name', $post_id, 'raw' );
        }

        $terms = get_the_terms( $post_id, 'wp_theme' );
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return '';
        }

        return (string) $terms[0]->name;
    }

    /**
     * Unknown theme counts as installed. Skipping it would report its images unused.
     *
     * @param string $stylesheet
     * @return bool
     */
    public static function is_installed( $stylesheet ) {
        if ( '' === $stylesheet ) {
            return true;
        }

        $wanted = sanitize_title( $stylesheet );
        foreach ( array_keys( wp_get_themes() ) as $installed ) {
            if ( sanitize_title( $installed ) === $wanted ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $stylesheet
     * @return bool
     */
    public static function is_active( $stylesheet ) {
        return '' !== $stylesheet && sanitize_title( $stylesheet ) === sanitize_title( get_stylesheet() );
    }

    /**
     * Where Used entry for a design post.
     *
     * Edit links only for the active theme: core builds template links from
     * get_stylesheet(), so for any other theme it would open the wrong template.
     *
     * @param WP_Post $post
     * @return array array( 'title', 'edit_link', 'view_link' )
     */
    public static function describe( $post ) {
        $stylesheet = self::theme_of( $post->ID, $post->post_type );

        switch ( $post->post_type ) {
            case 'custom_css':
                $label = __( 'Additional CSS', 'unattached-media-manager' );
                break;
            case 'wp_template':
                /* translators: %s: template title */
                $label = sprintf( __( 'Template: %s', 'unattached-media-manager' ), $post->post_title ? $post->post_title : $post->post_name );
                break;
            case 'wp_template_part':
                /* translators: %s: template part title */
                $label = sprintf( __( 'Template part: %s', 'unattached-media-manager' ), $post->post_title ? $post->post_title : $post->post_name );
                break;
            default:
                $label = __( 'Styles', 'unattached-media-manager' );
        }

        $title = $label;
        if ( '' !== $stylesheet ) {
            $theme = wp_get_theme( $stylesheet );
            $title = sprintf(
                /* translators: 1: design item, e.g. "Additional CSS", 2: theme name */
                __( '%1$s (%2$s)', 'unattached-media-manager' ),
                $label,
                $theme->exists() ? $theme->get( 'Name' ) : $stylesheet
            );
        }

        $edit_link = null;
        if ( self::is_active( $stylesheet ) ) {
            if ( 'custom_css' === $post->post_type ) {
                if ( current_user_can( 'edit_css' ) ) {
                    $edit_link = admin_url( 'customize.php?autofocus[section]=custom_css' );
                }
            } else {
                $edit_link = get_edit_post_link( $post->ID );
            }
        }

        return array(
            'title'     => $title,
            'edit_link' => $edit_link ? $edit_link : null,
            'view_link' => null,
        );
    }
}
