<?php
/**
 * Shared contract implemented by every Gobear device-family driver, so the fulfillment UI can
 * work with GBK77/GBK114 ("printer") and K20 ("deskprinter") devices polymorphically.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Gobear_Driver_Interface {

	/**
	 * Machine types this driver handles, e.g. array( 'GBK77', 'GBK114' ) or array( 'K20' ).
	 *
	 * @return string[]
	 */
	public function get_machine_types();

	/**
	 * Whether this device family exposes per-device storage-slot stock info. K20 desk printers
	 * do not, so the fulfillment UI should skip the slot-stock check for them.
	 *
	 * @return bool
	 */
	public function supports_slots();

	/**
	 * Whether this device family supports manually forcing an abnormal order to "complete".
	 *
	 * @return bool
	 */
	public function supports_manual_complete();

	/**
	 * doc 3.1 / 6.1 Get device list.
	 *
	 * @param array $args Optional filters: device_sn, merch_id, status, run_status, page, pagesize.
	 * @return array|WP_Error
	 */
	public function list_devices( $args = array() );

	/**
	 * doc 3.3 Get device slot info (printer family only).
	 *
	 * @param string $device_sn
	 * @return array|WP_Error
	 */
	public function get_device_storage( $device_sn );

	/**
	 * doc 3.15 / 6.3 Get all phone configs and template files (brand -> series -> model ->
	 * material -> sku, with base_map template images).
	 *
	 * @param int|null $merch_id
	 * @return array|WP_Error
	 */
	public function get_phone_configs( $merch_id = null );

	/**
	 * doc 3.4 / 6.4 Get sales order list.
	 *
	 * @param array $args
	 * @return array|WP_Error
	 */
	public function get_order_list( $args = array() );

	/**
	 * doc 3.12 / 6.6 Get order info / detail.
	 *
	 * @param int $order_id Gobear's own order id.
	 * @return array|WP_Error
	 */
	public function get_order_detail( $order_id );

	/**
	 * doc 3.9 / 6.5 Create a print order. This is the call that pushes the job onto the physical
	 * device's production queue — only call it once staff have deliberately chosen a device.
	 *
	 * @param int    $model_id        phone_model_id.
	 * @param int    $sku_id          Consumable SKU id.
	 * @param int    $device_id       Gobear device id (not the device_sn).
	 * @param string $image_url       Public HTTPS URL of the print-ready image.
	 * @param string $rendering_url   Public HTTPS URL of the preview/rendering image.
	 * @return array|WP_Error
	 */
	public function create_order( $model_id, $sku_id, $device_id, $image_url, $rendering_url );

	/**
	 * doc 3.13 Update order status to complete (printer family only; used to recover abnormal
	 * orders). Drivers that don't support this should return a WP_Error.
	 *
	 * @param int $order_id
	 * @return array|WP_Error
	 */
	public function complete_order( $order_id );
}
