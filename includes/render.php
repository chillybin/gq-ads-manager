<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue tracking.js the first time an ad is rendered on a page.
 *
 * Called lazily from gq_ads_render_placement() rather than wp_enqueue_scripts,
 * so the script and its nonce are only generated on pages that actually have ads.
 */
function gq_ads_enqueue_tracking(): void {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	// SCRIPT_DEBUG: serve unminified source in development, minified in production.
	$script_file = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? 'tracking.js' : 'tracking.min.js';
	wp_enqueue_script(
		'gq-ads-tracking',
		GQ_ADS_PLUGIN_URL . 'assets/js/' . $script_file,
		[],
		GQ_ADS_VERSION,
		true // footer — DOM must exist before IntersectionObserver attaches
	);
	wp_localize_script(
		'gq-ads-tracking',
		'gqAdsTracking',
		[
			'endpoint' => rest_url( 'gq-ads/v1/track' ),
			'nonce'    => wp_create_nonce( 'gq_ads_track' ),
		]
	);
}

/**
 * Shortcode: [gq_ad placement="leaderboard"]
 *
 * @param array<string,string>|string $atts WordPress passes array or '' when no attributes given.
 */
function gq_ads_shortcode( array|string $atts ): string {
	$atts = shortcode_atts(
		[
			'placement' => '',
		],
		$atts
	);

	if ( empty( $atts['placement'] ) ) {
		return '';
	}

	return gq_ads_render_placement( sanitize_title( $atts['placement'] ) );
}

/**
 * Fetch all published groups for a placement, with a 60-second object cache.
 *
 * Cached separately from rendering so the same query is not repeated when
 * the same placement appears multiple times per page.
 *
 * @return list<WP_Post>
 */
function gq_ads_get_groups_for_placement( int $placement_id ): array {
	$cache_key = 'groups_for_' . $placement_id;
	$groups    = wp_cache_get( $cache_key, 'gq_ads' );

	if ( false === $groups ) {
		$groups = get_posts( [
			'post_type'              => 'gq_ad_group',
			'post_status'            => 'publish',
			'posts_per_page'         => 100,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => [
				'relation' => 'OR',
				[ 'key' => 'gq_ad_placement', 'value' => '"' . $placement_id . '"', 'compare' => 'LIKE' ],
				[ 'key' => 'gq_ad_placement', 'value' => $placement_id, 'compare' => '=' ],
				[ 'key' => 'gq_ad_placement', 'value' => ':' . $placement_id . ';', 'compare' => 'LIKE' ],
			],
		] );
		wp_cache_set( $cache_key, $groups, 'gq_ads', 5 * MINUTE_IN_SECONDS );
	}

	return is_array( $groups ) ? $groups : [];
}

/**
 * Fetch all published ads for a group, with a 60-second object cache.
 *
 * @return list<WP_Post>
 */
function gq_ads_get_ads_for_group( int $group_id ): array {
	$cache_key = 'ads_for_' . $group_id;
	$ads       = wp_cache_get( $cache_key, 'gq_ads' );

	if ( false === $ads ) {
		$ads = get_posts( [
			'post_type'              => 'gq_ad',
			'post_status'            => 'publish',
			'posts_per_page'         => 100,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => [
				'relation' => 'OR',
				[ 'key' => 'gq_ad_group', 'value' => '"' . $group_id . '"', 'compare' => 'LIKE' ],
				[ 'key' => 'gq_ad_group', 'value' => $group_id, 'compare' => '=' ],
				[ 'key' => 'gq_ad_group', 'value' => ':' . $group_id . ';', 'compare' => 'LIKE' ],
			],
		] );
		wp_cache_set( $cache_key, $ads, 'gq_ads', 5 * MINUTE_IN_SECONDS );
	}

	return is_array( $ads ) ? $ads : [];
}

/**
 * Render an ad placement by slug.
 *
 * Selection priority:
 *   1. Exclusive (100% SOV) groups that match the current page's categories.
 *   2. Category-targeted groups that match the current page.
 *   3. Global groups (no category restriction).
 *
 * In all cases only groups within their campaign date range are considered.
 * If nothing renders, the placement's fallback ad code is returned instead.
 */
