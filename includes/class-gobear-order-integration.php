<?php
/**
 * Bridges the cart's design data into the WooCommerce order: carries design references onto
 * order line items, creates a "pending fulfillment" record per personalized item, and registers
 * a custom "Personalizing" order status used once staff dispatch a job to a device.
 *
 * Deliberately does NOT call the Gobear API here — dispatching to a device is a separate,
 * explicit back-office action (see Gobear_Fulfillment_Admin).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gobear_Order_Integration {

	const ORDER_STATUS = 'wc-personalizing';

	public function init() {
		add_action( 'init', array( $this, 'register_order_status' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_order_status_to_list' ) );

		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_item_meta' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'create_fulfillment_rows' ) );
		// The Checkout block (Store API) never fires the classic hook above.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'create_fulfillment_rows' ) );
	}

	public function register_order_status() {
		register_post_status(
			self::ORDER_STATUS,
			array(
				'label'                     => _x( 'Personalizing', 'Order status', 'gobear-personalization' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders. */
				'label_count'               => _n_noop( 'Personalizing <span class="count">(%s)</span>', 'Personalizing <span class="count">(%s)</span>', 'gobear-personalization' ),
			)
		);
	}

	public function add_order_status_to_list( $order_statuses ) {
		$new_statuses = array();
		foreach ( $order_statuses as $key => $label ) {
			$new_statuses[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$new_statuses[ self::ORDER_STATUS ] = _x( 'Personalizing', 'Order status', 'gobear-personalization' );
			}
		}
		if ( ! isset( $new_statuses[ self::ORDER_STATUS ] ) ) {
			$new_statuses[ self::ORDER_STATUS ] = _x( 'Personalizing', 'Order status', 'gobear-personalization' );
		}
		return $new_statuses;
	}

	/**
	 * Copies the design reference from the cart item onto the order line item: a couple of
	 * customer-visible fields ("Phone", "Case") plus underscore-prefixed internal fields
	 * (hidden from the storefront/emails by WooCommerce's own convention).
	 */
	public function add_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['gobear_design'] ) ) {
			return;
		}
		$design = $values['gobear_design'];

		$item->add_meta_data( __( 'Phone', 'gobear-personalization' ), $design['model_name'] );
		$item->add_meta_data( __( 'Case', 'gobear-personalization' ), $design['sku_name'] );

		$item->add_meta_data( '_gobear_design_id', $design['design_id'] );
		$item->add_meta_data( '_gobear_model_id', $design['model_id'] );
		$item->add_meta_data( '_gobear_sku_id', $design['sku_id'] );
		$item->add_meta_data( '_gobear_machine_type', $design['machine_type'] );
	}

	/**
	 * Fires once the order (and its items, with real item IDs) has been saved — from either the
	 * classic checkout or the Checkout block. Creates one
	 * "pending" fulfillment row per personalized line item — this is the hold point: nothing is
	 * sent to Gobear until staff act on it in the Fulfillment Queue.
	 */
	public function create_fulfillment_rows( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		global $wpdb;
		$fulfillments_table = $wpdb->prefix . 'gobear_fulfillments';
		$designs_table      = $wpdb->prefix . 'gobear_designs';
		$created            = 0;

		foreach ( $order->get_items() as $item_id => $item ) {
			$design_id = $item->get_meta( '_gobear_design_id', true );
			if ( ! $design_id ) {
				continue;
			}

			// Idempotent: a checkout retry / duplicate hook firing shouldn't create two rows.
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$fulfillments_table} WHERE order_item_id = %d", $item_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			if ( $exists ) {
				continue;
			}

			$machine_type = $item->get_meta( '_gobear_machine_type', true );
			$now          = current_time( 'mysql', true );

			$wpdb->insert(
				$fulfillments_table,
				array(
					'order_id'      => $order->get_id(),
					'order_item_id' => $item_id,
					'design_id'     => (int) $design_id,
					'machine_type'  => $machine_type,
					'status'        => 'pending',
					'created_at'    => $now,
					'updated_at'    => $now,
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
			);

			$wpdb->update(
				$designs_table,
				array( 'order_item_id' => $item_id ),
				array( 'id' => (int) $design_id ),
				array( '%d' ),
				array( '%d' )
			);

			$created++;
		}

		if ( $created > 0 ) {
			$order->add_order_note(
				sprintf(
					/* translators: %d: number of personalized items. */
					_n(
						'%d personalized item is awaiting device assignment (Gobear Personalization).',
						'%d personalized items are awaiting device assignment (Gobear Personalization).',
						$created,
						'gobear-personalization'
					),
					$created
				)
			);
		}
	}
}
