<?php

declare(strict_types=1);

/**
 * Plugin Name:       GQ Ads Manager
 * Description:       Ad placements with category targeting, campaign scheduling, and impression/click tracking for GQ Magazine.
 * Author:            Chillybin Web Design
 * Author URI:        https://www.chillybin.co
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Tested up to:      6.8
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gq-ads
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GQ_ADS_PLUGIN_FILE', __FILE__ );
define( 'GQ_ADS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GQ_ADS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GQ_ADS_VERSION',    '1.0.0' );
define( 'GQ_ADS_DB_VERSION', '0.1.0' );

// Core includes.
require_once GQ_ADS_PLUGIN_DIR . 'includes/enum-ad-type.php';
require_once GQ_ADS_PLUGIN_DIR . 'includes/class-gq-ads-activator.php';
require_once GQ_ADS_PLUGIN_DIR . 'includes/post-types.php';
require_once GQ_ADS_PLUGIN_DIR . 'includes/adsense.php';
require_once GQ_ADS_PLUGIN_DIR . 'includes/render.php';
require_once GQ_ADS_PLUGIN_DIR . 'includes/rest.php';
if ( is_admin() ) {
	require_once GQ_ADS_PLUGIN_DIR . 'includes/meta-boxes.php';
	require_once GQ_ADS_PLUGIN_DIR . 'includes/admin-stats.php';
}

// Activation: create stats table etc.
register_activation_hook( GQ_ADS_PLUGIN_FILE, [ 'GQ_Ads_Activator', 'activate' ] );

// Uninstall: remove DB table and options when plugin is deleted.
register_uninstall_hook( GQ_ADS_PLUGIN_FILE, [ 'GQ_Ads_Activator', 'uninstall' ] );

// DB upgrade: runs once on each page load until the stored version matches.
add_action( 'plugins_loaded', [ 'GQ_Ads_Activator', 'maybe_upgrade' ] );

// Load translations.
add_action( 'plugins_loaded', function (): void {
	load_plugin_textdomain( 'gq-ads', false, dirname( plugin_basename( GQ_ADS_PLUGIN_FILE ) ) . '/languages' );
} );

// Register CPTs.
add_action( 'init', 'gq_ads_register_post_types' );

// Shortcode: [gq_ad placement="leaderboard"]
add_shortcode( 'gq_ad', 'gq_ads_shortcode' );

// Device-targeting CSS — enqueued as a cacheable stylesheet rather than inline.
add_action( 'wp_enqueue_scripts', 'gq_ads_enqueue_frontend_styles' );

function gq_ads_enqueue_frontend_styles(): void {
	wp_enqueue_style(
		'gq-ads',
		GQ_ADS_PLUGIN_URL . 'assets/css/frontend.css',
		[],
		GQ_ADS_VERSION
	);
}
