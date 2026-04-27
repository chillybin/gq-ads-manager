<?php

declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register REST route for ad tracking.
 */
add_action( 'rest_api_init', function (): void {
	register_rest_route(
		'gq-ads/v1',
		'/track',
		[
			'methods'             => 'POST',
			'callback'            => 'gq_ads_rest_track',
			// Public endpoint — nonce verification inside the callback acts as CSRF guard.
			// No authentication required: tracking pings are anonymous by design.
			'permission_callback' => '__return_true',
			'args'                => [
				'ad_id'        => [
					'required'          => true,
					'type'              => 'integer',
					'minimum'           => 1,
					'sanitize_callback' => 'absint',
				],
				'placement_id' => [
					'required'          => true,
					'type'              => 'integer',
					'minimum'           => 1,
					'sanitize_callback' => 'absint',
				],
				'group_id'     => [
					'required'          => false,
					'type'              => 'integer',
					'minimum'           => 0,
					'default'           => 0,
					'sanitize_callback' => 'absint',
				],
				'action'       => [
					'required' => true,
					'type'     => 'string',
					'enum'     => [ 'impression', 'click' ],
				],
				'_wpnonce'     => [
					'required' => true,
					'type'     => 'string',
				],
			],
		]
	);
} );

/**
 * Safely log error messages with sanitization.
 * Removes control characters and sanitizes content to prevent log injection.
 *
 * @param string $message Context message (e.g., "Database error tracking impression").
 * @param string $error   Raw error message to sanitize.
 */
function gq_ads_safe_error_log( string $message, string $error ): void {
	$safe_error = sanitize_text_field( str_replace( [ "\r", "\n", "\t" ], ' ', $error ) );
	error_log( 'GQ Ads: ' . $message . ': ' . $safe_error );
}

/**
 * Handle tracking calls.
 *
 * Expected JSON:
 * {
 *   "ad_id": 123,
 *   "placement_id": 456,
 *   "group_id": 789,
 *   "action": "impression"
 * }
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function gq_ads_rest_track( WP_REST_Request $request ): WP_REST_Response {
	global $wpdb;

	// CSRF: verify nonce first — strict (=== 1) to reject previous-tick nonces.
	// _wpnonce has no sanitize_callback in the schema, so sanitise here.
	$nonce = sanitize_text_field( (string) $request->get_param( '_wpnonce' ) );

	if ( 1 !== wp_verify_nonce( $nonce, 'gq_ads_track' ) ) {
		return new WP_REST_Response( [ 'success' => false, 'error' => 'invalid_nonce' ], 403 );
	}

	// Rate limiting: max 20 requests per IP per minute.
	// Applied after nonce check so invalid requests don't consume quota.
	$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	if ( $ip ) {
		$rate_key = 'gq_ads_rate_' . md5( $ip );
		$count    = (int) get_transient( $rate_key );

		if ( $count > 20 ) {
			return new WP_REST_Response( [ 'success' => false, 'error' => 'rate_limit_exceeded' ], 429 );
		}

		set_transient( $rate_key, $count + 1, 60 );
	}

	// get_param() returns values pre-sanitized by the args schema (absint for IDs, enum for action).
	$ad_id        = (int) $request->get_param( 'ad_id' );
	$placement_id = (int) $request->get_param( 'placement_id' );
	$group_id     = (int) $request->get_param( 'group_id' );
	$action       = (string) $request->get_param( 'action' );

	// Verify referenced posts exist as the correct published CPT.
	// (Schema validates type/range; this guards against IDs belonging to wrong post types.)
	if ( get_post_type( $ad_id ) !== 'gq_ad' || get_post_status( $ad_id ) !== 'publish' ) {
		return new WP_REST_Response( [ 'success' => false, 'error' => 'invalid_ad' ], 400 );
	}

	if ( get_post_type( $placement_id ) !== 'gq_ad_placement' || get_post_status( $placement_id ) !== 'publish' ) {
		return new WP_REST_Response( [ 'success' => false, 'error' => 'invalid_placement' ], 400 );
	}

	if ( $group_id > 0 && ( get_post_type( $group_id ) !== 'gq_ad_group' || get_post_status( $group_id ) !== 'publish' ) ) {
		return new WP_REST_Response( [ 'success' => false, 'error' => 'invalid_group' ], 400 );
	}

	$table = $wpdb->prefix . 'gq_ad_stats';
	$today = current_time( 'Y-m-d' );

	$column = $action === 'impression' ? 'impressions' : 'clicks';
	$values = $action === 'impression' ? [ 1, 0 ] : [ 0, 1 ];

	// Atomic upsert — UNIQUE KEY (ad_id, placement_id, group_id, stat_date) prevents races.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe, wpdb->prefix is WordPress-controlled.
	$result = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table} (ad_id, placement_id, group_id, stat_date, impressions, clicks)
			VALUES (%d, %d, %d, %s, %d, %d)
			ON DUPLICATE KEY UPDATE {$column} = {$column} + 1",
			$ad_id, $placement_id, $group_id, $today, $values[0], $values[1]
		)
	);

	if ( false === $result ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $wpdb->last_error ) {
			gq_ads_safe_error_log( "Database error tracking {$action}", $wpdb->last_error );
		}
		return new WP_REST_Response( [ 'success' => false, 'error' => 'database_error' ], 500 );
	}

	$response = new WP_REST_Response( [ 'success' => true ], 200 );
	// Prevent CDNs and proxies from caching tracking requests.
	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );
	$response->header( 'Pragma', 'no-cache' );
	return $response;
}
