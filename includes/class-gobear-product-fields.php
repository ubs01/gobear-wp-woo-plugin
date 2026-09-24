<?php
/**
 * Adds a "Gobear Personalization" panel to the Product Data box, letting a store admin mark a
 * product as a personalizable case and pick which Gobear device family fulfills it. The exact
 * phone model + SKU are resolved live from the cached catalog when the shopper designs their case.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gobear_Product_Fields {

	const META_ENABLED      = '_gobear_enabled';
	const META_MACHINE_TYPE = '_gobear_machine_type';

	public function init() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ) );
		add_action( 'wp_ajax_gobear_sync_catalog', array( $this, 'ajax_sync_catalog' ) );
	}

	public function add_tab( $tabs ) {
		$tabs['gobear'] = array(
			'label'    => __( 'Gobear Personalization', 'gobear-personalization' ),
			'target'   => 'gobear_product_data',
			'class'    => array(),
			'priority' => 21,
		);
		return $tabs;
	}

	public static function is_enabled( $product_id ) {
		return 'yes' === get_post_meta( $product_id, self::META_ENABLED, true );
	}

	public static function get_machine_type( $product_id ) {
		$type = get_post_meta( $product_id, self::META_MACHINE_TYPE, true );
		return $type ? $type : 'GBK77';
	}

	public function render_panel() {
		global $post;

		$enabled      = self::is_enabled( $post->ID );
		$machine_type = self::get_machine_type( $post->ID );
		$families     = Gobear_Driver_Factory::get_supported_families();
		?>
		<div id="gobear_product_data" class="panel woocommerce_options_panel">
			<div class="options_group">
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => self::META_ENABLED,
						'value'       => $enabled ? 'yes' : 'no',
						'label'       => __( 'Personalized with Gobear', 'gobear-personalization' ),
						'description' => __( 'Shows a "Design Your Case" tool on the product page instead of (or alongside) a plain Add to Cart.', 'gobear-personalization' ),
					)
				);
				?>
				<p class="form-field gobear_machine_type_field">
					<label for="<?php echo esc_attr( self::META_MACHINE_TYPE ); ?>"><?php esc_html_e( 'Fulfilled by', 'gobear-personalization' ); ?></label>
					<select id="<?php echo esc_attr( self::META_MACHINE_TYPE ); ?>" name="<?php echo esc_attr( self::META_MACHINE_TYPE ); ?>">
						<?php foreach ( $families as $label => $codes ) : ?>
							<option value="<?php echo esc_attr( $codes[0] ); ?>" <?php selected( in_array( $machine_type, $codes, true ) ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="form-field">
					<button type="button" class="button" id="gobear-sync-catalog"><?php esc_html_e( 'Sync Gobear Catalog Now', 'gobear-personalization' ); ?></button>
					<span id="gobear-sync-catalog-result" style="margin-left:8px;"></span>
					<br /><span class="description"><?php esc_html_e( 'Refreshes the cached list of phone models, case SKUs, and print templates used by the design tool.', 'gobear-personalization' ); ?></span>
				</p>
			</div>
		</div>
		<script>
		(function(){
			var btn = document.getElementById('gobear-sync-catalog');
			if (!btn) { return; }
			btn.addEventListener('click', function(){
				var select = document.getElementById('<?php echo esc_js( self::META_MACHINE_TYPE ); ?>');
				var result = document.getElementById('gobear-sync-catalog-result');
				result.textContent = '<?php echo esc_js( __( 'Syncing…', 'gobear-personalization' ) ); ?>';
				var data = new FormData();
				data.append('action', 'gobear_sync_catalog');
				data.append('machine_type', select.value);
				data.append('_ajax_nonce', '<?php echo esc_js( wp_create_nonce( 'gobear_sync_catalog' ) ); ?>');
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
					.then(function(r){ return r.json(); })
					.then(function(json){
						if (json.success) {
							result.style.color = '#1a7f37';
							result.textContent = json.data.message;
						} else {
							result.style.color = '#b32d2e';
							result.textContent = (json.data && json.data.message) ? json.data.message : '<?php echo esc_js( __( 'Sync failed.', 'gobear-personalization' ) ); ?>';
						}
					});
			});
		})();
		</script>
		<?php
	}

	public function save( $post_id ) {
		$enabled = isset( $_POST[ self::META_ENABLED ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handled by WooCommerce core on this hook.
		update_post_meta( $post_id, self::META_ENABLED, $enabled );

		if ( isset( $_POST[ self::META_MACHINE_TYPE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$machine_type = sanitize_text_field( wp_unslash( $_POST[ self::META_MACHINE_TYPE ] ) );
			update_post_meta( $post_id, self::META_MACHINE_TYPE, $machine_type );
		}
	}

	public function ajax_sync_catalog() {
		check_ajax_referer( 'gobear_sync_catalog' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gobear-personalization' ) ), 403 );
		}

		$machine_type = isset( $_POST['machine_type'] ) ? sanitize_text_field( wp_unslash( $_POST['machine_type'] ) ) : 'GBK77';
		$catalog      = Gobear_Catalog_Cache::get_catalog( $machine_type, true );

		if ( is_wp_error( $catalog ) ) {
			wp_send_json_error( array( 'message' => $catalog->get_error_message() ) );
		}

		$count = count( Gobear_Catalog_Cache::flatten_models( $catalog ) );

		wp_send_json_success(
			array(
				/* translators: %d: number of phone models found. */
				'message' => sprintf( __( 'Synced. %d phone models available.', 'gobear-personalization' ), $count ),
			)
		);
	}
}
