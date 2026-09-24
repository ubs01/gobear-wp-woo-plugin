<?php
/**
 * The "Fulfillment Queue" admin list table: every Gobear-personalized order line item, its
 * current status, and a "Fulfill" action for anything still waiting on a device assignment.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Gobear_Fulfillment_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'gobear_fulfillment',
				'plural'   => 'gobear_fulfillments',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'order'        => __( 'Order', 'gobear-personalization' ),
			'design'       => __( 'Design', 'gobear-personalization' ),
			'machine_type' => __( 'Device Family', 'gobear-personalization' ),
			'status'       => __( 'Status', 'gobear-personalization' ),
			'device'       => __( 'Device', 'gobear-personalization' ),
			'created'      => __( 'Ordered', 'gobear-personalization' ),
			'actions'      => __( 'Actions', 'gobear-personalization' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'created' => array( 'created_at', true ),
		);
	}

	public function get_status_counts() {
		global $wpdb;
		$table = $wpdb->prefix . 'gobear_fulfillments';
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) as c FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['c'];
		}
		return $counts;
	}

	public function prepare_items() {
		global $wpdb;

		$fulfillments = $wpdb->prefix . 'gobear_fulfillments';
		$designs      = $wpdb->prefix . 'gobear_designs';

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$status       = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$where  = '';
		$params = array();
		if ( $status ) {
			$where    = 'WHERE f.status = %s';
			$params[] = $status;
		}

		$order_by = 'f.created_at DESC';
		if ( isset( $_GET['orderby'] ) && 'created_at' === $_GET['orderby'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$dir      = ( isset( $_GET['order'] ) && 'asc' === $_GET['order'] ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_by = "f.created_at {$dir}";
		}

		$count_sql = "SELECT COUNT(*) FROM {$fulfillments} f {$where}";
		$total     = $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$offset = ( $current_page - 1 ) * $per_page;

		$sql = "SELECT f.*, d.model_name, d.sku_name, d.rendering_attachment_id, d.print_attachment_id
				FROM {$fulfillments} f
				LEFT JOIN {$designs} d ON d.id = f.design_id
				{$where}
				ORDER BY {$order_by}
				LIMIT %d OFFSET %d";

		$query_params   = $params;
		$query_params[] = $per_page;
		$query_params[] = $offset;

		$this->items = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->set_pagination_args(
			array(
				'total_items' => (int) $total,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	public function no_items() {
		esc_html_e( 'No personalized orders yet.', 'gobear-personalization' );
	}

	protected function column_order( $item ) {
		$order = wc_get_order( $item['order_id'] );
		if ( ! $order ) {
			return sprintf( '#%d', (int) $item['order_id'] );
		}
		return sprintf(
			'<a href="%s">#%s</a><br /><span class="description">%s</span>',
			esc_url( $order->get_edit_order_url() ),
			esc_html( $order->get_order_number() ),
			esc_html( wc_get_order_status_name( $order->get_status() ) )
		);
	}

	protected function column_design( $item ) {
		$thumb = $item['rendering_attachment_id'] ? wp_get_attachment_image( $item['rendering_attachment_id'], array( 48, 48 ) ) : '';
		return sprintf(
			'<div class="gobear-design-cell">%s<div><strong>%s</strong><br />%s</div></div>',
			$thumb,
			esc_html( $item['model_name'] ),
			esc_html( $item['sku_name'] )
		);
	}

	protected function column_machine_type( $item ) {
		return esc_html( $item['machine_type'] );
	}

	protected function column_status( $item ) {
		$labels = array(
			'pending'       => __( 'Awaiting device assignment', 'gobear-personalization' ),
			'sent'          => __( 'Sent to device', 'gobear-personalization' ),
			'in_production' => __( 'In production', 'gobear-personalization' ),
			'completed'     => __( 'Completed', 'gobear-personalization' ),
			'failed'        => __( 'Failed', 'gobear-personalization' ),
			'refunded'      => __( 'Refunded', 'gobear-personalization' ),
			'cancelled'     => __( 'Cancelled', 'gobear-personalization' ),
		);
		$label = isset( $labels[ $item['status'] ] ) ? $labels[ $item['status'] ] : $item['status'];
		return '<span class="gobear-status gobear-status-' . esc_attr( $item['status'] ) . '">' . esc_html( $label ) . '</span>'
			. ( $item['last_error'] ? '<br /><span class="description">' . esc_html( $item['last_error'] ) . '</span>' : '' );
	}

	protected function column_device( $item ) {
		return $item['device_sn'] ? esc_html( $item['device_sn'] ) : '&mdash;';
	}

	protected function column_created( $item ) {
		return esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['created_at'] ) );
	}

	protected function column_actions( $item ) {
		$base_url = admin_url( 'admin.php?page=' . Gobear_Fulfillment_Admin::MENU_SLUG );

		if ( 'pending' === $item['status'] || 'failed' === $item['status'] ) {
			return sprintf(
				'<a class="button button-primary" href="%s">%s</a>',
				esc_url( add_query_arg( array( 'action' => 'fulfill', 'id' => $item['id'] ), $base_url ) ),
				esc_html__( 'Fulfill', 'gobear-personalization' )
			);
		}

		if ( in_array( $item['status'], array( 'sent', 'in_production' ), true ) ) {
			return sprintf(
				'<a class="button" href="%s">%s</a>',
				esc_url( add_query_arg( array( 'action' => 'fulfill', 'id' => $item['id'] ), $base_url ) ),
				esc_html__( 'View / Manage', 'gobear-personalization' )
			);
		}

		return '&mdash;';
	}

	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}
}
