<?php
/**
 * Driver for the K20 "deskprinter" device family. Unlike GBK77/GBK114, K20 has no physical
 * storage-slot concept and no manual "force complete" endpoint in the API docs, so those
 * capabilities are reported as unsupported rather than guessed at.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gobear_Deskprinter_Driver implements Gobear_Driver_Interface {

	const PREFIX = '/addons/deskprinter/openapi';

	/** @var Gobear_Client */
	private $client;

	public function __construct( Gobear_Client $client ) {
		$this->client = $client;
	}

	public function get_machine_types() {
		return array( 'K20' );
	}

	public function supports_slots() {
		return false;
	}

	public function supports_manual_complete() {
		return false;
	}

	/** doc 6.1 Get device list. */
	public function list_devices( $args = array() ) {
		return $this->client->get( self::PREFIX . '.device/list', $args );
	}

	public function get_device_storage( $device_sn ) {
		return new WP_Error( 'gobear_not_supported', __( 'K20 devices do not expose per-slot stock; check the case is loaded on the device itself.', 'gobear-personalization' ) );
	}

	/** doc 6.2 Get sku list and suitable phones. */
	public function get_sku_match() {
		return $this->client->get( self::PREFIX . '.sku/match' );
	}

	/** doc 6.3 Get all phone configs and template files. */
	public function get_phone_configs( $merch_id = null ) {
		$args = array();
		if ( null !== $merch_id ) {
			$args['merch_id'] = (int) $merch_id;
		}
		return $this->client->get( self::PREFIX . '.phone/getList', $args );
	}

	/** doc 6.4 Get sales order list. */
	public function get_order_list( $args = array() ) {
		return $this->client->get( self::PREFIX . '.order/list', $args );
	}

	/** doc 6.6 Get order detail. */
	public function get_order_detail( $order_id ) {
		return $this->client->get( self::PREFIX . '.order/detail', array( 'order_id' => $order_id ) );
	}

	/** doc 6.5 Create a print order — pushes the job to the device's production queue. */
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

	public function complete_order( $order_id ) {
		return new WP_Error( 'gobear_not_supported', __( 'K20 does not support manually forcing an order to complete.', 'gobear-personalization' ) );
	}
}
