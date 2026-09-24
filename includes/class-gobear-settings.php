<?php
/**
 * Plugin settings screen: Gobear credentials, API host, language, and cache/staleness tuning.
 * Registered as its own top-level admin menu ("Gobear Personalization") rather than a
 * WooCommerce settings tab, so it works even before WooCommerce's settings-page classes are
 * guaranteed to be loaded.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gobear_Settings {

	const OPTION_KEY    = 'gobear_personalization_settings';
	const MENU_SLUG      = 'gobear-personalization';
	const OPTION_GROUP   = 'gobear_personalization_settings_group';

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_gobear_test_connection', array( $this, 'ajax_test_connection' ) );
	}

	/**
	 * Returns the plugin settings merged with defaults, so callers never need isset() guards.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'app_key'           => '',
			'app_secret'        => '',
			'api_base_url'      => 'https://saas-cdn.gobear8.com',
			'merch_id'          => '',
			'app_type'          => '',
			'accept_language'   => 'en-US',
			'stale_job_hours'   => 6,
			'catalog_cache_ttl' => HOUR_IN_SECONDS,
		);

		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array_merge( $defaults, $saved );
	}

	public function add_menu() {
		add_menu_page(
			__( 'Gobear Personalization', 'gobear-personalization' ),
			__( 'Gobear Cases', 'gobear-personalization' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render_settings_page' ),
			'dashicons-smartphone',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Gobear Settings', 'gobear-personalization' ),
			__( 'Settings', 'gobear-personalization' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitizes and merges submitted settings. The App Secret field is write-only in the UI: an
	 * empty submission means "leave the stored secret unchanged" rather than clearing it.
	 */
	public function sanitize( $input ) {
		$existing = self::get_settings();
		$output   = $existing;

		$output['app_key']         = isset( $input['app_key'] ) ? sanitize_text_field( $input['app_key'] ) : $existing['app_key'];
		$output['api_base_url']    = isset( $input['api_base_url'] ) ? esc_url_raw( untrailingslashit( trim( $input['api_base_url'] ) ) ) : $existing['api_base_url'];
		$output['merch_id']        = isset( $input['merch_id'] ) ? sanitize_text_field( $input['merch_id'] ) : $existing['merch_id'];
		$output['accept_language'] = isset( $input['accept_language'] ) ? sanitize_text_field( $input['accept_language'] ) : $existing['accept_language'];
		$output['stale_job_hours'] = isset( $input['stale_job_hours'] ) ? max( 1, (int) $input['stale_job_hours'] ) : $existing['stale_job_hours'];

		if ( isset( $input['catalog_cache_minutes'] ) ) {
			$output['catalog_cache_ttl'] = max( 5, (int) $input['catalog_cache_minutes'] ) * MINUTE_IN_SECONDS;
		}

		if ( ! empty( $input['app_secret'] ) ) {
			$output['app_secret'] = sanitize_text_field( $input['app_secret'] );
		}

		// Credentials changed: drop any cached token so the next call re-authenticates.
		if ( $output['app_key'] !== $existing['app_key'] || $output['app_secret'] !== $existing['app_secret'] || $output['api_base_url'] !== $existing['api_base_url'] ) {
			delete_transient( Gobear_Client::TOKEN_TRANSIENT );
		}

		return $output;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings    = self::get_settings();
		$webhook_url = rest_url( 'gobear/v1/webhook' );
		$languages   = array(
			'zh-CN' => __( 'Simplified Chinese', 'gobear-personalization' ),
			'zh-TW' => __( 'Traditional Chinese (Taiwan)', 'gobear-personalization' ),
			'en-US' => __( 'English (US)', 'gobear-personalization' ),
			'ja-JP' => __( 'Japanese', 'gobear-personalization' ),
			'ko-KR' => __( 'Korean', 'gobear-personalization' ),
			'ru-RU' => __( 'Russian', 'gobear-personalization' ),
			'th-TH' => __( 'Thai', 'gobear-personalization' ),
		);
		?>
		<div class="wrap gobear-settings-wrap">
			<h1><?php esc_html_e( 'Gobear Personalization Settings', 'gobear-personalization' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gobear_app_key"><?php esc_html_e( 'App Key', 'gobear-personalization' ); ?></label></th>
						<td>
							<input type="text" id="gobear_app_key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[app_key]" value="<?php echo esc_attr( $settings['app_key'] ); ?>" class="regular-text" autocomplete="off" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gobear_app_secret"><?php esc_html_e( 'App Secret', 'gobear-personalization' ); ?></label></th>
						<td>
							<input type="password" id="gobear_app_secret" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[app_secret]" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $settings['app_secret'] ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'gobear-personalization' ) : esc_attr__( 'Not set', 'gobear-personalization' ); ?>" />
							<p class="description"><?php esc_html_e( 'Also used to verify the webhook signature. Provided by your Gobear sales/account manager.', 'gobear-personalization' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gobear_api_base_url"><?php esc_html_e( 'API Base URL', 'gobear-personalization' ); ?></label></th>
						<td>
							<input type="url" id="gobear_api_base_url" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_base_url]" value="<?php echo esc_attr( $settings['api_base_url'] ); ?>" class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gobear_merch_id"><?php esc_html_e( 'Merchant ID', 'gobear-personalization' ); ?></label></th>
						<td>
							<input type="text" id="gobear_merch_id" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[merch_id]" value="<?php echo esc_attr( $settings['merch_id'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Only needed if "Test Connection" reports your App Key is an AGENT-level key (several endpoints then require an explicit merchant id). Leave blank for a normal MERCHANT-type key.', 'gobear-personalization' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gobear_accept_language"><?php esc_html_e( 'API Response Language', 'gobear-personalization' ); ?></label></th>
						<td>
							<select id="gobear_accept_language" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[accept_language]">
								<?php foreach ( $languages as $code => $label ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['accept_language'], $code ); ?>><?php echo esc_html( $label . ' (' . $code . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gobear_catalog_cache_minutes"><?php esc_html_e( 'Catalog Cache (minutes)', 'gobear-personalization' ); ?></label></th>
						<td>
							<input type="number" min="5" step="1" id="gobear_catalog_cache_minutes" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[catalog_cache_minutes]" value="<?php echo esc_attr( round( $settings['catalog_cache_ttl'] / MINUTE_IN_SECONDS ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'How long phone/case catalog data (models, SKUs, print templates) is cached before re-fetching.', 'gobear-personalization' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gobear_stale_job_hours"><?php esc_html_e( 'Stale Job Threshold (hours)', 'gobear-personalization' ); ?></label></th>
						<td>
							<input type="number" min="1" step="1" id="gobear_stale_job_hours" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[stale_job_hours]" value="<?php echo esc_attr( $settings['stale_job_hours'] ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'If a dispatched job has not received a webhook update after this many hours, the background check will poll Gobear directly for its status.', 'gobear-personalization' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'gobear-personalization' ); ?></th>
						<td>
							<input type="text" readonly="readonly" onclick="this.select();" value="<?php echo esc_attr( $webhook_url ); ?>" class="regular-text code" />
							<p class="description"><?php esc_html_e( 'Order status webhooks are registered manually by Gobear\'s ops team — send them this URL along with your App Key.', 'gobear-personalization' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Test Connection', 'gobear-personalization' ); ?></h2>
			<p>
				<button type="button" class="button" id="gobear-test-connection"><?php esc_html_e( 'Test Connection', 'gobear-personalization' ); ?></button>
				<span id="gobear-test-connection-result" style="margin-left:10px;"></span>
			</p>
			<script>
			(function(){
				var btn = document.getElementById('gobear-test-connection');
				if (!btn) { return; }
				btn.addEventListener('click', function(){
					var result = document.getElementById('gobear-test-connection-result');
					result.textContent = '<?php echo esc_js( __( 'Testing…', 'gobear-personalization' ) ); ?>';
					var data = new FormData();
					data.append('action', 'gobear_test_connection');
					data.append('_ajax_nonce', '<?php echo esc_js( wp_create_nonce( 'gobear_test_connection' ) ); ?>');
					fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
						.then(function(r){ return r.json(); })
						.then(function(json){
							if (json.success) {
								result.textContent = json.data.message;
								result.style.color = '#1a7f37';
							} else {
								result.textContent = (json.data && json.data.message) ? json.data.message : '<?php echo esc_js( __( 'Connection failed.', 'gobear-personalization' ) ); ?>';
								result.style.color = '#b32d2e';
							}
						})
						.catch(function(){
							result.textContent = '<?php echo esc_js( __( 'Connection failed.', 'gobear-personalization' ) ); ?>';
							result.style.color = '#b32d2e';
						});
				});
			})();
			</script>
		</div>
		<?php
	}

	public function ajax_test_connection() {
		check_ajax_referer( 'gobear_test_connection' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gobear-personalization' ) ), 403 );
		}

		delete_transient( Gobear_Client::TOKEN_TRANSIENT );

		$client  = new Gobear_Client();
		$profile = $client->get_profile();

		if ( is_wp_error( $profile ) ) {
			wp_send_json_error( array( 'message' => $profile->get_error_message() ) );
		}

		$app_type = isset( $profile['app_type'] ) ? $profile['app_type'] : '';
		$settings = self::get_settings();
		$settings['app_type'] = $app_type;

		if ( 'MERCHANT' === $app_type && ! empty( $profile['merchant']['id'] ) ) {
			$settings['merch_id'] = $profile['merchant']['id'];
			$name                 = $profile['merchant']['merch_name'];
		} elseif ( 'AGENT' === $app_type && ! empty( $profile['agent']['agent_name'] ) ) {
			$name = $profile['agent']['agent_name'];
		} else {
			$name = isset( $profile['app_name'] ) ? $profile['app_name'] : '';
		}

		update_option( self::OPTION_KEY, $settings );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: app type (AGENT/MERCHANT), 2: account name. */
					__( 'Connected. Account type: %1$s (%2$s).', 'gobear-personalization' ),
					$app_type,
					$name
				),
			)
		);
	}
}
