<?php
/**
 * Plugin Name: Krokedil demo payments
 * Description: Betalplugin demo payments.
 * Version: 1.0.0
 * Author: Thyra Nyström
 * Text Domain: krokedil-demo-payments
 * Requires at least: 7
 *
 * @package KrokedilDemoPayments
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', 'kdp_init' );

/**
 * Initialize the WooCommerce gateway.
 */
function kdp_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	/**
	 * Demo payment gateway for Krokedil.
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
			$this->icon               = plugins_url( 'icon.jpg', __FILE__ );
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
					'description' => 'Adress till betal-API:t (test eller produktion).',
					'default'     => '',
				),
				'mode'          => array(
					'title'   => 'Läge',
					'type'    => 'select',
					'options' => array(
						'manual'   => 'Manuellt',
						'inline'   => 'Inline',
						'redirect' => 'Redirect',
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
					'title'       => __( 'Delad hemlighet', 'krokedil-demo-payments' ),
					'type'        => 'password',
					'description' => __( 'Nyckeln som betalleverantören signerar svaren med. Måste vara samma som i betalsidan.', 'krokedil-demo-payments' ),
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
			$mode  = $this->get_option( 'mode' );

			if ( 'manual' === $mode ) {
				$order->update_status( 'on-hold', 'Väntar på manuell betalning.' );
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}

			$endpoint = trim( $this->get_option( 'endpoint_url' ) );

			if ( '' === $endpoint || ! wp_http_validate_url( $endpoint ) ) {
				return $this->outcome_error( $order, 'Endpoint-URL saknas eller är ogiltig.' );
			}

			switch ( $mode ) {
				case 'inline':
					return $this->process_inline( $order, $endpoint );
				case 'redirect':
					return $this->process_redirect( $order, $endpoint );
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
		 * Process a redirect-based payment flow.
		 *
		 * @param WC_Order $order Order data.
		 * @param string   $endpoint Payment API endpoint.
		 * @return array
		 */
		private function process_redirect( $order, $endpoint ) {
			$secret = $this->get_option( 'shared_secret' );
			if ( '' === $secret ) {
				return $this->outcome_error( $order, 'Delad hemlighet saknas, kan inte signera förfrågan.' );
			}

			$return_url = add_query_arg(
				array(
					'order_id' => $order->get_id(),
					'key'      => $order->get_order_key(),
				),
				WC()->api_request_url( $this->id . '_return' )
			);

			$amount    = $this->format_amount( $order->get_total() );
			$signature = hash_hmac( 'sha256', implode( '|', array( $order->get_id(), $amount, $return_url ) ), $secret );

			$pay_url = add_query_arg(
				array(
					'order_id'   => $order->get_id(),
					'amount'     => $amount,
					'return_url' => rawurlencode( $return_url ),
					'signature'  => $signature,
				),
				$endpoint
			);

			return array(
				'result'   => 'success',
				'redirect' => $pay_url,
			);
		}

		/**
		 * Handle the provider callback after redirect.
		 */
		public function handle_return() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order_id = absint( wp_unslash( $_GET['order_id'] ?? 0 ) );
			$key      = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
			$status   = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$reference = sanitize_text_field( wp_unslash( $_GET['transaction_id'] ?? '' ) );
			$order     = $order_id ? wc_get_order( $order_id ) : false;

			if ( ! $order || ! $order->key_is_valid( $key ) || $this->id !== $order->get_payment_method() ) {
				wp_die( 'Ogiltig order.', 'Fel', array( 'response' => 400 ) );
			}

			$secret    = $this->get_option( 'shared_secret' );
			$amount    = sanitize_text_field( wp_unslash( $_GET['amount'] ?? '' ) );
			$signature = sanitize_text_field( wp_unslash( $_GET['signature'] ?? '' ) );

			if ( '' === $secret ) {
				$this->log( 'Delad hemlighet saknas i inställningarna, retur kan inte verifieras.', 'error', $order );
				wp_die( 'Betalsättet är inte korrekt konfigurerat.', 'Fel', array( 'response' => 500 ) );
			}

			$expected = hash_hmac( 'sha256', implode( '|', array( $order_id, $status, $reference, $amount ) ), $secret );

			if ( ! hash_equals( $expected, $signature ) ) {
				$this->log( 'OGILTIG SIGNATUR vid retur. Möjligt manipulationsförsök. Status i URL: ' . $status, 'error', $order );
				$order->add_order_note( 'Retur med ogiltig signatur avvisades. Orderstatus ändrades inte.' );
				wc_add_notice( 'Betalningen kunde inte verifieras. Kontakta butiken om du har betalat.', 'error' );
				wp_safe_redirect( wc_get_checkout_url() );
				exit;
			}

			if ( $amount !== $this->format_amount( $order->get_total() ) ) {
				$result = $this->outcome_unknown( $order, "Beloppet stämmer inte: betalt {$amount}, ordern är " . $this->format_amount( $order->get_total() ) . '.' );
				wp_safe_redirect( $result['redirect'] );
				exit;
			}

			$status_text = '' !== $status ? $status : 'saknas';
			$this->log( 'Kunden kom tillbaka från betalsidan med status: ' . $status_text, 'info', $order );

			if ( $order->is_paid() ) {
				wp_safe_redirect( $this->get_return_url( $order ) );
				exit;
			}

			switch ( $status ) {
				case 'approved':
					$result = $this->outcome_approved( $order, $reference );
					break;
				case 'pending':
					$result = $this->outcome_pending( $order, $reference );
					break;
				case 'declined':
					$result = $this->outcome_declined( $order, $reference, 'nekad på betalsidan' );
					break;
				case 'cancelled':
					$this->log( 'Kunden avbröt betalningen.', 'info', $order );
					$order->add_order_note( 'Kunden avbröt betalningen på betalsidan.' );
					wc_add_notice( 'Betalningen avbröts.', 'notice' );
					$result = array( 'result' => 'failure' );
					break;
				default:
					$result = $this->outcome_unknown( $order, 'Okänd status vid retur: ' . $status_text );
			}

			wp_safe_redirect( 'success' === $result['result'] ? $result['redirect'] : wc_get_checkout_url() );
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

	add_filter( 'woocommerce_payment_gateways', 'kdp_add_gateway' );

	/**
	 * Support for WooCommerce blocks checkout.
	 */
	class KDP_Blocks_Support extends AbstractPaymentMethodType {
		/**
		 * Payment method slug.
		 *
		 * @var string
		 */
		protected $name = 'kdp_gateway';

		/**
		 * Initialize the payment method settings.
		 */
		public function initialize() {
			$this->name     = 'kdp_gateway';
			$this->settings = get_option( 'woocommerce_kdp_gateway_settings', array() );
		}

		/**
		 * Check whether the payment method is active.
		 *
		 * @return bool
		 */
		public function is_active() {
			return isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
		}

		/**
		 * Register the frontend script for the payment method.
		 *
		 * @return array
		 */
		public function get_payment_method_script_handles() {
			wp_register_script(
				'kdp-blocks',
				plugins_url( 'index.js', __FILE__ ),
				array( 'wc-blocks-registry', 'wc-settings', 'wp-element' ),
				'1.0.0',
				true
			);
			return array( 'kdp-blocks' );
		}

		/**
		 * Provide data for the block payment method.
		 *
		 * @return array
		 */
		public function get_payment_method_data() {
			return array(
				'name'        => $this->name,
				'title'       => $this->settings['title'] ?? '',
				'description' => $this->settings['description'] ?? '',
				'supports'    => array( 'products' ),
			);
		}
	}

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( $registry ) {
			$registry->register( new KDP_Blocks_Support() );
		}
	);
}

/**
 * Add the Krokedil gateway to WooCommerce.
 *
 * @param array $methods Payment methods list.
 * @return array
 */
function kdp_add_gateway( $methods ) {
	$methods[] = 'KDP_WC_Gateway';
	return $methods;
}

/**
 * Reduce the icon width on the checkout page.
 */
add_action( 'wp_head', 'kdp_gateway_icon_style' );
/**
 * Reduce the icon width on the checkout page.
 */
function kdp_gateway_icon_style() {
	echo '<style>
		.wc_payment_method label img {
			max-height: 30px !important;
			width: auto !important;
		}
	</style>';
}
