<?php
/**
 * Storefront-facing AJAX endpoints for the case design tool: fetching the catalog (models/SKUs/
 * templates) for a personalizable product, and saving a finished design (two composited images)
 * as a row the cart/checkout flow can reference.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gobear_Design_Ajax {

	const NONCE_ACTION      = 'gobear_design_nonce';
	const MAX_UPLOAD_BYTES  = 8 * 1024 * 1024; // 8MB per image.
	private static $allowed_mimes = array( 'png' => 'image/png', 'jpg|jpeg' => 'image/jpeg' );

	public function init() {
		add_action( 'wp_ajax_gobear_get_catalog', array( $this, 'ajax_get_catalog' ) );
		add_action( 'wp_ajax_nopriv_gobear_get_catalog', array( $this, 'ajax_get_catalog' ) );

		add_action( 'wp_ajax_gobear_save_design', array( $this, 'ajax_save_design' ) );
		add_action( 'wp_ajax_nopriv_gobear_save_design', array( $this, 'ajax_save_design' ) );
	}

	/**
	 * Returns the flattened, design-tool-ready catalog for a given (personalization-enabled)
	 * product: available phone models, each with its materials/SKUs and the SKU's base_map
	 * print-template image.
	 */
	public function ajax_get_catalog() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		if ( ! $product_id || ! Gobear_Product_Fields::is_enabled( $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This product is not set up for personalization.', 'gobear-personalization' ) ) );
		}

		$machine_type = Gobear_Product_Fields::get_machine_type( $product_id );
		$catalog      = Gobear_Catalog_Cache::get_catalog( $machine_type );

		if ( is_wp_error( $catalog ) ) {
			wp_send_json_error( array( 'message' => $catalog->get_error_message() ) );
		}

		$models = array();
		foreach ( Gobear_Catalog_Cache::flatten_models( $catalog ) as $model ) {
			$materials = array();
			foreach ( (array) $model['materials'] as $material ) {
				$skus = array();
				foreach ( (array) $material['skus'] as $sku ) {
					$skus[] = array(
						'sku_id'    => $sku['sku_id'],
						'sku_name'  => isset( $sku['sku_name'] ) ? $sku['sku_name'] : '',
						'base_map'  => isset( $sku['base_map'] ) ? $sku['base_map'] : '',
					);
				}
				if ( $skus ) {
					$materials[] = array(
						'material_id'   => isset( $material['material_id'] ) ? $material['material_id'] : null,
						'material_name' => isset( $material['material_name'] ) ? $material['material_name'] : '',
						'skus'          => $skus,
					);
				}
			}
			if ( ! $materials ) {
				continue;
			}
			$models[] = array(
				'brand_id'    => $model['brand_id'],
				'brand_name'  => $model['brand_name'],
				'model_id'    => $model['model_id'],
				'model_name'  => $model['model_name'],
				'full_name'   => $model['full_name'],
				'phone_image' => $model['phone_image'],
				'materials'   => $materials,
			);
		}

		wp_send_json_success( array( 'models' => $models, 'machine_type' => $machine_type ) );
	}

	/**
	 * Saves a finished design: validates the chosen model/sku actually exist in the cached
	 * catalog (never trust client-supplied ids/prices), sideloads the two composited images into
	 * the Media Library, and records a wp_gobear_designs row the cart can reference.
	 */
	public function ajax_save_design() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$model_id   = isset( $_POST['model_id'] ) ? absint( $_POST['model_id'] ) : 0;
		$sku_id     = isset( $_POST['sku_id'] ) ? absint( $_POST['sku_id'] ) : 0;

		if ( ! $product_id || ! Gobear_Product_Fields::is_enabled( $product_id ) || ! $model_id || ! $sku_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing or invalid design parameters.', 'gobear-personalization' ) ) );
		}

		$machine_type = Gobear_Product_Fields::get_machine_type( $product_id );
		$catalog      = Gobear_Catalog_Cache::get_catalog( $machine_type );
		if ( is_wp_error( $catalog ) ) {
			wp_send_json_error( array( 'message' => $catalog->get_error_message() ) );
		}

		$model = Gobear_Catalog_Cache::find_model( $catalog, $model_id );
		if ( ! $model ) {
			wp_send_json_error( array( 'message' => __( 'That phone model is no longer available.', 'gobear-personalization' ) ) );
		}

		$sku = Gobear_Catalog_Cache::find_sku_in_model( $model, $sku_id );
		if ( ! $sku ) {
			wp_send_json_error( array( 'message' => __( 'That case option is not available for the selected phone.', 'gobear-personalization' ) ) );
		}

		if ( empty( $_FILES['photo']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please choose a photo for your case.', 'gobear-personalization' ) ) );
		}

		if ( $_FILES['photo']['size'] > self::MAX_UPLOAD_BYTES ) {
			wp_send_json_error( array( 'message' => __( 'Photo is too large (8MB max).', 'gobear-personalization' ) ) );
		}

		$filetype = wp_check_filetype_and_ext( $_FILES['photo']['tmp_name'], $_FILES['photo']['name'], self::$allowed_mimes );
		if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Only PNG or JPEG photos are allowed.', 'gobear-personalization' ) ) );
		}

		$design_meta = array();
		if ( isset( $_POST['design_meta'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['design_meta'] ), true );
			if ( is_array( $decoded ) ) {
				$design_meta = $decoded;
			}
		}
		if ( empty( $design_meta['container_w'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing design placement data — please reposition your photo and try again.', 'gobear-personalization' ) ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$composited = Gobear_Image_Compositor::composite(
			$sku['base_map'],
			isset( $model['phone_image'] ) ? $model['phone_image'] : '',
			$_FILES['photo']['tmp_name'],
			$design_meta
		);

		if ( is_wp_error( $composited ) ) {
			wp_send_json_error( array( 'message' => $composited->get_error_message() ) );
		}

		$print_id = $this->sideload_from_path( $composited['print_path'], 'gobear-print.png', $product_id );
		if ( is_wp_error( $print_id ) ) {
			wp_send_json_error( array( 'message' => $print_id->get_error_message() ) );
		}

		$rendering_id = $this->sideload_from_path( $composited['rendering_path'], 'gobear-render.png', $product_id );
		if ( is_wp_error( $rendering_id ) ) {
			wp_delete_attachment( $print_id, true );
			wp_send_json_error( array( 'message' => $rendering_id->get_error_message() ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'gobear_designs';
		$token = wp_generate_password( 32, false, false );

		$wpdb->insert(
			$table,
			array(
				'cart_item_key'           => $token, // Temporary token until the item is actually added to cart.
				'product_id'              => $product_id,
				'user_id'                 => get_current_user_id() ?: null,
				'brand_id'                => $model['brand_id'],
				'brand_name'              => $model['brand_name'],
				'model_id'                => $model_id,
				'model_name'              => $model['full_name'] ? $model['full_name'] : $model['model_name'],
				'machine_type'            => $machine_type,
				'sku_id'                  => $sku_id,
				'sku_name'                => $sku['sku_name'],
				'print_attachment_id'     => $print_id,
				'rendering_attachment_id' => $rendering_id,
				'design_meta'             => wp_json_encode( $design_meta ),
				'created_at'              => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s' )
		);

		$design_id = $wpdb->insert_id;
		if ( ! $design_id ) {
			wp_delete_attachment( $print_id, true );
			wp_delete_attachment( $rendering_id, true );
			wp_send_json_error( array( 'message' => __( 'Could not save your design. Please try again.', 'gobear-personalization' ) ) );
		}

		wp_send_json_success(
			array(
				'design_id'     => $design_id,
				'design_token'  => $token,
				'preview_url'   => wp_get_attachment_url( $rendering_id ),
				'model_name'    => $model['full_name'] ? $model['full_name'] : $model['model_name'],
				'sku_name'      => $sku['sku_name'],
			)
		);
	}

	/**
	 * Sideloads a server-generated temp file (the compositor's output) into the Media Library.
	 *
	 * @param string $tmp_path   Local filesystem path (already validated/generated by our own code).
	 * @param string $filename   Suggested filename.
	 * @param int    $product_id Used only to attach the media item for tidiness.
	 * @return int|WP_Error Attachment ID.
	 */
	private function sideload_from_path( $tmp_path, $filename, $product_id ) {
		$file = array(
			'name'     => 'gobear-design-' . wp_generate_password( 8, false ) . '-' . $filename,
			'type'     => 'image/png',
			'tmp_name' => $tmp_path,
			'error'    => 0,
			'size'     => filesize( $tmp_path ),
		);

		// media_handle_sideload() moves (or copies+unlinks) the tmp file into the uploads
		// directory itself, so we don't need to clean it up separately on success.
		return media_handle_sideload( $file, $product_id );
	}
}
