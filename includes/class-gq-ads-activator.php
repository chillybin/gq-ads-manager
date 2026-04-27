<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GQ_Ads_Activator {

	public static function activate(): void {
		self::create_stats_table();
	}

	/**
	 * Run on every `plugins_loaded` — only executes when the stored DB version
	 * is behind the current GQ_ADS_DB_VERSION constant.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'gq_ads_db_version' ) === GQ_ADS_DB_VERSION ) {
			return;
		}
		self::create_stats_table(); // dbDelta handles ALTER TABLE safely.
	}

	/**
	 * Called by register_uninstall_hook when the plugin is deleted via the WP admin.
	 * Removes the stats table and all plugin options.
	 */
	public static function uninstall(): void {
		global $wpdb;

		// Only remove data when the site admin has explicitly chosen to delete the plugin.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'gq_ad_stats' );

		delete_option( 'gq_ads_db_version' );
		delete_transient( 'gq_ads_adsense_publisher_id' );
	}

	protected static function create_stats_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'gq_ad_stats';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ad_id bigint(20) unsigned NOT NULL,
			placement_id bigint(20) unsigned NOT NULL,
			group_id bigint(20) unsigned NOT NULL DEFAULT 0,
			stat_date date NOT NULL,
			impressions bigint(20) unsigned NOT NULL DEFAULT 0,
			clicks bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY ad_date_placement_group (ad_id, placement_id, group_id, stat_date),
			KEY stat_date (stat_date)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'gq_ads_db_version', GQ_ADS_DB_VERSION );
	}
}
