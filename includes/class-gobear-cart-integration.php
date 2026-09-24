<?php
/**
 * Wires the storefront design tool into the product page and cart: enqueues the design-tool
 * assets, blocks "Add to Cart" until a design is attached, and carries the design reference
 * through the cart/checkout item data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gobear_Cart_Integration {

	const CART_ITEM_META_KEY = 'gobear_design_id';

	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_design_button' ) );

		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_thumbnail', array( $this, 'cart_item_thumbnail' ), 10, 3 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'link_design_to_cart_item' ), 10, 2 );
	}

	private function is_product_enabled( $product_id ) {
		return $product_id && Gobear_Product_Fields::is_enabled( $product_id );
	}

	public function enqueue_assets() {
		if ( ! is_product() ) {
			return;
		}
		$product_id = get_the_ID();
		if ( ! $this->is_product_enabled( $product_id ) ) {
			return;
		}

		wp_enqueue_style(
			'gobear-design-tool',
			GOBEAR_PERSONALIZATION_URL . 'assets/css/design-tool.css',
			array(),
			GOBEAR_PERSONALIZATION_VERSION
		);

		wp_enqueue_script(
			'gobear-design-tool',
			GOBEAR_PERSONALIZATION_URL . 'assets/js/design-tool.js',
			array(),
			GOBEAR_PERSONALIZATION_VERSION,
			true
		);

		wp_localize_script(
			'gobear-design-tool',
			'GobearDesignTool',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( Gobear_Design_Ajax::NONCE_ACTION ),
				'productId' => $product_id,
				'i18n'      => array(
					'chooseModel'   => __( 'Choose your phone model', 'gobear-personalization' ),
					'uploadPhoto'   => __( 'Upload a photo', 'gobear-personalization' ),
					'confirm'       => __( 'Use this design', 'gobear-personalization' ),
					'edit'          => __( 'Edit design', 'gobear-personalization' ),
					'loading'       => __( 'Loading…', 'gobear-personalization' ),
					'saving'        => __( 'Saving your design…', 'gobear-personalization' ),
					'error'         => __( 'Something went wrong. Please try again.', 'gobear-personalization' ),
					'designNeeded'  => __( 'Please design your case before adding it to your cart.', 'gobear-personalization' ),
					'zoom'          => __( 'Zoom', 'gobear-personalization' ),
					'rotate'        => __( 'Rotate', 'gobear-personalization' ),
					'designYourCase' => __( 'Design Your Case', 'gobear-personalization' ),
				),
			)
		);
	}

	public function render_design_button() {
		global $product;
		if ( ! $product || ! $this->is_product_enabled( $product->get_id() ) ) {
			return;
		}
		require GOBEAR_PERSONALIZATION_DIR . 'templates/design-modal.php';
	}

	/**
	 * Blocks Add to Cart for personalizable products until a valid, matching design has been
	 * saved via the AJAX design tool.
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity ) {
		if ( ! $this->is_product_enabled( $product_id ) ) {
			return $passed;
		}

		$token = isset( $_POST['gobear_design_token'] ) ? sanitize_text_field( wp_unslash( $_POST['gobear_design_token'] ) ) : '';
		if ( ! $token || ! $this->get_design_by_token( $token, $product_id ) ) {
			wc_add_notice( __( 'Please design your case before adding it to your cart.', 'gobear-personalization' ), 'error' );
			return false;
		}

		return $passed;
	}

	private function get_design_by_token( $token, $product_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'gobear_designs';
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE cart_item_key = %s AND product_id = %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$token,
				$product_id
			)
		);
	}

	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( ! $this->is_product_enabled( $product_id ) ) {
			return $cart_item_data;
		}

		$token = isset( $_POST['gobear_design_token'] ) ? sanitize_text_field( wp_unslash( $_POST['gobear_design_token'] ) ) : '';
		$design = $token ? $this->get_design_by_token( $token, $product_id ) : null;

		if ( $design ) {
			$cart_item_data['gobear_design'] = array(
				'design_id'   => (int) $design->id,
				'token'       => $token,
				'model_id'    => (int) $design->model_id,
				'model_name'  => $design->model_name,
				'sku_id'      => (int) $design->sku_id,
				'sku_name'    => $design->sku_name,
				'machine_type' => $design->machine_type,
				'preview_url' => wp_get_attachment_url( $design->rendering_attachment_id ),
			);
			// Keep cart items with different designs distinct even for the same product/variation.
			$cart_item_data['unique_key'] = md5( 'gobear-' . $token );
		}

		return $cart_item_data;
	}

	public function display_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item['gobear_design'] ) ) {
			return $item_data;
		}
		$design = $cart_item['gobear_design'];

		$item_data[] = array(
			'key'   => __( 'Phone', 'gobear-personalization' ),
			'value' => esc_html( $design['model_name'] ),
		);
		$item_data[] = array(
			'key'   => __( 'Case', 'gobear-personalization' ),
			'value' => esc_html( $design['sku_name'] ),
		);

		return $item_data;
	}

	public function cart_item_thumbnail( $thumbnail, $cart_item, $cart_item_key ) {
		if ( ! empty( $cart_item['gobear_design']['preview_url'] ) ) {
			return '<img src="' . esc_url( $cart_item['gobear_design']['preview_url'] ) . '" class="gobear-cart-thumbnail" alt="" />';
		}
		return $thumbnail;
	}

	/**
	 * Once WooCommerce has assigned the item a real cart_item_key, record it against the design
	 * row (mostly for admin debugging/traceability — the design_id embedded in cart item data is
	 * what everything downstream actually relies on).
	 */
	public function link_design_to_cart_item( $cart_item_key, $product_id ) {
		$cart_item = WC()->cart->get_cart_item( $cart_item_key );
		if ( empty( $cart_item['gobear_design']['design_id'] ) ) {
			return;
		}

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'gobear_designs',
			array( 'cart_item_key' => $cart_item_key ),
			array( 'id' => $cart_item['gobear_design']['design_id'] ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
