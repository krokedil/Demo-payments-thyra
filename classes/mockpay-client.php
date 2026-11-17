<?php
/**
 * API facade and shared request handling for MockPay.
 *
 * @package KrokedilDemoPayments
 */

/**
 * MockPay API facade with one method per endpoint.
 */
class KDP_MockPay_Client {
	/**
	 * Shared HTTP request handler.
	 *
	 * @var KDP_MockPay_Request
	 */
	private $request;

	/**
	 * Constructor.
	 *
	 * @param string $base_url MockPay base URL.
	 * @param string $username Basic authentication username.
	 * @param string $password Basic authentication password.
	 */
	public function __construct( $base_url, $username, $password ) {
		$this->request = new KDP_MockPay_Request( $base_url, $username, $password );
	}

	/**
	 * Validate an HTTP(S) URL while allowing local development hosts.
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
	 * @param array<string, mixed> $payload Payment payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function create_payment( $payload ) {
		return $this->request->request( 'POST', '/payments', $payload );
	}

	/**
	 * Retrieve a payment by its ID.
	 *
	 * @param string $payment_id MockPay payment ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_payment( $payment_id ) {
		return $this->request->request( 'GET', '/payments/' . rawurlencode( $payment_id ) );
	}

	/**
	 * Activate a payment.
	 *
	 * @param string $payment_id MockPay payment ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function activate_payment( $payment_id ) {
		return $this->request->request( 'POST', '/payments/' . rawurlencode( $payment_id ) . '/activate' );
	}

	/**
	 * Cancel a payment.
	 *
	 * @param string $payment_id MockPay payment ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function cancel_payment( $payment_id ) {
		return $this->request->request( 'POST', '/payments/' . rawurlencode( $payment_id ) . '/cancel' );
	}
}

/**
 * Shared request and response processing for the MockPay API facade.
 */
class KDP_MockPay_Request {
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
	 * Send a request and normalize its response.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $path API path.
	 * @param array<string, mixed>|null $payload Optional JSON payload.
	 * @return array<string, mixed>|WP_Error
	 */
	public function request( $method, $path, $payload = null ) {
		$url = $this->base_url . $path;

		if ( ! KDP_MockPay_Client::is_valid_http_url( $url ) ) {
			return new WP_Error( 'kdp_mockpay_invalid_url', 'MockPay API URL is invalid.' );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => 20,
			// Do not forward Basic Auth credentials if the API redirects elsewhere.
			'redirection' => 0,
			'headers'     => array(
				'Accept'        => 'application/json, application/problem+json',
				// Basic Auth requires credentials to be Base64 encoded.
				'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'Content-Type'  => 'application/json',
			),
		);

		if ( null !== $payload ) {
			$body = wp_json_encode( $payload );
			if ( false === $body ) {
				return new WP_Error( 'kdp_mockpay_json_error', 'Could not encode the MockPay request as JSON.' );
			}
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );
		return $this->process_response( $response );
	}

	/**
	 * Process a response in one place for every endpoint.
	 *
	 * @param array<string, mixed>|WP_Error $response WordPress HTTP response.
	 * @return array<string, mixed>|WP_Error
	 */
	private function process_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code  = wp_remote_retrieve_response_code( $response );
		$body         = wp_remote_retrieve_body( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		$content_type = is_string( $content_type ) ? $content_type : '';
		$data         = json_decode( $body, true );

		if ( ! is_int( $status_code ) ) {
			return new WP_Error( 'kdp_mockpay_invalid_response', 'MockPay returned an invalid HTTP status.' );
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->process_error_response( $status_code, $content_type, $body, $data );
		}

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error(
				'kdp_mockpay_invalid_response',
				sprintf( 'MockPay returned invalid JSON (HTTP %d).', $status_code ),
				array(
					'status'       => $status_code,
					'content_type' => $content_type,
				)
			);
		}

		$response_data = array();
		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) ) {
				return new WP_Error(
					'kdp_mockpay_invalid_response',
					sprintf( 'MockPay returned a non-object JSON response (HTTP %d).', $status_code ),
					array(
						'status'       => $status_code,
						'content_type' => $content_type,
					)
				);
			}

			$response_data[ $key ] = $value;
		}

		return $response_data;
	}

	/**
	 * Convert provider errors into structured WP_Error responses, point 3.1.
	 *
	 * @param int    $status_code HTTP response status.
	 * @param string $content_type Response content type.
	 * @param string $body Raw response body.
	 * @param mixed  $data Decoded response body.
	 * @return WP_Error
	 */
	private function process_error_response( $status_code, $content_type, $body, $data ) {
		$status_errors = array(
			401 => array(
				'code'    => 'kdp_mockpay_unauthorized',
				'message' => 'MockPay rejected the API credentials.',
			),
			404 => array(
				'code'    => 'kdp_mockpay_not_found',
				'message' => 'MockPay could not find the requested resource.',
			),
			409 => array(
				'code'    => 'kdp_mockpay_conflict',
				'message' => 'MockPay could not perform the requested state change.',
			),
			422 => array(
				'code'    => 'kdp_mockpay_validation_error',
				'message' => 'MockPay rejected the payment data.',
			),
			500 => array(
				'code'    => 'kdp_mockpay_server_error',
				'message' => 'MockPay encountered an internal server error.',
			),
		);
		$error_type    = $status_errors[ $status_code ] ?? array(
			'code'    => 'kdp_mockpay_http_error',
			'message' => 'MockPay request failed.',
		);
		$content_parts = explode( ';', $content_type, 2 );
		$is_problem    = 'application/problem+json' === strtolower( trim( $content_parts[0] ) );
		$problem       = is_array( $data ) ? $data : array();
		$title         = is_string( $problem['title'] ?? null ) ? sanitize_text_field( $problem['title'] ) : '';
		$detail        = is_string( $problem['detail'] ?? null ) ? sanitize_text_field( $problem['detail'] ) : '';
		$message       = '' !== $detail ? $detail : ( '' !== $title ? $title : $error_type['message'] );
		$error_data    = array(
			'status'       => $status_code,
			'content_type' => $content_type,
		);

		if ( $is_problem && JSON_ERROR_NONE !== json_last_error() ) {
			$error_data['mockpay_error_body'] = substr( $body, 0, 500 );
			$message                          = sprintf( 'MockPay returned unreadable application/problem+json (HTTP %d).', $status_code );
		} elseif ( $is_problem ) {
			$error_data['problem'] = $problem;
		} elseif ( JSON_ERROR_NONE === json_last_error() ) {
			$error_data['mockpay_error_body'] = $data;
		} elseif ( '' !== $body ) {
			$error_data['mockpay_error_body'] = substr( $body, 0, 500 );
			$message                          = sprintf( 'MockPay returned unreadable error JSON (HTTP %d).', $status_code );
		}

		return new WP_Error(
			$error_type['code'],
			sprintf( 'MockPay HTTP %d: %s', $status_code, $message ),
			$error_data
		);
	}
}
