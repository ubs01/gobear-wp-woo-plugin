<?php
/**
 * Plugin Name:       Gobear Personalized Phone Cases for WooCommerce
 * Plugin URI:        https://example.com/gobear-phone-case-personalization
 * Description:       Lets customers design or upload artwork for a personalized phone case, checks out
 *                     normally through WooCommerce, then lets a store manager pick a Gobear device
 *                     (GBK77 / GBK114 printer or K20 desk printer) to dispatch the job for production
 *                     and vending.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 * Author:            Your Store
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gobear-personalization
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Do not access this file directly.
}

define( 'GOBEAR_PERSONALIZATION_VERSION', '1.0.0' );
define( 'GOBEAR_PERSONALIZATION_FILE', __FILE__ );
define( 'GOBEAR_PERSONALIZATION_DIR', plugin_dir_path( __FILE__ ) );
define( 'GOBEAR_PERSONALIZATION_URL', plugin_dir_url( __FILE__ ) );
define( 'GOBEAR_PERSONALIZATION_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility up front, before WooCommerce
 * finishes loading, per WooCommerce's own guidance.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				GOBEAR_PERSONALIZATION_FILE,
				true
			);
		}
	}
);

/**
 * Load every class file. WordPress plugins conventionally avoid Composer/PSR-4 autoloading for
 * a self-contained plugin like this one, so files are required explicitly in dependency order.
 */
function gobear_personalization_load_files() {
	$includes = GOBEAR_PERSONALIZATION_DIR . 'includes/';

	require_once $includes . 'class-gobear-installer.php';
	require_once $includes . 'class-gobear-client.php';
	require_once $includes . 'drivers/interface-gobear-driver.php';
	require_once $includes . 'drivers/class-gobear-printer-driver.php';
	require_once $includes . 'drivers/class-gobear-deskprinter-driver.php';
	require_once $includes . 'class-gobear-driver-factory.php';
	require_once $includes . 'class-gobear-catalog-cache.php';
	require_once $includes . 'class-gobear-image-compositor.php';
	require_once $includes . 'class-gobear-settings.php';
	require_once $includes . 'class-gobear-product-fields.php';
	require_once $includes . 'class-gobear-cart-integration.php';
	require_once $includes . 'class-gobear-order-integration.php';
	require_once $includes . 'class-gobear-fulfillment-sync.php';
	require_once $includes . 'class-gobear-fulfillment-list-table.php';
	require_once $includes . 'class-gobear-fulfillment-admin.php';
	require_once $includes . 'class-gobear-design-ajax.php';
	require_once $includes . 'class-gobear-webhook.php';
	require_once $includes . 'class-gobear-cron.php';
	require_once $includes . 'class-gobear-plugin.php';
}
gobear_personalization_load_files();

/**
 * Bail (with an admin notice) if WooCommerce isn't active, instead of fataling.
 */
function gobear_personalization_woocommerce_missing_notice() {
	echo '<div class="notice notice-error"><p>' .
		esc_html__( 'Gobear Personalized Phone Cases requires WooCommerce to be installed and active.', 'gobear-personalization' ) .
		'</p></div>';
}

function gobear_personalization_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'gobear_personalization_woocommerce_missing_notice' );
		return;
	}

	Gobear_Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'gobear_personalization_init', 20 );

register_activation_hook( __FILE__, array( 'Gobear_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Gobear_Installer', 'deactivate' ) );
