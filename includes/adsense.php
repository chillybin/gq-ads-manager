<?php

declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load AdSense script once in footer if any adsense ads exist.
 */
add_action( 'wp_footer', 'gq_ads_maybe_load_adsense_script', 5 );

function gq_ads_maybe_load_adsense_script() {
	global $gq_ads_has_adsense;

	// Only load if we rendered at least one adsense ad on this page.
	if ( empty( $gq_ads_has_adsense ) ) {
		return;
	}

	// Get the publisher ID from settings or first adsense ad.
	$publisher_id = get_option( 'gq_ads_adsense_publisher_id', '' );

	if ( empty( $publisher_id ) ) {
		// Try to get from first adsense ad.
		$publisher_id = gq_ads_get_adsense_publisher_id();
	}

	if ( empty( $publisher_id ) ) {
		return;
	}

	?>
	<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=<?php echo esc_attr( $publisher_id ); ?>" crossorigin="anonymous"></script>
	<?php

}

/**
 * Get AdSense publisher ID from first adsense ad.
 *
 * @return string
 */
function gq_ads_get_adsense_publisher_id(): string {
	// Check transient first.
	$cached = get_transient( 'gq_ads_adsense_publisher_id' );
	if ( $cached !== false ) {
		return $cached;
	}

	// Find an adsense ad and extract publisher ID.
	$ads = get_posts( [
		'post_type'      => 'gq_ad',
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'meta_query'     => [
			[
				'key'   => 'gq_ad_type',
				'value' => 'adsense',
			],
		],
	] );

	if ( empty( $ads ) ) {
		return '';
	}

	$code = get_post_meta( $ads[0]->ID, 'gq_ad_code', true );

	// Extract data-ad-client from the code.
	if ( preg_match( '/data-ad-client=["\']([^"\']+)["\']/', $code, $matches ) ) {
		$publisher_id = $matches[1];
		set_transient( 'gq_ads_adsense_publisher_id', $publisher_id, DAY_IN_SECONDS );
		return $publisher_id;
	}

	return '';
}

/**
 * Mark that we need to load AdSense script.
 */
function gq_ads_flag_adsense_used() {
	global $gq_ads_has_adsense;
	$gq_ads_has_adsense = true;
}

/**
 * Render an AdSense ad unit from just the slot info.
 *
 * @param string $ad_code The ad unit code (just the <ins> tag, or full snippet).
 * @return string
 */
function gq_ads_render_adsense_unit( string $ad_code ): string {
	// Flag that we need the adsense script.
	gq_ads_flag_adsense_used();

	// If the code already contains just the <ins> tag, use it as-is.
	// If it contains the full snippet with <script>, extract just the <ins> part.
	if ( strpos( $ad_code, '<script' ) !== false ) {
		// Extract just the <ins> tag.
		if ( preg_match( '/<ins[^>]*>.*?<\/ins>/s', $ad_code, $matches ) ) {
			$ins_tag = $matches[0];
		} else {
			// Fallback: use as-is but strip script tags.
			$ins_tag = preg_replace( '/<script[^>]*>.*?<\/script>/s', '', $ad_code );
		}
	} else {
		$ins_tag = $ad_code;
	}

	// Add the push call after the ins tag.
	$output = $ins_tag . "\n" . '<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>';

	return $output;
}
