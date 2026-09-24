<?php
/**
 * Shared logic for updating a fulfillment record's status — used by the manual "Send to Device" /
 * "Check Status" admin actions, the incoming webhook, and the cron safety-net poller, so all three
 * agree on exactly how a Gobear order/detail payload or webhook event maps onto our own status.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gobear_Fulfillment_Sync {

	/**
	 * Gobear "status": 1 Pending Payment, 2 In Production, 3 Completed, 4 Cancelled, 5 Closed.
	 * Gobear "produce_status": 1 Pending Production, 2 In Production, 3 Insufficient Stock,
	 * 4 Device Error, 5 Production Completed.
	 *
	 * @param array $detail Result of driver->get_order_detail().
	 * @return array { status: string, error: string|null }
	 */
	public static function map_order_detail_to_status( $detail ) {
		$order_status   = isset( $detail['status'] ) ? (int) $detail['status'] : null;
		$produce_status = isset( $detail['produce_status'] ) ? (int) $detail['produce_status'] : null;
		$error_code     = isset( $detail['error_code'] ) ? $detail['error_code'] : null;

		if ( 5 === $produce_status || 3 === $order_status ) {
			return array( 'status' => 'completed', 'error' => null );
		}

		if ( in_array( $produce_status, array( 3, 4 ), true ) ) {
			$labels = array(
				3 => __( 'Insufficient stock on device.', 'gobear-personalization' ),
				4 => __( 'Device error.', 'gobear-personalization' ),
			);
			return array( 'status' => 'failed', 'error' => $error_code ? $error_code : $labels[ $produce_status ] );
		}

		if ( 4 === $order_status ) {
			return array( 'status' => 'failed', 'error' => __( 'Order cancelled/refunded by Gobear.', 'gobear-personalization' ) );
		}

		if ( 5 === $order_status ) {
			return array( 'status' => 'failed', 'error' => __( 'Order closed unpaid.', 'gobear-personalization' ) );
		}

		if ( in_array( $produce_status, array( 1, 2 ), true ) ) {
			return array( 'status' => 'in_production', 'error' => null );
		}

		return array( 'status' => 'sent', 'error' => null );
	}

	public static function map_webhook_event_to_status( $event_type ) {
		switch ( $event_type ) {
			case 'ORDER_SUCCESS':
				return 'completed';
			case 'ORDER_REFUND':
				return 'refunded';
			case 'ORDER_FAILED':
				return 'failed';
			default:
				return null;
		}
	}

	/**
	 * Applies a new status (+ optional snapshots/error/gobear order id) to a fulfillment row and
	 * mirrors it onto the linked WooCommerce order as a note (and, once the job is finished, an
	 * order status bump).
	 *
	 * @param object $fulfillment Row from wp_gobear_fulfillments.
	 * @param string $status
	 * @param array  $extra { error?: string, snapshots?: array }
	 */
	public static function apply_status( $fulfillment, $status, $extra = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'gobear_fulfillments';

		if ( $fulfillment->status === $status ) {
			return; // No-op; avoid duplicate notes on repeated pokes (webhook retries, cron re-poll).
		}

		$data   = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		);
		$format = array( '%s', '%s' );

		if ( ! empty( $extra['error'] ) ) {
			$data['last_error'] = $extra['error'];
			$format[]           = '%s';
		}
		if ( ! empty( $extra['snapshots'] ) ) {
			$data['snapshots'] = wp_json_encode( $extra['snapshots'] );
			$format[]          = '%s';
		}
		if ( in_array( $status, array( 'completed', 'failed', 'refunded' ), true ) ) {
			$data['completed_at'] = current_time( 'mysql', true );
			$format[]             = '%s';
		}

		$wpdb->update( $table, $data, array( 'id' => $fulfillment->id ), $format, array( '%d' ) );

		$order = wc_get_order( $fulfillment->order_id );
		if ( ! $order ) {
			return;
		}

		$notes = array(
			'in_production' => __( 'Gobear: device has started producing the personalized case.', 'gobear-personalization' ),
			'completed'     => __( 'Gobear: personalized case production completed.', 'gobear-personalization' ),
			'failed'        => __( 'Gobear: production failed.', 'gobear-personalization' ) . ( ! empty( $extra['error'] ) ? ' ' . $extra['error'] : '' ),
			'refunded'      => __( 'Gobear: order was refunded on the device side.', 'gobear-personalization' ),
		);

		if ( isset( $notes[ $status ] ) ) {
			$order->add_order_note( $notes[ $status ] );
		}

		if ( 'completed' === $status && self::all_items_finished( $fulfillment->order_id ) ) {
			if ( $order->has_status( array( 'personalizing', 'processing' ) ) ) {
				$order->update_status( 'completed', __( 'All personalized items produced by Gobear.', 'gobear-personalization' ) );
			}
		}
	}

	/**
	 * Whether every Gobear-fulfillment line item on an order has reached a terminal state
	 * (completed/failed/refunded), used to decide whether it's safe to auto-complete the order.
	 */
	private static function all_items_finished( $order_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'gobear_fulfillments';

		$pending = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND status NOT IN ('completed','failed','refunded','cancelled')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_id
			)
		);

		return 0 === (int) $pending;
	}

	/**
	 * Fetches a fulfillment row by its Gobear order id (used by the webhook, which only gives us
	 * Gobear's own order_id, not our internal row id).
	 *
	 * @param int $gobear_order_id
	 * @return object|null
	 */
	public static function find_by_gobear_order_id( $gobear_order_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'gobear_fulfillments';
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE gobear_order_id = %d", $gobear_order_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	public static function find( $fulfillment_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'gobear_fulfillments';
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $fulfillment_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
