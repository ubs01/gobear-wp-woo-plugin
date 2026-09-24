<?php
/**
 * Main orchestrator: instantiates and wires up every feature class. Only run once WooCommerce is
 * confirmed active (see gobear-personalization.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gobear_Plugin {

	/** @var Gobear_Plugin */
	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		load_plugin_textdomain( 'gobear-personalization', false, dirname( GOBEAR_PERSONALIZATION_BASENAME ) . '/languages' );

		// Tables may need creating/upgrading (e.g. after an update that changes the schema);
		// activation already does this, but this keeps things self-healing if activation was
		// somehow skipped (multisite network activation edge cases, manual file copy, etc.).
		if ( get_option( Gobear_Installer::DB_VERSION_OPTION ) !== Gobear_Installer::DB_VERSION ) {
			Gobear_Installer::create_tables();
			update_option( Gobear_Installer::DB_VERSION_OPTION, Gobear_Installer::DB_VERSION );
		}

		( new Gobear_Settings() )->init();
		( new Gobear_Product_Fields() )->init();
		( new Gobear_Cart_Integration() )->init();
		( new Gobear_Order_Integration() )->init();
		( new Gobear_Fulfillment_Admin() )->init();
		( new Gobear_Design_Ajax() )->init();
		( new Gobear_Webhook() )->init();
		( new Gobear_Cron() )->init();
	}
}