function gq_ads_render_placement( string $placement_slug ): string {

	$debug = defined( 'WP_DEBUG' ) && WP_DEBUG;

	$placement = get_page_by_path( $placement_slug, OBJECT, 'gq_ad_placement' );

	if ( ! $placement ) {
		return gq_ads_render_fallback( 0, $placement_slug,
			$debug ? "<!-- GQ Ads: Placement '{$placement_slug}' not found -->" : ''
		);
	}

	$placement_id = (int) $placement->ID;

	$groups = gq_ads_get_groups_for_placement( $placement_id );

	if ( empty( $groups ) ) {
		return gq_ads_render_fallback( $placement_id, $placement_slug,
			$debug ? "<!-- GQ Ads: No groups found for placement '{$placement_slug}' (ID: {$placement_id}) -->" : ''
		);
	}

	// Remove groups outside their campaign date window.
	$active_groups = array_values( array_filter( $groups, static fn ( WP_Post $g ) => gq_ads_is_group_active( $g->ID ) ) );

	if ( empty( $active_groups ) ) {
		return gq_ads_render_fallback( $placement_id, $placement_slug,
			$debug ? "<!-- GQ Ads: No active groups for placement '{$placement_slug}' -->" : ''
		);
	}

	$page_cats = gq_ads_get_page_categories();

	$exclusive_groups = [];
	$targeted_groups  = [];
	$global_groups    = [];

	foreach ( $active_groups as $group ) {
		$is_exclusive = get_post_meta( $group->ID, 'gq_group_exclusive', true ) === '1';
		$group_cats   = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $group->ID, 'gq_group_categories', true ) ) ) );
		$has_cats     = [] !== $group_cats;
		$cat_match    = $has_cats && [] !== array_intersect( $page_cats, $group_cats );

		if ( $is_exclusive ) {
			if ( ! $has_cats || $cat_match ) {
				$exclusive_groups[] = $group;
			}
		} elseif ( $has_cats ) {
			if ( $cat_match ) {
				$targeted_groups[] = $group;
			}
		} else {
			$global_groups[] = $group;
		}
	}

	$candidate_groups = match ( true ) {
		[] !== $exclusive_groups => $exclusive_groups,
		[] !== $targeted_groups  => $targeted_groups,
		default                  => $global_groups,
	};

	if ( [] === $candidate_groups ) {
		return gq_ads_render_fallback( $placement_id, $placement_slug, '' );
	}

	$group = gq_ads_select_weighted_item( $candidate_groups, 'gq_group_weight' );

	if ( null === $group ) {
		return gq_ads_render_fallback( $placement_id, $placement_slug, '' );
	}

	$group_id = (int) $group->ID;
	$ads      = gq_ads_get_ads_for_group( $group_id );

	if ( [] === $ads ) {
		return gq_ads_render_fallback( $placement_id, $placement_slug,
			$debug ? "<!-- GQ Ads: No ads found for group ID: {$group_id} -->" : ''
		);
	}

	$ad = gq_ads_select_weighted_item( $ads, 'gq_ad_weight' );

	if ( null === $ad ) {
		return gq_ads_render_fallback( $placement_id, $placement_slug, '' );
	}

	$html = gq_ads_render_single_ad( (int) $ad->ID, $placement_id, $group_id );

	if ( '' === $html ) {
		return gq_ads_render_fallback( $placement_id, $placement_slug, '' );
	}

	// Enqueue tracking.js the first time an ad renders (lazy — pages with no ads skip this).
	gq_ads_enqueue_tracking();

	/**
	 * Filters the final rendered HTML for a placement.
	 *
	 * @param string $html           The rendered ad HTML.
	 * @param string $placement_slug Placement slug (e.g. 'sidebar', 'leaderboard').
	 * @param int    $placement_id   Placement post ID.
	 */
	return apply_filters( 'gq_ads_render_placement_output', $html, $placement_slug, $placement_id );
}

/**
 * Return the placement's fallback ad code, or a debug comment if none is set.
 *
 * @param int    $placement_id  0 when the placement itself was not found.
 * @param string $placement_slug
 * @param string $debug_comment HTML comment returned only when no fallback exists.
 */
function gq_ads_render_fallback( int $placement_id, string $placement_slug, string $debug_comment ): string {
	$fallback_html = '';

	if ( $placement_id > 0 ) {
		$fallback_code = (string) get_post_meta( $placement_id, 'gq_placement_google_fallback', true );
		if ( '' !== $fallback_code ) {
			$fallback_html = sprintf(
				'<div class="gq-ad gq-ad-placement-%s gq-ad-fallback">%s</div>',
				esc_attr( $placement_slug ),
				$fallback_code
			);
		}
	}

	/**
	 * Filters the fallback HTML shown when no active group matches a placement.
	 *
	 * Returning a non-empty string suppresses the debug comment.
	 *
	 * @param string $fallback_html  The fallback HTML (empty string if no fallback configured).
	 * @param int    $placement_id   Placement post ID (0 when placement was not found).
	 * @param string $placement_slug Placement slug.
	 */
	$fallback_html = (string) apply_filters( 'gq_ads_fallback_output', $fallback_html, $placement_id, $placement_slug );

	return '' !== $fallback_html ? $fallback_html : $debug_comment;
}

/**
 * Check whether a group is within its campaign date window.
 */
function gq_ads_is_group_active( int $group_id ): bool {
	$today      = current_time( 'Y-m-d' );
	$start_date = (string) get_post_meta( $group_id, 'gq_group_start_date', true );
	$end_date   = (string) get_post_meta( $group_id, 'gq_group_end_date', true );

	if ( '' !== $start_date && $today < $start_date ) {
		return false;
	}
	if ( '' !== $end_date && $today > $end_date ) {
		return false;
	}

	return true;
}

/**
 * Return the category IDs relevant to the current page.
 *
 * Returns post categories for singular posts and the current term ID for
 * category archive pages. Returns an empty array everywhere else (homepage,
 * search, etc.) so only global groups are candidates there.
 *
 * @return list<int>
 */
