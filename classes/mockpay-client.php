<?php
/**
 * HTTP client for the MockPay API.
 *
 * @package KrokedilDemoPayments
 */

/**
 * Send authenticated requests to MockPay.
 */
class KDP_MockPay_Client {
	/**
	 * MockPay API base URL.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Basic authentication username.
	 *
	 * @var string
	 */
	private $username;

	/**
	 * Basic authentication password.
	 *
	 * @var string
	 */
	private $password;

	/**
	 * Constructor.
	 *
	 * @param string $base_url MockPay base URL.
	 * @param string $username Basic authentication username.
	 * @param string $password Basic authentication password.
	 */
	public function __construct( $base_url, $username, $password ) {
		$this->base_url = untrailingslashit( $base_url );
		$this->username = $username;
		$this->password = $password;
	}

	/**
	 * Validate an HTTP(S) URL while allowing local development hosts.
	 *
	 * The URL is entered by a store administrator; WordPress's safe-URL helper
	 * rejects localhost and private Docker hosts that are valid for this API.
	 *
	 * @param string $url URL to validate.
	 * @return bool
	 */
	public static function is_valid_http_url( $url ) {
		$parts = wp_parse_url( $url );

		return is_array( $parts )
			&& ! empty( $parts['host'] )
			&& isset( $parts['scheme'] )
			&& in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& ! isset( $parts['query'] )
			&& ! isset( $parts['fragment'] )
			&& false !== filter_var( $url, FILTER_VALIDATE_URL );
	}

	/**
	 * Create a payment and obtain the hosted checkout URL.
	 *
	 * @param array $payload Payment payload.
	 * @return array|WP_Error
	 */
	public function create_payment( $payload ) {
		return $this->request( 'POST', '/payments', $payload );
	}

	/**
	 * Retrieve a payment so the return URL is not trusted as proof of payment.
	 *
	 * @param string $payment_id MockPay payment ID.
	 * @return array|WP_Error
	 */
	public function get_payment( $payment_id ) {
		return $this->request( 'GET', '/payments/' . rawurlencode( $payment_id ) );
	}

	/**
	 * Activate only after MockPay reports the payment as authorized.
	 *
	 * @param string $payment_id MockPay payment ID.
	 * @return array|WP_Error
	 */
	public function activate_payment( $payment_id ) {
		return $this->request( 'POST', '/payments/' . rawurlencode( $payment_id ) . '/activate' );
	}

	/**
	 * Cancel the pending MockPay payment when the customer cancels checkout.
	 *
	 * @param string $payment_id MockPay payment ID.
	 * @return array|WP_Error
	 */
	public function cancel_payment( $payment_id ) {
		return $this->request( 'POST', '/payments/' . rawurlencode( $payment_id ) . '/cancel' );
	}

	/**
	 * Make an authenticated JSON request to MockPay.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path API path.
	 * @param array|null $payload Optional JSON payload.
	 * @return array|WP_Error
	 */
	private function request( $method, $path, $payload = null ) {
		$url = $this->base_url . $path;

		if ( ! self::is_valid_http_url( $url ) ) {
			return new WP_Error( 'kdp_mockpay_invalid_url', 'MockPay API URL is invalid.' );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => 20,
			// Do not forward Basic Auth credentials if the API redirects elsewhere.
			'redirection' => 0,
			'headers'     => array(
				'Accept'        => 'application/json',
				// Basic Auth requires credentials to be Base64 encoded.
				'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'Content-Type'  => 'application/json',
			),
		);

		if ( null !== $payload ) {
			$args['body'] = wp_json_encode( $payload );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error(
				'kdp_mockpay_invalid_response',
				sprintf( 'MockPay returned invalid JSON (HTTP %d).', $status_code ),
				array(
					'status' => $status_code,
					'body'   => substr( $body, 0, 500 ),
				)
			);
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = $data['detail'] ?? $data['title'] ?? 'MockPay request failed.';
			$message = is_string( $message ) ? $message : 'MockPay request failed.';
			return new WP_Error(
				'kdp_mockpay_http_error',
				sprintf( 'MockPay HTTP %d: %s', $status_code, sanitize_text_field( $message ) ),
				array(
					'status' => $status_code,
					'body'   => $data,
				)
			);
		}

		return $data;
	}
}
