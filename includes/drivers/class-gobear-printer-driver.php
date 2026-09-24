<?php
/**
 * Driver for the GBK77 / GBK114 "printer" device family (folder "GBK77(114)" in the API docs).
 * These devices have physical storage slots pre-loaded with case SKUs, so we can check real
 * per-device stock before staff dispatch a job to one.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gobear_Printer_Driver implements Gobear_Driver_Interface {

	const PREFIX = '/addons/printer/openapi';

	/** @var Gobear_Client */
	private $client;

	public function __construct( Gobear_Client $client ) {
		$this->client = $client;
	}

	public function get_machine_types() {
		return array( 'GBK77', 'GBK114' );
	}

	public function supports_slots() {
		return true;
	}

	public function supports_manual_complete() {
		return true;
	}

	/** doc 3.1 Get device list. */
	public function list_devices( $args = array() ) {
		return $this->client->get( self::PREFIX . '.device/list', $args );
	}

	/** doc 3.3 Get device slot info. */
	public function get_device_storage( $device_sn ) {
		return $this->client->get( self::PREFIX . '.device/storage', array( 'device_sn' => $device_sn ) );
	}

	/** doc 3.2 Get sku list and suitable phones. */
	public function get_sku_match() {
		return $this->client->get( self::PREFIX . '.sku/match' );
	}

	/** doc 3.15 Get all phone configs and template files. */
	public function get_phone_configs( $merch_id = null ) {
		$args = array();
		if ( null !== $merch_id ) {
			$args['merch_id'] = (int) $merch_id;
		}
		return $this->client->get( self::PREFIX . '.phone/getList', $args );
	}

	/** doc 3.4 Get sales order list. */
	public function get_order_list( $args = array() ) {
		return $this->client->get( self::PREFIX . '.order/list', $args );
	}

	/** doc 3.12 Get order info. */
	public function get_order_detail( $order_id ) {
		return $this->client->get( self::PREFIX . '.order/detail', array( 'order_id' => $order_id ) );
	}

	/** doc 3.9 Create a print order — pushes the job to the device's production queue. */
	public function create_order( $model_id, $sku_id, $device_id, $image_url, $rendering_url ) {
		return $this->client->post(
			self::PREFIX . '.order/create',
			array(
				'model_id'        => (int) $model_id,
				'sku_id'          => (int) $sku_id,
				'device_id'       => (int) $device_id,
				'image'           => $image_url,
				'rendering_image' => $rendering_url,
			)
		);
	}

	/** doc 3.13 Update order status to complete. */
	public function complete_order( $order_id ) {
		return $this->client->post( self::PREFIX . '.order/complete', array( 'order_id' => (int) $order_id ) );
	}

	/** doc 3.14 Remove sku from storage slots (batch), exposed for completeness/future admin tooling. */
	public function remove_sku_from_slots( $device_id, array $storage_box_names ) {
		return $this->client->post(
			self::PREFIX . '.consumable/removeSku',
			array(
				'device_id'         => (int) $device_id,
				'storage_box_names' => wp_json_encode( array_values( $storage_box_names ) ),
			)
		);
	}
}
