<?php

declare(strict_types=1);

/**
 * Plugin uninstall routine.
 *
 * WordPress executes this file when an administrator deletes the plugin
 * via the Plugins screen. It runs in the context of the main plugin file's
 * directory, so GQ_ADS_PLUGIN_FILE is not yet defined here.
 *
 * Note: deactivating the plugin does NOT run this file.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // Direct access not allowed.
}

global $wpdb;

// Drop the stats table.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'gq_ad_stats' );

// Remove plugin options.
delete_option( 'gq_ads_db_version' );
delete_transient( 'gq_ads_adsense_publisher_id' );
