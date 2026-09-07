<?php
/**
 * Plugin Name: UNMAM ACF Harness (TEMPORARY)
 * Description: Local ACF field groups covering every media-bearing field type, on a term and a post. Delete when done.
 *
 * Registered locally rather than as acf-field-group posts so nothing is left in the database
 * to clean up afterwards. Pairs with acf-seed.php.
 */

add_action( 'acf/init', function () {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_local_field_group( array(
		'key'    => 'group_unmam_term',
		'title'  => 'UNMAM Term Fixture',
		'fields' => array(
			array( 'key' => 'field_unmam_img',  'name' => 'unmam_img',  'label' => 'Image',    'type' => 'image', 'return_format' => 'id' ),
			array( 'key' => 'field_unmam_gal',  'name' => 'unmam_gal',  'label' => 'Gallery',  'type' => 'gallery' ),
			array( 'key' => 'field_unmam_file', 'name' => 'unmam_file', 'label' => 'File',     'type' => 'file', 'return_format' => 'id' ),
			array( 'key' => 'field_unmam_wys',  'name' => 'unmam_wys',  'label' => 'Editor',   'type' => 'wysiwyg' ),
			array( 'key' => 'field_unmam_area', 'name' => 'unmam_area', 'label' => 'Blurb',    'type' => 'textarea' ),
			array( 'key' => 'field_unmam_text', 'name' => 'unmam_text', 'label' => 'Plain',    'type' => 'text' ),
			array( 'key' => 'field_unmam_link', 'name' => 'unmam_link', 'label' => 'Link',     'type' => 'link' ),
			array( 'key' => 'field_unmam_url',  'name' => 'unmam_url',  'label' => 'Url',      'type' => 'url' ),
			array( 'key' => 'field_unmam_icon', 'name' => 'unmam_icon', 'label' => 'Icon',     'type' => 'icon_picker' ),
			array(
				'key' => 'field_unmam_rep', 'name' => 'unmam_rep', 'label' => 'Repeater', 'type' => 'repeater',
				'sub_fields' => array(
					array( 'key' => 'field_unmam_rep_img', 'name' => 'rep_img', 'label' => 'Row image', 'type' => 'image', 'return_format' => 'id' ),
				),
			),
			array(
				'key' => 'field_unmam_grp', 'name' => 'unmam_grp', 'label' => 'Group', 'type' => 'group',
				'sub_fields' => array(
					array( 'key' => 'field_unmam_grp_img', 'name' => 'grp_img', 'label' => 'Group image', 'type' => 'image', 'return_format' => 'id' ),
				),
			),
			// External-URL false positive: a link to someone else's file that shares a basename
			// with one of ours. Must NOT be credited to the local file.
			array( 'key' => 'field_unmam_extlink', 'name' => 'unmam_extlink', 'label' => 'External link', 'type' => 'link' ),
			// Same file served from a CDN host with the uploads path intact. Must still resolve.
			array( 'key' => 'field_unmam_cdnurl', 'name' => 'unmam_cdnurl', 'label' => 'CDN url', 'type' => 'url' ),
		),
		'location' => array( array( array( 'param' => 'taxonomy', 'operator' => '==', 'value' => 'unmam_tax' ) ) ),
	) );

	acf_add_local_field_group( array(
		'key'      => 'group_unmam_post',
		'title'    => 'UNMAM Post Fixture',
		'fields'   => array(
			array( 'key' => 'field_unmam_post_wys', 'name' => 'unmam_post_wys', 'label' => 'Post editor', 'type' => 'wysiwyg' ),
		),
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'unmam_pub' ) ) ),
	) );
} );
