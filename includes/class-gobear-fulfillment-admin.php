<?php
/**
 * Back-office fulfillment: the "Fulfillment Queue" list screen, a per-order meta box, and the
 * AJAX endpoints that list devices (with live slot-stock checks) and dispatch a job to Gobear —
 * this is the only place in the plugin that calls the create-order API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gobear_Fulfillment_Admin {

	const MENU_SLUG    = 'gobear-fulfillment';
	const NONCE_ACTION = 'gobear_fulfillment_action';

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Renders on both the legacy (post-based) and HPOS order edit screens, which both fire
		// this same core WooCommerce hook from their order-details template partial.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_order_meta_box' ) );

		add_action( 'wp_ajax_gobear_list_devices', array( $this, 'ajax_list_devices' ) );
		add_action( 'wp_ajax_gobear_send_to_device', array( $this, 'ajax_send_to_device' ) );
		add_action( 'wp_ajax_gobear_mark_complete', array( $this, 'ajax_mark_complete' ) );
		add_action( 'wp_ajax_gobear_refresh_status', array( $this, 'ajax_refresh_status' ) );

		add_action( 'admin_post_gobear_backfill_fulfillments', array( $this, 'handle_backfill' ) );
	}

	/**
	 * "Scan for missing orders" button on the queue page: creates fulfillment rows for existing
	 * personalized orders that never got one, then redirects back with the result.
	 */
	public function handle_backfill() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'gobear-personalization' ), 403 );
		}
		check_admin_referer( 'gobear_backfill_fulfillments' );

		$created = ( new Gobear_Order_Integration() )->backfill_fulfillment_rows();

		wp_safe_redirect( add_query_arg( 'gobear_backfilled', $created, admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	public function add_menu() {
		add_submenu_page(
			Gobear_Settings::MENU_SLUG,
			__( 'Fulfillment Queue', 'gobear-personalization' ),
			__( 'Fulfillment Queue', 'gobear-personalization' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render_queue_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		$is_queue_page = ( strpos( $hook, self::MENU_SLUG ) !== false );
		$is_order_page = in_array( $hook, array( 'post.php', 'woocommerce_page_wc-orders' ), true );

		if ( ! $is_queue_page && ! $is_order_page ) {
			return;
		}

		wp_enqueue_style( 'gobear-admin', GOBEAR_PERSONALIZATION_URL . 'assets/css/admin.css', array(), GOBEAR_PERSONALIZATION_VERSION );
		wp_enqueue_script( 'gobear-admin-fulfillment', GOBEAR_PERSONALIZATION_URL . 'assets/js/admin-fulfillment.js', array( 'jquery' ), GOBEAR_PERSONALIZATION_VERSION, true );
		wp_localize_script(
			'gobear-admin-fulfillment',
			'GobearFulfillment',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'loadingDevices' => __( 'Loading devices…', 'gobear-personalization' ),
					'noDevices'      => __( 'No devices found for this device family.', 'gobear-personalization' ),
					'sending'        => __( 'Sending to device…', 'gobear-personalization' ),
					'sent'           => __( 'Sent! Gobear order:', 'gobear-personalization' ),
					'error'          => __( 'Something went wrong.', 'gobear-personalization' ),
					'noStock'        => __( 'No stock for this case in the selected device.', 'gobear-personalization' ),
					'confirmSend'    => __( 'Send this job to the selected device now? This will start production/vending immediately.', 'gobear-personalization' ),
				),
			)
		);
	}

	public function render_queue_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'fulfill' === $action && $id ) {
			$this->render_fulfill_screen( $id );
			return;
		}

		$table = new Gobear_Fulfillment_List_Table();
		$table->prepare_items();
		$counts = $table->get_status_counts();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Gobear Fulfillment Queue', 'gobear-personalization' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<input type="hidden" name="action" value="gobear_backfill_fulfillments" />
				<?php wp_nonce_field( 'gobear_backfill_fulfillments' ); ?>
				<button type="submit" class="page-title-action"><?php esc_html_e( 'Scan for missing orders', 'gobear-personalization' ); ?></button>
			</form>
			<hr class="wp-header-end" />
			<?php if ( isset( $_GET['gobear_backfilled'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php $backfilled = absint( $_GET['gobear_backfilled'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					echo esc_html(
						$backfilled
							/* translators: %d: number of fulfillment rows created. */
							? sprintf( _n( 'Added %d missing personalized item to the queue.', 'Added %d missing personalized items to the queue.', $backfilled, 'gobear-personalization' ), $backfilled )
							: __( 'No missing personalized orders found.', 'gobear-personalization' )
					);
					?>
				</p></div>
			<?php endif; ?>
			<ul class="subsubsub">
				<?php
				$base = admin_url( 'admin.php?page=' . self::MENU_SLUG );
				$all_url = esc_url( $base );
				$total = array_sum( $counts );
				echo '<li><a href="' . $all_url . '">' . esc_html__( 'All', 'gobear-personalization' ) . ' (' . (int) $total . ')</a> |</li> ';
				$labels = array(
					'pending'       => __( 'Pending', 'gobear-personalization' ),
					'sent'          => __( 'Sent', 'gobear-personalization' ),
					'in_production' => __( 'In Production', 'gobear-personalization' ),
					'completed'     => __( 'Completed', 'gobear-personalization' ),
					'failed'        => __( 'Failed', 'gobear-personalization' ),
					'refunded'      => __( 'Refunded', 'gobear-personalization' ),
				);
				$i = 0;
				foreach ( $labels as $key => $label ) {
					$i++;
					$count = isset( $counts[ $key ] ) ? $counts[ $key ] : 0;
					echo '<li><a href="' . esc_url( add_query_arg( 'status', $key, $base ) ) . '">' . esc_html( $label ) . '</a> (' . (int) $count . ')' . ( $i < count( $labels ) ? ' |' : '' ) . '</li> ';
				}
				?>
			</ul>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	private function render_fulfill_screen( $id ) {
		$fulfillment = Gobear_Fulfillment_Sync::find( $id );
		if ( ! $fulfillment ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Fulfillment record not found.', 'gobear-personalization' ) . '</p></div>';
			return;
		}

		global $wpdb;
		$design = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}gobear_designs WHERE id = %d", $fulfillment->design_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$order  = wc_get_order( $fulfillment->order_id );
		$driver = Gobear_Driver_Factory::create( $fulfillment->machine_type );

		$back_url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		?>
		<div class="wrap gobear-fulfill-screen">
			<h1><?php esc_html_e( 'Fulfill Personalized Case', 'gobear-personalization' ); ?></h1>
			<p><a href="<?php echo esc_url( $back_url ); ?>">&larr; <?php esc_html_e( 'Back to queue', 'gobear-personalization' ); ?></a></p>

			<div class="gobear-fulfill-summary">
				<?php if ( $design && $design->rendering_attachment_id ) : ?>
					<?php echo wp_get_attachment_image( $design->rendering_attachment_id, 'medium' ); ?>
				<?php endif; ?>
				<table class="widefat striped" style="max-width:600px;">
					<tbody>
						<tr><th><?php esc_html_e( 'Order', 'gobear-personalization' ); ?></th><td><?php echo $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a> (' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . ')' : esc_html__( 'Unknown', 'gobear-personalization' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Phone', 'gobear-personalization' ); ?></th><td><?php echo esc_html( $design->model_name ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Case', 'gobear-personalization' ); ?></th><td><?php echo esc_html( $design->sku_name ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Device Family', 'gobear-personalization' ); ?></th><td><?php echo esc_html( $fulfillment->machine_type ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Status', 'gobear-personalization' ); ?></th><td><span id="gobear-current-status"><?php echo esc_html( $fulfillment->status ); ?></span></td></tr>
						<?php if ( $fulfillment->gobear_order_sn ) : ?>
						<tr><th><?php esc_html_e( 'Gobear Order', 'gobear-personalization' ); ?></th><td><?php echo esc_html( $fulfillment->gobear_order_sn ); ?> (<?php echo esc_html( $fulfillment->device_sn ); ?>)</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<?php if ( in_array( $fulfillment->status, array( 'pending', 'failed' ), true ) ) : ?>
				<h2><?php esc_html_e( 'Choose a device', 'gobear-personalization' ); ?></h2>
				<div id="gobear-device-picker" data-fulfillment-id="<?php echo esc_attr( $fulfillment->id ); ?>" data-machine-type="<?php echo esc_attr( $fulfillment->machine_type ); ?>" data-sku-id="<?php echo esc_attr( $design->sku_id ); ?>">
					<p class="gobear-device-loading"><?php esc_html_e( 'Loading devices…', 'gobear-personalization' ); ?></p>
				</div>
			<?php elseif ( in_array( $fulfillment->status, array( 'sent', 'in_production' ), true ) ) : ?>
				<h2><?php esc_html_e( 'Job status', 'gobear-personalization' ); ?></h2>
				<p>
					<button type="button" class="button" id="gobear-refresh-status" data-fulfillment-id="<?php echo esc_attr( $fulfillment->id ); ?>"><?php esc_html_e( 'Check status now', 'gobear-personalization' ); ?></button>
					<?php if ( ! is_wp_error( $driver ) && $driver->supports_manual_complete() ) : ?>
						<button type="button" class="button" id="gobear-mark-complete" data-fulfillment-id="<?php echo esc_attr( $fulfillment->id ); ?>"><?php esc_html_e( 'Mark complete manually', 'gobear-personalization' ); ?></button>
					<?php endif; ?>
					<span id="gobear-status-result"></span>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'This item has reached a final status and no further action is available here.', 'gobear-personalization' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders a compact "Gobear Personalization" box on the order edit screen (both legacy and
	 * HPOS admin templates fire the hook this is attached to) linking to the full fulfill screen
	 * for each personalized line item on the order.
	 */
	public function render_order_meta_box( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.*, d.model_name, d.sku_name FROM {$wpdb->prefix}gobear_fulfillments f
				LEFT JOIN {$wpdb->prefix}gobear_designs d ON d.id = f.design_id
				WHERE f.order_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order->get_id()
			)
		);

		if ( ! $rows ) {
			return;
		}
		?>
		<div class="gobear-order-box" style="clear:both;padding-top:1em;">
			<h3><?php esc_html_e( 'Gobear Personalization', 'gobear-personalization' ); ?></h3>
			<ul>
				<?php foreach ( $rows as $row ) : ?>
					<li>
						<?php echo esc_html( $row->model_name . ' — ' . $row->sku_name . ' (' . $row->status . ')' ); ?>
						&mdash;
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&action=fulfill&id=' . $row->id ) ); ?>"><?php esc_html_e( 'Manage', 'gobear-personalization' ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * AJAX handlers                                                      *
	 * ------------------------------------------------------------------ */

	private function check_permissions() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gobear-personalization' ) ), 403 );
		}
	}

	public function ajax_list_devices() {
		$this->check_permissions();

		$fulfillment_id = isset( $_POST['fulfillment_id'] ) ? absint( $_POST['fulfillment_id'] ) : 0;
		$fulfillment    = Gobear_Fulfillment_Sync::find( $fulfillment_id );
		if ( ! $fulfillment ) {
			wp_send_json_error( array( 'message' => __( 'Fulfillment record not found.', 'gobear-personalization' ) ) );
		}

		global $wpdb;
		$design = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}gobear_designs WHERE id = %d", $fulfillment->design_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$driver = Gobear_Driver_Factory::create( $fulfillment->machine_type );
		if ( is_wp_error( $driver ) ) {
			wp_send_json_error( array( 'message' => $driver->get_error_message() ) );
		}

		$settings = Gobear_Settings::get_settings();
		$args     = array( 'status' => 1 ); // Active devices only.
		if ( 'AGENT' === $settings['app_type'] && ! empty( $settings['merch_id'] ) ) {
			$args['merch_id'] = $settings['merch_id'];
		}

		$result = $driver->list_devices( $args );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$devices = isset( $result['list'] ) ? $result['list'] : array();
		$output  = array();

		foreach ( $devices as $device ) {
			$entry = array(
				'device_id'   => $device['id'],
				'device_sn'   => $device['device_sn'],
				'device_name' => $device['device_name'],
				'machine_type'=> isset( $device['machine_type'] ) ? $device['machine_type'] : $fulfillment->machine_type,
				'is_online'   => ! empty( $device['is_online'] ),
				'run_status'  => isset( $device['run_status'] ) ? (int) $device['run_status'] : 0,
				'has_slots'   => $driver->supports_slots(),
				'slot_stock'  => null,
			);

			if ( $driver->supports_slots() && $design ) {
				$storage = $driver->get_device_storage( $device['device_sn'] );
				if ( ! is_wp_error( $storage ) ) {
					$stock = 0;
					foreach ( (array) $storage as $slot ) {
						if ( ! empty( $slot['sku_id'] ) && (int) $slot['sku_id'] === (int) $design->sku_id ) {
							$stock += (int) $slot['slot_stock'];
						}
					}
					$entry['slot_stock'] = $stock;
				}
			}

			$output[] = $entry;
		}

		wp_send_json_success( array( 'devices' => $output ) );
	}

	public function ajax_send_to_device() {
		$this->check_permissions();

		$fulfillment_id = isset( $_POST['fulfillment_id'] ) ? absint( $_POST['fulfillment_id'] ) : 0;
		$device_id      = isset( $_POST['device_id'] ) ? absint( $_POST['device_id'] ) : 0;
		$device_sn      = isset( $_POST['device_sn'] ) ? sanitize_text_field( wp_unslash( $_POST['device_sn'] ) ) : '';

		$fulfillment = Gobear_Fulfillment_Sync::find( $fulfillment_id );
		if ( ! $fulfillment || ! $device_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'gobear-personalization' ) ) );
		}

		// Idempotency guard: only pending/failed jobs may be (re)dispatched.
		if ( ! in_array( $fulfillment->status, array( 'pending', 'failed' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'This item has already been sent to a device.', 'gobear-personalization' ) ) );
		}

		global $wpdb;
		$design = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}gobear_designs WHERE id = %d", $fulfillment->design_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $design || ! $design->print_attachment_id || ! $design->rendering_attachment_id ) {
			wp_send_json_error( array( 'message' => __( 'Design artwork is missing for this item.', 'gobear-personalization' ) ) );
		}

		$image_url      = wp_get_attachment_url( $design->print_attachment_id );
		$rendering_url  = wp_get_attachment_url( $design->rendering_attachment_id );

		if ( ! $image_url || ! $rendering_url || 0 !== strpos( $image_url, 'https://' ) ) {
			wp_send_json_error( array( 'message' => __( 'Design images must be served over a public HTTPS URL for the device to fetch them. Check your site\'s URL/SSL configuration.', 'gobear-personalization' ) ) );
		}

		$driver = Gobear_Driver_Factory::create( $fulfillment->machine_type );
		if ( is_wp_error( $driver ) ) {
			wp_send_json_error( array( 'message' => $driver->get_error_message() ) );
		}

		$result = $driver->create_order( $design->model_id, $design->sku_id, $device_id, $image_url, $rendering_url );
		if ( is_wp_error( $result ) ) {
			$wpdb->update(
				$wpdb->prefix . 'gobear_fulfillments',
				array( 'last_error' => $result->get_error_message(), 'updated_at' => current_time( 'mysql', true ) ),
				array( 'id' => $fulfillment->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$now = current_time( 'mysql', true );
		$wpdb->update(
			$wpdb->prefix . 'gobear_fulfillments',
			array(
				'status'          => 'sent',
				'gobear_order_id' => isset( $result['order_id'] ) ? $result['order_id'] : null,
				'gobear_order_sn' => isset( $result['order_sn'] ) ? $result['order_sn'] : null,
				'device_id'       => $device_id,
				'device_sn'       => $device_sn,
				'sent_by_user_id' => get_current_user_id(),
				'sent_at'         => $now,
				'last_error'      => null,
				'updated_at'      => $now,
			),
			array( 'id' => $fulfillment->id ),
			array( '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		$order = wc_get_order( $fulfillment->order_id );
		if ( $order ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: device serial number, 2: Gobear order number. */
					__( 'Gobear: dispatched to device %1$s (Gobear order %2$s).', 'gobear-personalization' ),
					$device_sn,
					isset( $result['order_sn'] ) ? $result['order_sn'] : ''
				)
			);
			if ( $order->has_status( array( 'processing', 'on-hold' ) ) ) {
				$order->update_status( 'personalizing' );
			}
		}

		wp_send_json_success(
			array(
				'gobear_order_id' => isset( $result['order_id'] ) ? $result['order_id'] : null,
				'gobear_order_sn' => isset( $result['order_sn'] ) ? $result['order_sn'] : null,
			)
		);
	}

	public function ajax_mark_complete() {
		$this->check_permissions();

		$fulfillment_id = isset( $_POST['fulfillment_id'] ) ? absint( $_POST['fulfillment_id'] ) : 0;
		$fulfillment    = Gobear_Fulfillment_Sync::find( $fulfillment_id );
		if ( ! $fulfillment || ! $fulfillment->gobear_order_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'gobear-personalization' ) ) );
		}

		$driver = Gobear_Driver_Factory::create( $fulfillment->machine_type );
		if ( is_wp_error( $driver ) || ! $driver->supports_manual_complete() ) {
			wp_send_json_error( array( 'message' => __( 'This device family does not support manually forcing completion.', 'gobear-personalization' ) ) );
		}

		$result = $driver->complete_order( $fulfillment->gobear_order_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		Gobear_Fulfillment_Sync::apply_status( $fulfillment, 'completed' );

		wp_send_json_success( array( 'status' => 'completed' ) );
	}

	public function ajax_refresh_status() {
		$this->check_permissions();

		$fulfillment_id = isset( $_POST['fulfillment_id'] ) ? absint( $_POST['fulfillment_id'] ) : 0;
		$fulfillment    = Gobear_Fulfillment_Sync::find( $fulfillment_id );
		if ( ! $fulfillment || ! $fulfillment->gobear_order_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'gobear-personalization' ) ) );
		}

		$driver = Gobear_Driver_Factory::create( $fulfillment->machine_type );
		if ( is_wp_error( $driver ) ) {
			wp_send_json_error( array( 'message' => $driver->get_error_message() ) );
		}

		$detail = $driver->get_order_detail( $fulfillment->gobear_order_id );
		if ( is_wp_error( $detail ) ) {
			wp_send_json_error( array( 'message' => $detail->get_error_message() ) );
		}

		$mapped = Gobear_Fulfillment_Sync::map_order_detail_to_status( $detail );
		Gobear_Fulfillment_Sync::apply_status(
			$fulfillment,
			$mapped['status'],
			array(
				'error'     => $mapped['error'],
				'snapshots' => isset( $detail['snapshots'] ) ? $detail['snapshots'] : array(),
			)
		);

		wp_send_json_success( array( 'status' => $mapped['status'] ) );
	}
}
