<?php
/**
 * Handles plugin activation/deactivation: custom DB tables and default options.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gobear_Installer {

	const DB_VERSION_OPTION = 'gobear_personalization_db_version';
	const DB_VERSION        = '1.0.0';

	/**
	 * Runs on plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		self::maybe_set_default_settings();

		if ( ! wp_next_scheduled( Gobear_Cron::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'gobear_fifteen_minutes', Gobear_Cron::CRON_HOOK );
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Runs on plugin deactivation. We intentionally do NOT drop tables or settings here so a
	 * temporary deactivation (e.g. during a WordPress update) doesn't lose fulfillment history.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Gobear_Cron::CRON_HOOK );
	}

	/**
	 * Creates (or upgrades) the two custom tables this plugin relies on.
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$designs_table      = $wpdb->prefix . 'gobear_designs';
		$fulfillments_table = $wpdb->prefix . 'gobear_fulfillments';

		$sql = "CREATE TABLE {$designs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cart_item_key VARCHAR(191) NULL,
			order_item_id BIGINT UNSIGNED NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			brand_id BIGINT UNSIGNED NULL,
			brand_name VARCHAR(191) NULL,
			model_id BIGINT UNSIGNED NOT NULL,
			model_name VARCHAR(191) NULL,
			machine_type VARCHAR(20) NOT NULL,
			sku_id BIGINT UNSIGNED NOT NULL,
			sku_name VARCHAR(191) NULL,
			print_attachment_id BIGINT UNSIGNED NULL,
			rendering_attachment_id BIGINT UNSIGNED NULL,
			design_meta LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY cart_item_key (cart_item_key),
			KEY order_item_id (order_item_id)
		) {$charset_collate};";

		$sql .= "CREATE TABLE {$fulfillments_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			order_item_id BIGINT UNSIGNED NOT NULL,
			design_id BIGINT UNSIGNED NOT NULL,
			machine_type VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			gobear_order_id BIGINT UNSIGNED NULL,
			gobear_order_sn VARCHAR(64) NULL,
			device_id BIGINT UNSIGNED NULL,
			device_sn VARCHAR(64) NULL,
			sent_by_user_id BIGINT UNSIGNED NULL,
			sent_at DATETIME NULL,
			completed_at DATETIME NULL,
			last_error TEXT NULL,
			snapshots LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY order_item_id (order_item_id),
			KEY status (status),
			KEY gobear_order_id (gobear_order_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Seeds the settings option with sane defaults the first time the plugin activates, without
	 * clobbering an existing configuration on re-activation.
	 */
	private static function maybe_set_default_settings() {
		if ( false !== get_option( Gobear_Settings::OPTION_KEY, false ) ) {
			return;
		}

		update_option(
			Gobear_Settings::OPTION_KEY,
			array(
				'app_key'          => '',
				'app_secret'       => '',
				'api_base_url'     => 'https://saas-cdn.gobear8.com',
				'merch_id'         => '',
				'app_type'         => '',
				'accept_language'  => 'en-US',
				'stale_job_hours'  => 6,
				'catalog_cache_ttl' => HOUR_IN_SECONDS,
			)
		);
	}
}
