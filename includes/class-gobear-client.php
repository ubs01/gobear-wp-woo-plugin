<?php
/**
 * Thin HTTP client for the Gobear Open API: bearer-token auth (with caching + auto-refresh) and
 * the account-wide "common" endpoints. Device-family-specific endpoints live in the driver classes
 * (see includes/drivers/), which call back into request() on this client.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gobear_Client {

	const TOKEN_TRANSIENT = 'gobear_bearer_token';

	/** @var array Plugin settings (app_key, app_secret, api_base_url, accept_language, ...). */
	private $settings;

	public function __construct( $settings = null ) {
		$this->settings = $settings ? $settings : Gobear_Settings::get_settings();
	}

	/**
	 * Returns true when app_key/app_secret have been configured at all. This does not verify
	 * they are valid — use get_profile() for that (surfaced via the Settings screen's "Test
	 * Connection" button).
	 */
	public function is_configured() {
		return ! empty( $this->settings['app_key'] ) && ! empty( $this->settings['app_secret'] );
	}

	public function get_app_secret() {
		return isset( $this->settings['app_secret'] ) ? $this->settings['app_secret'] : '';
	}

	public function get_app_key() {
		return isset( $this->settings['app_key'] ) ? $this->settings['app_key'] : '';
	}

	private function get_base_url() {
		$base = isset( $this->settings['api_base_url'] ) ? $this->settings['api_base_url'] : 'https://saas-cdn.gobear8.com';
		return untrailingslashit( $base );
	}

	/**
	 * Returns a cached bearer token, requesting a new one if there isn't a valid cached copy.
	 *
	 * @return string|WP_Error
	 */
	public function get_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( $cached ) {
			return $cached;
		}
		return $this->refresh_token();
	}

	/**
	 * Forces a new token to be fetched from Gobear and caches it.
	 *
	 * @return string|WP_Error
	 */
	public function refresh_token() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'gobear_not_configured', __( 'Gobear App Key/Secret are not configured.', 'gobear-personalization' ) );
		}

		$response = wp_remote_post(
			$this->get_base_url() . '/openapi/auth/bearer',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'app_key'    => $this->get_app_key(),
						'app_secret' => $this->get_app_secret(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['code'] ) || 1 !== (int) $body['code'] || empty( $body['data']['token'] ) ) {
			$message = isset( $body['msg'] ) ? $body['msg'] : __( 'Unknown error obtaining Gobear bearer token.', 'gobear-personalization' );
			return new WP_Error( 'gobear_auth_failed', $message, $body );
		}

		$token       = $body['data']['token'];
		$expire_time = isset( $body['data']['expire_time'] ) ? (int) $body['data']['expire_time'] : ( time() + 5 * MINUTE_IN_SECONDS );
		// Cache slightly under the real expiry so we never hand out a token that's about to die mid-request.
		$ttl = max( 30, ( $expire_time - time() ) - 30 );

		set_transient( self::TOKEN_TRANSIENT, $token, $ttl );

		return $token;
	}

	/**
	 * Performs a request against the Gobear API, handling auth headers, JSON decoding, the
	 * documented `code` envelope, and a single automatic token-refresh retry on auth failure.
	 *
	 * @param string $method       GET|POST.
	 * @param string $path         API path, e.g. '/openapi/phone/list' or a driver-prefixed path.
	 * @param array  $params       For GET: query args. For POST: JSON body fields.
	 * @param bool   $is_retry     Internal flag to prevent infinite retry loops.
	 * @return array|WP_Error      Decoded `data` payload on success.
	 */
	public function request( $method, $path, $params = array(), $is_retry = false ) {
		$token = $this->get_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = $this->get_base_url() . $path;

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 30,
			'headers' => array(
				'Authorization'   => 'Bearer ' . $token,
				'Accept-Language' => ! empty( $this->settings['accept_language'] ) ? $this->settings['accept_language'] : 'en-US',
			),
		);

		if ( 'GET' === strtoupper( $method ) ) {
			if ( ! empty( $params ) ) {
				$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $params );
			}
		} else {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $params );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		// Auth failures: retry once after forcing a fresh token (covers a token that expired
		// between our transient check and the request actually reaching Gobear).
		$auth_failed = ( 401 === (int) $http_code ) || ( isset( $body['code'] ) && in_array( (int) $body['code'], array( 1001, 1002 ), true ) );
		if ( $auth_failed && ! $is_retry ) {
			delete_transient( self::TOKEN_TRANSIENT );
			return $this->request( $method, $path, $params, true );
		}

		if ( null === $body ) {
			return new WP_Error(
				'gobear_bad_response',
				__( 'Gobear API returned a non-JSON response.', 'gobear-personalization' ),
				array( 'http_code' => $http_code, 'raw' => wp_remote_retrieve_body( $response ) )
			);
		}

		if ( empty( $body['code'] ) || 1 !== (int) $body['code'] ) {
			$message = isset( $body['msg'] ) ? $body['msg'] : __( 'Unknown Gobear API error.', 'gobear-personalization' );
			return new WP_Error( 'gobear_api_error', $message, $body );
		}

		return isset( $body['data'] ) ? $body['data'] : null;
	}

	public function get( $path, $params = array() ) {
		return $this->request( 'GET', $path, $params );
	}

	public function post( $path, $params = array() ) {
		return $this->request( 'POST', $path, $params );
	}

	/* ------------------------------------------------------------------ *
	 * Common (account-wide) endpoints — see "Common" folder in the docs. *
	 * ------------------------------------------------------------------ */

	/** doc 1.2 Get Account Profile. */
	public function get_profile() {
		return $this->get( '/openapi/auth/profile' );
	}

	/** doc 1.3 Get merchant list (agent identity only; a merchant token only ever sees itself). */
	public function get_merchant_list( $get_account = 0 ) {
		return $this->get( '/openapi/merch/list', array( 'get_account' => $get_account ? 1 : 0 ) );
	}

	/** doc 1.4 Get phone list. brand_id defaults server-side to Apple (1) when omitted. */
	public function get_phone_list( $brand_id = null ) {
		$params = array();
		if ( null !== $brand_id ) {
			$params['brand_id'] = (int) $brand_id;
		}
		return $this->get( '/openapi/phone/list', $params );
	}

	/** doc 1.5 Get merchant stock info. */
	public function get_merchant_stock( $merch_id = null, $plugin_name = null ) {
		$params = array();
		if ( null !== $merch_id ) {
			$params['merch_id'] = (int) $merch_id;
		}
		if ( null !== $plugin_name ) {
			$params['plugin_name'] = $plugin_name;
		}
		return $this->get( '/openapi/merch/stock', $params );
	}
}
