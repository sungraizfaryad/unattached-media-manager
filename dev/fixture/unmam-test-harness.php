<?php
/**
 * Plugin Name: UNMAM Test Harness (TEMPORARY)
 * Description: Registers post types and a taxonomy used to verify scanner-coverage fixes. Delete when done.
 */

add_action( 'init', function () {
	// Public CPT, registered after unmam_settings was first saved.
	// Reproduces the stale scan_post_types snapshot.
	register_post_type( 'unmam_pub', array(
		'public'  => true,
		'label'   => 'UNMAM Public Test',
		'supports'=> array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
	) );

	// Non-public but UI-visible CPT. Same shape as a Bricks/builder template type:
	// can never appear in a settings list filtered on public => true.
	register_post_type( 'unmam_hidden', array(
		'public'             => false,
		'publicly_queryable' => false,
		'show_ui'            => true,
		'label'              => 'UNMAM Hidden Test',
		'supports'           => array( 'title', 'editor', 'custom-fields' ),
	) );

	register_taxonomy( 'unmam_tax', array( 'unmam_pub' ), array(
		'public'  => true,
		'label'   => 'UNMAM Test Taxonomy',
	) );
} );
