<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backed enum representing the three supported ad creative types.
 *
 * Values correspond directly to the string stored in the `gq_ad_type` post meta key.
 * Cases are ordered by frequency of use (HTML/JS first) so admin radio buttons render
 * in a sensible order when iterating GQ_Ad_Type::cases().
 */
enum GQ_Ad_Type: string {
	case HTML       = 'html';
	case ADSENSE    = 'adsense';
	case IMAGE_LINK = 'image_link';

	/**
	 * Human-readable admin label for this ad type.
	 *
	 * Delegates i18n to the call site so callers that don't need translated
	 * strings (e.g. REST validation) don't pay the __() overhead.
	 */
	public function label(): string {
		return match ( $this ) {
			self::HTML       => __( 'HTML / JavaScript', 'gq-ads' ),
			self::ADSENSE    => __( 'Google AdSense', 'gq-ads' ),
			self::IMAGE_LINK => __( 'Image + Link', 'gq-ads' ),
		};
	}

	/**
	 * Whether click tracking is allowed for this type.
	 *
	 * AdSense prohibits publisher-side click tracking per its Terms of Service.
	 */
	public function allowsClickTracking(): bool {
		return $this !== self::ADSENSE;
	}

	/**
	 * Safely coerce a post-meta value to a GQ_Ad_Type instance.
	 *
	 * Returns null for empty or unrecognised values so callers can bail out
	 * cleanly rather than hitting an unhandled match arm.
	 */
	public static function fromMeta( mixed $value ): ?self {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		return self::tryFrom( $value );
	}
}
