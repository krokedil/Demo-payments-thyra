<?php
/**
 * Main payment gateway for Krokedil demo payments.
 *
 * @package KrokedilDemoPayments
 */

/**
 * Demo payment gateway for WooCommerce.
 */
class KDP_WC_Gateway extends WC_Payment_Gateway {
	const LOG_SOURCE = 'krokedil-demo-payments';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'kdp_gateway';
		$this->method_title       = 'Krokedil Demo Payments';
		$this->method_description = 'En demo-betalningsmetod';
		$this->icon               = plugins_url( '../icon.jpg', __FILE__ );
		$this->has_fields         = false;

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->get_option( 'enabled' );
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);

		add_action( 'woocommerce_thankyou_kdp_gateway', array( $this, 'kdp_thankyou_page' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'kdp_email_instructions' ), 10, 3 );
		add_action( 'woocommerce_api_' . $this->id . '_return', array( $this, 'handle_return' ) );
	}

	/**
	 * Set up payment method settings.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'       => array(
				'title'   => __( 'Aktivera', 'krokedil-demo-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Aktivera Krokedil Demo Payments', 'krokedil-demo-payments' ),
				'default' => 'yes',
			),
			'title'         => array(
				'title'       => __( 'Rubrik', 'krokedil-demo-payments' ),
				'type'        => 'text',
				'description' => __( 'Visas som betalningsalternativ i kassan.', 'krokedil-demo-payments' ),
				'default'     => __( 'Krokedil Demo Payments', 'krokedil-demo-payments' ),
				'desc_tip'    => true,
			),
			'description'   => array(
				'title'       => __( 'Beskrivning', 'krokedil-demo-payments' ),
				'type'        => 'textarea',
				'description' => __( 'Visas under betalningsalternativet i kassan.', 'krokedil-demo-payments' ),
				'default'     => __( 'Betala med Krokedil Demo Payments!', 'krokedil-demo-payments' ),
				'desc_tip'    => true,
			),
			'instructions'  => array(
				'title'       => __( 'Instruktioner', 'krokedil-demo-payments' ),
				'type'        => 'textarea',
				'description' => __( 'Visas på tack-sidan efter genomförd betalning.', 'krokedil-demo-payments' ),
				'default'     => __( 'Tack för din beställning!', 'krokedil-demo-payments' ),
				'desc_tip'    => true,
			),
			'endpoint_url'  => array(
				'title'       => 'Endpoint-URL',
				'type'        => 'url',
				'description' => 'MockPays basadress, till exempel http://localhost:8888. Lägg inte till /payments.',
				'default'     => '',
			),
			'api_username'  => array(
				'title'       => __( 'MockPay API-användarnamn', 'krokedil-demo-payments' ),
				'type'        => 'text',
				'description' => __( 'Värdet för AUTH_USERNAME i MockPay.', 'krokedil-demo-payments' ),
				'default'     => 'username',
			),
			'mode'          => array(
				'title'   => 'Läge',
				'type'    => 'select',
				'options' => array(
					'manual'   => 'Manuellt',
					'inline'   => 'Inline (eget API)',
					'redirect' => 'MockPay (redirect)',
				),
				'default' => 'manual',
			),
			'logging'       => array(
				'title'       => __( 'Loggning', 'krokedil-demo-payments' ),
				'type'        => 'checkbox',
				'label'       => __( 'Logga händelser och API-anrop', 'krokedil-demo-payments' ),
				'description' => sprintf(
					/* translators: %s: WooCommerce log URL. */
					__( 'Loggarna finns under <a href="%s">WooCommerce → Status → Loggar</a> med källan "krokedil-demo-payments".', 'krokedil-demo-payments' ),
					admin_url( 'admin.php?page=wc-status&tab=logs' )
				),
				'default'     => 'yes',
			),
			'shared_secret' => array(
				'title'       => __( 'MockPay API-lösenord', 'krokedil-demo-payments' ),
				'type'        => 'password',
				'description' => __( 'Värdet för AUTH_PASSWORD i MockPay. Används tillsammans med API-användarnamnet för Basic Auth.', 'krokedil-demo-payments' ),
				'default'     => '',
			),
		);
	}

	/**
	 * Process a payment for an order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( 'Ordern kunde inte hittas. Kontakta butiken.', 'error' );
			return array( 'result' => 'failure' );
		}

		$mode = $this->get_option( 'mode' );

		if ( 'manual' === $mode ) {
			$order->update_status( 'on-hold', 'Väntar på manuell betalning.' );
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		$endpoint = trim( $this->get_option( 'endpoint_url' ) );

		if (
			'' === $endpoint
			|| ( 'redirect' === $mode && ! KDP_MockPay_Client::is_valid_http_url( $endpoint ) )
			|| ( 'redirect' !== $mode && ! wp_http_validate_url( $endpoint ) )
		) {
			return $this->outcome_error( $order, 'Endpoint-URL saknas eller är ogiltig.' );
		}

		switch ( $mode ) {
			case 'inline':
				return $this->process_inline( $order, $endpoint );
			case 'redirect':
				return $this->process_mockpay_payment( $order, $endpoint );
			default:
				return $this->outcome_error( $order, 'Okänt läge: ' . $mode );
		}
	}

	/**
	 * Render the thank-you text on the order confirmation page.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function kdp_thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || 'kdp_gateway' !== $order->get_payment_method() ) {
			return;
		}

		if ( $order->is_paid() ) {
			echo '<p>Tack! Din betalning är mottagen.</p>';
		} elseif ( $order->has_status( 'on-hold' ) && 'manual' === $this->get_option( 'mode' ) ) {
			echo '<p>Tack för din beställning! Vänligen överför summan i orderbekräftelsen till vårt konto.</p>';
		} elseif ( $order->has_status( 'on-hold' ) ) {
			echo '<p>Tack! Vi väntar på bekräftelse av din betalning och mejlar dig när den är klar.</p>';
		}
	}

	/**
	 * Add payment instructions to the email.
	 *
	 * @param WC_Order $order Order object.
	 * @param bool     $sent_to_admin Whether the email is for admin.
	 * @param bool     $plain_text Whether the email is plain text.
	 */
	public function kdp_email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		$order = is_object( $order ) ? $order : wc_get_order( $order );

		if ( ! $order || 'kdp_gateway' !== $order->get_payment_method() ) {
			return;
		}

		if ( $order->is_paid() ) {
			echo '<p>Tack! Din betalning är mottagen.</p>';
		} elseif ( $order->has_status( 'on-hold' ) && 'manual' === $this->get_option( 'mode' ) ) {
			echo '<p>Tack för din beställning! Vänligen överför summan i orderbekräftelsen till vårt konto.</p>';
		} elseif ( $order->has_status( 'on-hold' ) ) {
			echo '<p>Tack! Vi väntar på bekräftelse av din betalning och mejlar dig när den är klar.</p>';
		}
	}

	/**
	 * Process inline payment requests.
	 *
	 * @param WC_Order $order Order data.
	 * @param string   $endpoint Payment API endpoint.
	 * @return array
	 */
	private function process_inline( $order, $endpoint ) {
		$this->log( sprintf( 'Skickar betalförfrågan till %s (%s %s).', $endpoint, $order->get_total(), $order->get_currency() ), 'info', $order );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'order_id' => $order->get_id(),
						'amount'   => $order->get_total(),
						'currency' => $order->get_currency(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( $this->is_timeout( $response ) ) {
				return $this->outcome_unknown( $order, 'Timeout: ' . $response->get_error_message() );
			}
			return $this->outcome_error( $order, 'Anslutningsfel: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$this->log( "Svar från leverantören: HTTP {$code}, body: " . substr( $raw, 0, 500 ), 'info', $order );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			return $this->outcome_error( $order, "HTTP {$code}: " . substr( $raw, 0, 200 ) );
		}

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) || empty( $data['status'] ) ) {
			return $this->outcome_unknown( $order, 'Ogiltig JSON: ' . substr( $raw, 0, 200 ) );
		}

		$reference = sanitize_text_field( $data['transaction_id'] ?? '' );

		switch ( $data['status'] ) {
			case 'approved':
				return $this->outcome_approved( $order, $reference );
			case 'pending':
				return $this->outcome_pending( $order, $reference );
			case 'declined':
				return $this->outcome_declined( $order, $reference, sanitize_text_field( $data['reason'] ?? 'okänd orsak' ) );
			default:
				return $this->outcome_unknown( $order, 'Okänd status från leverantören: ' . sanitize_text_field( $data['status'] ) );
		}
	}

	/**
	 * Create a MockPay payment and redirect the customer to its hosted page.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $endpoint MockPay base URL.
	 * @return array
	 */
	private function process_mockpay_payment( $order, $endpoint ) {
		$username = trim( $this->get_option( 'api_username' ) );
		$password = $this->get_option( 'shared_secret' );

		if ( '' === $username || '' === $password ) {
			return $this->outcome_error( $order, 'MockPay API-användarnamn eller lösenord saknas.' );
		}

		$payload = KDP_MockPay_Order_Payload::from_order( $order );

		$payload['merchant_urls'] = array(
			'success' => $this->mockpay_return_url( $order, 'success' ),
			'failed'  => $this->mockpay_return_url( $order, 'failed' ),
			'cancel'  => $this->mockpay_return_url( $order, 'cancel' ),
		);

		$client  = new KDP_MockPay_Client( $endpoint, $username, $password );
		$payment = $client->create_payment( $payload );

		if ( is_wp_error( $payment ) ) {
			if ( $this->is_timeout( $payment ) ) {
				return $this->outcome_unknown( $order, 'Timeout när MockPay skapade betalningen: ' . $payment->get_error_message() );
			}

			return $this->outcome_error( $order, $payment->get_error_message() );
		}

		$payment_id  = is_string( $payment['id'] ?? null ) ? sanitize_text_field( $payment['id'] ) : '';
		$payment_url = is_string( $payment['payment_page_url'] ?? null ) ? esc_url_raw( $payment['payment_page_url'] ) : '';

		if ( '' === $payment_id || ! KDP_MockPay_Client::is_valid_http_url( $payment_url ) ) {
			return $this->outcome_unknown( $order, 'MockPay skapade ett svar utan giltigt betalnings-ID eller betalside-URL.' );
		}

		$order->update_meta_data( '_kdp_mockpay_payment_id', $payment_id );
		$order->save();
		$this->log( 'MockPay-betalning skapad: ' . $payment_id, 'info', $order );

		return array(
			'result'   => 'success',
			'redirect' => $payment_url,
		);
	}

	/**
	 * Build a return URL for MockPay.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $outcome Return outcome.
	 * @return string
	 */
	private function mockpay_return_url( $order, $outcome ) {
		return add_query_arg(
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
				'outcome'  => $outcome,
			),
			WC()->api_request_url( $this->id . '_return' )
		);
	}

	/**
	 * Verify the MockPay payment after the customer returns and capture it.
	 */
	public function handle_return() {
		$order_id = is_scalar( $_GET['order_id'] ?? null ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$key      = is_string( $_GET['key'] ?? null ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$outcome  = is_string( $_GET['outcome'] ?? null ) ? sanitize_key( wp_unslash( $_GET['outcome'] ) ) : '';
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || ! $order->key_is_valid( $key ) || $this->id !== $order->get_payment_method() ) {
			wp_die( 'Ogiltig order.', 'Fel', array( 'response' => 400 ) );
		}

		$payment_meta = $order->get_meta( '_kdp_mockpay_payment_id', true );
		$payment_id   = is_string( $payment_meta ) ? sanitize_text_field( $payment_meta ) : '';
		if ( '' === $payment_id ) {
			wp_die( 'MockPay-betalningen saknas för ordern.', 'Fel', array( 'response' => 400 ) );
		}

		$client  = new KDP_MockPay_Client(
			trim( $this->get_option( 'endpoint_url' ) ),
			trim( $this->get_option( 'api_username' ) ),
			$this->get_option( 'shared_secret' )
		);
		$payment = $client->get_payment( $payment_id );

		if ( is_wp_error( $payment ) ) {
			$this->redirect_unknown_return( $order, 'Kunde inte verifiera betalningen hos MockPay: ' . $payment->get_error_message() );
		}

		$api_payment_id = $payment['id'] ?? null;
		$api_currency   = $payment['currency'] ?? null;

		if (
			! is_string( $api_payment_id )
			|| ! hash_equals( $payment_id, $api_payment_id )
			|| ! is_int( $payment['order_total'] ?? null )
			|| (int) wc_add_number_precision( (float) $order->get_total() ) !== $payment['order_total']
			|| ! is_string( $api_currency )
			|| 0 !== strcasecmp( $order->get_currency(), $api_currency )
		) {
			$this->redirect_unknown_return( $order, 'MockPay-betalningen stämmer inte med orderns ID, belopp eller valuta.' );
		}

		$payment_status = is_string( $payment['status'] ?? null ) ? sanitize_key( $payment['status'] ) : '';

		if ( 'success' === $outcome ) {
			$this->complete_mockpay_payment( $order, $client, $payment_id, $payment_status );
		}

		if ( 'cancel' === $outcome && 'created' === $payment_status ) {
			$cancelled = $client->cancel_payment( $payment_id );
			if ( is_wp_error( $cancelled ) || 'cancelled' !== ( $cancelled['status'] ?? '' ) ) {
				$message = is_wp_error( $cancelled ) ? $cancelled->get_error_message() : 'MockPay bekräftade inte avbokningen.';
				$this->redirect_unknown_return( $order, $message );
			}
		} elseif ( in_array( $outcome, array( 'cancel', 'failed' ), true ) && 'created' !== $payment_status ) {
			$this->redirect_unknown_return( $order, 'MockPay returnerade ett oväntat betalningsutfall: ' . $payment_status );
		} elseif ( ! in_array( $outcome, array( 'cancel', 'failed' ), true ) ) {
			wp_die( 'Ogiltigt betalningsutfall.', 'Fel', array( 'response' => 400 ) );
		}

		$order->add_order_note( 'MockPay-checkouten avbröts eller misslyckades. Betalningen kan försökas igen.' );
		wc_add_notice(
			'Betalningen slutfördes inte. Du kan försöka igen eller välja ett annat betalsätt.',
			'notice'
		);
		wp_safe_redirect( $order->get_checkout_payment_url() );
		exit;
	}

	/**
	 * Activate an authorized MockPay payment and complete the WooCommerce order.
	 *
	 * @param WC_Order           $order WooCommerce order.
	 * @param KDP_MockPay_Client $client MockPay client.
	 * @param string             $payment_id MockPay payment ID.
	 * @param string             $payment_status Current MockPay status.
	 */
	private function complete_mockpay_payment( $order, $client, $payment_id, $payment_status ) {
		if ( $order->is_paid() && 'activated' === $payment_status ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		if ( 'activated' !== $payment_status ) {
			if ( 'authorized' !== $payment_status ) {
				$this->redirect_unknown_return( $order, 'MockPay-betalningen är inte godkänd: ' . $payment_status );
			}

			$activated = $client->activate_payment( $payment_id );
			if ( is_wp_error( $activated ) ) {
				$latest = $client->get_payment( $payment_id );
				if ( is_wp_error( $latest ) || 'activated' !== ( $latest['status'] ?? '' ) ) {
					$message = is_wp_error( $activated ) ? $activated->get_error_message() : 'MockPay kunde inte aktivera betalningen.';
					$this->redirect_unknown_return( $order, $message );
				}
			} elseif ( 'activated' !== ( $activated['status'] ?? '' ) ) {
				$this->redirect_unknown_return( $order, 'MockPay bekräftade inte aktiveringen.' );
			}
		}

		$this->outcome_approved( $order, $payment_id );
		wp_safe_redirect( $this->get_return_url( $order ) );
		exit;
	}

	/**
	 * Put the order on hold when the MockPay result cannot safely be verified.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $message Reason for the unknown outcome.
	 */
	private function redirect_unknown_return( $order, $message ) {
		$result = $this->outcome_unknown( $order, $message );
		wp_safe_redirect( $result['redirect'] );
		exit;
	}

	/**
	 * Mark an order as approved and complete the payment.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $reference Transaction reference.
	 * @return array
	 */
	private function outcome_approved( $order, $reference ) {
		$reference_text = '' !== $reference ? $reference : 'saknas';
		$this->log( 'Godkänd. Referens: ' . $reference_text, 'info', $order );
		$order->payment_complete( $reference );

		$order->add_order_note(
			'' !== $reference
				? 'Betalning godkänd. Leverantörens referens: ' . $reference
				: 'Betalning godkänd, men leverantören skickade ingen referens. Stäm av manuellt.'
		);

		WC()->cart->empty_cart();
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Mark an order as pending while awaiting confirmation.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $reference Transaction reference.
	 * @return array
	 */
	private function outcome_pending( $order, $reference ) {
		$reference_text = '' !== $reference ? $reference : 'saknas';
		$this->log( 'Väntande. Referens: ' . $reference_text, 'info', $order );

		$this->save_reference( $order, $reference );
		$order->update_status( 'on-hold', 'Betalningen väntar på bekräftelse från leverantören. Referens: ' . $reference_text );

		WC()->cart->empty_cart();
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Handle a declined payment.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $reference Transaction reference.
	 * @param string   $reason Payment decline reason.
	 * @return array
	 */
	private function outcome_declined( $order, $reference, $reason ) {
		$reference_text = '' !== $reference ? $reference : 'saknas';
		$this->log( "Nekad ({$reason}). Referens: " . $reference_text, 'warning', $order );

		$this->save_reference( $order, $reference );
		wc_increase_stock_levels( $order );
		$order->update_status( 'failed', "Betalningen nekades ({$reason}). Referens: " . $reference_text );

		wc_add_notice( 'Betalningen nekades. Försök igen eller välj ett annat betalsätt.', 'error' );
		return array( 'result' => 'failure' );
	}

	/**
	 * Handle a technical failure during payment processing.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $message Error message.
	 * @return array
	 */
	private function outcome_error( $order, $message ) {
		$this->log( 'Tekniskt fel: ' . $message, 'error', $order );
		wc_increase_stock_levels( $order );
		$order->update_status( 'failed', 'Tekniskt fel, ingen betalning genomförd: ' . $message );
		wc_add_notice( 'Betalningen kunde inte genomföras just nu. Försök igen om en stund.', 'error' );
		return array( 'result' => 'failure' );
	}

	/**
	 * Handle an unknown outcome and put the order on hold.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $message Reason for the unknown outcome.
	 * @return array
	 */
	private function outcome_unknown( $order, $message ) {
		$this->log( 'OKÄNT UTFALL, kräver manuell kontroll: ' . $message, 'error', $order );
		$order->update_status( 'on-hold', 'OKÄNT UTFALL. Kontrollera hos betalleverantören innan kunden debiteras igen. ' . $message );
		WC()->cart->empty_cart();
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Save the external transaction reference.
	 *
	 * @param WC_Order $order Order object.
	 * @param string   $reference Transaction reference.
	 */
	private function save_reference( $order, $reference ) {
		if ( '' !== $reference ) {
			$order->set_transaction_id( $reference );
			$order->save();
		}
	}

	/**
	 * Detect timeout errors from WooCommerce HTTP requests.
	 *
	 * @param WP_Error $error Error object.
	 * @return bool
	 */
	private function is_timeout( $error ) {
		$message = strtolower( $error->get_error_message() );
		return str_contains( $message, 'timed out' ) || str_contains( $message, 'curl error 28' );
	}

	/**
	 * Log a message to WooCommerce logging.
	 *
	 * @param string   $message Log message.
	 * @param string   $level Log level.
	 * @param WC_Order $order Optional order.
	 */
	private function log( $message, $level = 'info', $order = null ) {
		if ( 'yes' !== $this->get_option( 'logging' ) ) {
			return;
		}

		if ( $order ) {
			$message = sprintf( 'Order %d: %s', $order->get_id(), $message );
		}

		wc_get_logger()->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
	}

	/**
	 * Format amount values consistently for signatures and callbacks.
	 *
	 * @param float $amount Amount to format.
	 * @return string
	 */
	private function format_amount( $amount ) {
		return number_format( (float) $amount, 2, '.', '' );
	}
}
