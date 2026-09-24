<?php
/**
 * Receives Gobear's order-status webhook (doc 5.0/5.1): verifies the MD5 signature, then updates
 * the matching fulfillment record and WooCommerce order. Registered as a public REST route since
 * Gobear calls it unauthenticated and relies on the signature instead of WP auth.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gobear_Webhook {

	const NAMESPACE_ = 'gobear/v1';
	const ROUTE      = '/webhook';

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true', // Authenticated via signature, not WP auth.
			)
		);
	}

	/**
	 * Recomputes the signature exactly per doc 5.0: drop `sign`, sort remaining keys ascending by
	 * field name, concatenate as `key=value&` (trailing `&` kept before the secret), append the
	 * app secret, then MD5.
	 */
	public static function compute_signature( array $params, $app_secret ) {
		unset( $params['sign'] );
		ksort( $params );

		$signature_data = '';
		foreach ( $params as $key => $value ) {
			$signature_data .= $key . '=' . $value . '&';
		}

		return md5( $signature_data . $app_secret );
	}

	public function handle( WP_REST_Request $request ) {
		$params = $request->get_body_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}

		$this->respond_and_exit( $this->process( $params ) );
	}

	/**
	 * @return bool True to acknowledge with SUCCESS, false to signal FAILED (Gobear will retry).
	 */
	private function process( $params ) {
		$required = array( 'app_key', 'order_id', 'order_sn', 'event_type', 'sign' );
		foreach ( $required as $field ) {
			if ( ! isset( $params[ $field ] ) ) {
				$this->log( 'Webhook missing required field: ' . $field );
				return false;
			}
		}

		$settings = Gobear_Settings::get_settings();

		if ( empty( $settings['app_secret'] ) || $params['app_key'] !== $settings['app_key'] ) {
			$this->log( 'Webhook app_key mismatch.' );
			return false;
		}

		$expected = self::compute_signature( $params, $settings['app_secret'] );
		if ( ! hash_equals( $expected, (string) $params['sign'] ) ) {
			$this->log( 'Webhook signature mismatch.' );
			return false;
		}

		$fulfillment = Gobear_Fulfillment_Sync::find_by_gobear_order_id( (int) $params['order_id'] );
		if ( ! $fulfillment ) {
			// Not one of ours (or already deleted) — acknowledge so Gobear stops retrying.
			$this->log( 'Webhook for unknown Gobear order_id: ' . $params['order_id'] );
			return true;
		}

		$status = Gobear_Fulfillment_Sync::map_webhook_event_to_status( $params['event_type'] );
		if ( ! $status ) {
			$this->log( 'Webhook with unrecognized event_type: ' . $params['event_type'] );
			return true;
		}

		$snapshots = array();
		if ( 'completed' === $status ) {
			$driver = Gobear_Driver_Factory::create( $fulfillment->machine_type );
			if ( ! is_wp_error( $driver ) ) {
				$detail = $driver->get_order_detail( $fulfillment->gobear_order_id );
				if ( ! is_wp_error( $detail ) && ! empty( $detail['snapshots'] ) ) {
					$snapshots = $detail['snapshots'];
				}
			}
		}

		Gobear_Fulfillment_Sync::apply_status( $fulfillment, $status, array( 'snapshots' => $snapshots ) );

		return true;
	}

	/**
	 * Gobear's spec expects a bare HTTP 200 response with the literal text body "SUCCESS" or
	 * "FAILED" — not a JSON envelope — so we bypass the default REST JSON response and write the
	 * body directly.
	 */
	private function respond_and_exit( $success ) {
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo $success ? 'SUCCESS' : 'FAILED';
		exit;
	}

	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Gobear Webhook] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
