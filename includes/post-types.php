<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register custom post types for ads and placements.
 */
function gq_ads_register_post_types(): void {

	// Shared args for all three CPTs — internal management only, never exposed publicly.
	$shared = [
		'public'              => false, // Not queryable on the frontend.
		'publicly_queryable'  => false,
		'exclude_from_search' => true,
		'has_archive'         => false,
		'show_in_nav_menus'   => false, // Prevent accidental addition to site nav.
		'show_ui'             => true,
		'show_in_rest'        => false, // REST API access not required; handled via REST tracking endpoint.
		'supports'            => [ 'title' ],
		'capability_type'     => 'post',
		'map_meta_cap'        => true,
	];

	register_post_type( 'gq_ad_placement', array_merge( $shared, [
		'label'         => __( 'Ad Placements', 'gq-ads' ),
		'labels'        => [
			'name'          => __( 'Ad Placements', 'gq-ads' ),
			'singular_name' => __( 'Ad Placement', 'gq-ads' ),
			'add_new'       => __( 'Add New Placement', 'gq-ads' ),
			'add_new_item'  => __( 'Add New Placement', 'gq-ads' ),
			'edit_item'     => __( 'Edit Placement', 'gq-ads' ),
			'new_item'      => __( 'New Placement', 'gq-ads' ),
			'view_item'     => __( 'View Placement', 'gq-ads' ),
			'search_items'  => __( 'Search Placements', 'gq-ads' ),
		],
		'show_in_menu'  => true,
		'menu_position' => 25,
		'menu_icon'     => 'dashicons-align-full-width',
	] ) );

	register_post_type( 'gq_ad_group', array_merge( $shared, [
		'label'         => __( 'Ad Groups', 'gq-ads' ),
		'labels'        => [
			'name'          => __( 'Ad Groups', 'gq-ads' ),
			'singular_name' => __( 'Ad Group', 'gq-ads' ),
			'add_new'       => __( 'Add New Group', 'gq-ads' ),
			'add_new_item'  => __( 'Add New Group', 'gq-ads' ),
			'edit_item'     => __( 'Edit Group', 'gq-ads' ),
			'new_item'      => __( 'New Group', 'gq-ads' ),
			'view_item'     => __( 'View Group', 'gq-ads' ),
			'search_items'  => __( 'Search Groups', 'gq-ads' ),
		],
		'show_in_menu'  => 'edit.php?post_type=gq_ad_placement',
		'menu_position' => 25.5,
		'menu_icon'     => 'dashicons-category',
	] ) );

	register_post_type( 'gq_ad', array_merge( $shared, [
		'label'         => __( 'Ads', 'gq-ads' ),
		'labels'        => [
			'name'          => __( 'Ads', 'gq-ads' ),
			'singular_name' => __( 'Ad', 'gq-ads' ),
			'add_new'       => __( 'Add New Ad', 'gq-ads' ),
			'add_new_item'  => __( 'Add New Ad', 'gq-ads' ),
			'edit_item'     => __( 'Edit Ad', 'gq-ads' ),
			'new_item'      => __( 'New Ad', 'gq-ads' ),
			'view_item'     => __( 'View Ad', 'gq-ads' ),
			'search_items'  => __( 'Search Ads', 'gq-ads' ),
		],
		'show_in_menu'  => 'edit.php?post_type=gq_ad_placement',
		'menu_position' => 26,
		'menu_icon'     => 'dashicons-megaphone',
	] ) );
}