function gq_ads_get_page_categories(): array {
	if ( is_singular() ) {
		return array_map( 'intval', wp_get_post_categories( get_queried_object_id() ) );
	}
	if ( is_category() ) {
		return [ (int) get_queried_object_id() ];
	}
	return [];
}

/**
 * Select an item from array using proportional weighted random selection.
 *
 * @param list<WP_Post> $items
 * @return WP_Post|null
 */
function gq_ads_select_weighted_item( array $items, string $weight_meta_key ): ?WP_Post {
	if ( [] === $items ) {
		return null;
	}

	/** @var array<int, array{item: WP_Post, weight: int}> $weighted */
	$weighted    = [];
	$has_weights = false;

	foreach ( $items as $item ) {
		$weight = (int) get_post_meta( $item->ID, $weight_meta_key, true );

		if ( $weight <= 0 ) {
			$weight = 50;
		} else {
			$has_weights = true;
		}

		$weighted[] = [ 'item' => $item, 'weight' => $weight ];
	}

	// Two items with no explicit weights → enforce 50/50 split.
	if ( ! $has_weights && 2 === count( $items ) ) {
		$weighted[0]['weight'] = 50;
		$weighted[1]['weight'] = 50;
	}

	$total_weight = (int) array_sum( array_column( $weighted, 'weight' ) );

	if ( $total_weight <= 0 ) {
		return $items[ array_rand( $items ) ];
	}

	$random  = mt_rand( 1, $total_weight );
	$current = 0;

	foreach ( $weighted as $w ) {
		$current += $w['weight'];
		if ( $random <= $current ) {
			return $w['item'];
		}
	}

	return $items[0];
}

/**
 * Render a single ad's HTML with tracking wrappers.
 */
function gq_ads_render_single_ad( int $ad_id, int $placement_id, int $group_id = 0 ): string {
	$ad_type = GQ_Ad_Type::fromMeta( get_post_meta( $ad_id, 'gq_ad_type', true ) );

	if ( null === $ad_type ) {
		return '';
	}

	$html = match ( $ad_type ) {
		GQ_Ad_Type::ADSENSE    => gq_ads_build_adsense_html( $ad_id ),
		GQ_Ad_Type::HTML       => gq_ads_build_html_ad( $ad_id ),
		GQ_Ad_Type::IMAGE_LINK => gq_ads_build_image_link_html( $ad_id ),
	};

	if ( '' === $html ) {
		return '';
	}

	$placement_slug = (string) get_post_field( 'post_name', $placement_id );
	$device_class   = gq_ads_resolve_device_class( $placement_slug );

	// Tracking is handled by assets/js/tracking.js (enqueued via wp_enqueue_scripts).
	// Data attributes on the wrapper are all the script needs.
	return sprintf(
		'<div class="gq-ad gq-ad-placement-%s %s" data-gq-ad-id="%d" data-gq-placement-id="%d" data-gq-group-id="%d" data-gq-ad-type="%s">%s</div>',
		esc_attr( $placement_slug ),
		esc_attr( $device_class ),
		$ad_id,
		$placement_id,
		$group_id,
		esc_attr( $ad_type->value ),
		$html
	);
}

/**
 * Build HTML for an AdSense ad unit.
 */
function gq_ads_build_adsense_html( int $ad_id ): string {
	$snippet = (string) get_post_meta( $ad_id, 'gq_ad_code', true );
	return '' !== $snippet ? gq_ads_render_adsense_unit( $snippet ) : '';
}

/**
 * Build HTML for a raw HTML/JS ad creative.
 */
function gq_ads_build_html_ad( int $ad_id ): string {
	return (string) get_post_meta( $ad_id, 'gq_ad_code', true );
}

/**
 * Build HTML for an image-link ad creative.
 */
function gq_ads_build_image_link_html( int $ad_id ): string {
	$img_id = (int) get_post_meta( $ad_id, 'gq_ad_image', true );
	$url    = (string) get_post_meta( $ad_id, 'gq_target_url', true );
	$src    = $img_id > 0 ? ( wp_get_attachment_image_url( $img_id, 'full' ) ?: '' ) : '';

	if ( '' === $src || '' === $url ) {
		return '';
	}

	return sprintf(
		'<a href="%s" target="_blank" rel="noopener noreferrer"><img src="%s" alt="%s" style="max-width: 100%%; height: auto;"></a>',
		esc_url( $url ),
		esc_url( $src ),
		esc_attr( (string) get_the_title( $ad_id ) )
	);
}

/**
 * Resolve the CSS device-targeting class for a placement slug.
 */
function gq_ads_resolve_device_class( string $placement_slug ): string {
	if ( str_contains( $placement_slug, '-mobile' ) ) {
		return 'gq-ad-mobile-only';
	}
	if ( str_contains( $placement_slug, '-desktop' ) ) {
		return 'gq-ad-desktop-only';
	}
	// Auto-pair: if a mobile variant exists, this placement is desktop-only.
	$mobile_placement = get_page_by_path( $placement_slug . '-mobile', OBJECT, 'gq_ad_placement' );
	return $mobile_placement ? 'gq-ad-desktop-only' : '';
}

