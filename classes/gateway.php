<?php
/**
 * Manual payment gateway and MockPay API settings.
 *
 * @package KrokedilDemoPayments
 */

/**
 * Manual WooCommerce payment method.
 */
class KDP_WC_Gateway extends WC_Payment_Gateway {
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
			function () {
				$this->process_admin_options();
			}
		);

		add_action( 'woocommerce_thankyou_kdp_gateway', array( $this, 'kdp_thankyou_page' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'kdp_email_instructions' ), 10, 3 );
	}

	/**
	 * Set up payment method and MockPay connection settings.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'           => array(
				'title'   => __( 'Aktivera', 'krokedil-demo-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Aktivera Krokedil Demo Payments', 'krokedil-demo-payments' ),
				'default' => 'yes',
			),
			'title'             => array(
				'title'       => __( 'Rubrik', 'krokedil-demo-payments' ),
				'type'        => 'text',
				'description' => __( 'Visas som betalningsalternativ i kassan.', 'krokedil-demo-payments' ),
				'default'     => __( 'Krokedil Demo Payments', 'krokedil-demo-payments' ),
				'desc_tip'    => true,
			),
			'description'       => array(
				'title'       => __( 'Beskrivning', 'krokedil-demo-payments' ),
				'type'        => 'textarea',
				'description' => __( 'Visas under betalningsalternativet i kassan.', 'krokedil-demo-payments' ),
				'default'     => __( 'Betala med Krokedil Demo Payments!', 'krokedil-demo-payments' ),
				'desc_tip'    => true,
			),
			'instructions'      => array(
				'title'       => __( 'Instruktioner', 'krokedil-demo-payments' ),
				'type'        => 'textarea',
				'description' => __( 'Visas på tack-sidan efter genomförd betalning.', 'krokedil-demo-payments' ),
				'default'     => __( 'Tack för din beställning!', 'krokedil-demo-payments' ),
				'desc_tip'    => true,
			),
			'mode'              => array(
				'title'       => __( 'Betalläge', 'krokedil-demo-payments' ),
				'type'        => 'select',
				'description' => __( 'Välj Manuellt eller Redirect (MockPay). ', 'krokedil-demo-payments' ),
				'options'     => array(
					'manual'   => __( 'Manuellt', 'krokedil-demo-payments' ),
					'redirect' => __( 'Redirect (MockPay)', 'krokedil-demo-payments' ),
				),
				'default'     => 'manual',
			),
			'api_environment'   => array(
				'title'   => __( 'API-miljö', 'krokedil-demo-payments' ),
				'type'    => 'select',
				'options' => array(
					'test' => __( 'Test', 'krokedil-demo-payments' ),
				),
				'default' => 'test',
			),
			'test_api_url'      => array(
				'title'       => __( 'Test API-URL', 'krokedil-demo-payments' ),
				'type'        => 'url',
				'description' => __( 'MockPays testbasadress som WordPress-servern når, utan /payments.', 'krokedil-demo-payments' ),
				'default'     => '',
			),
			'test_api_username' => array(
				'title'       => __( 'Test API-användarnamn', 'krokedil-demo-payments' ),
				'type'        => 'text',
				'description' => __( 'Användarnamnet för MockPays testmiljö.', 'krokedil-demo-payments' ),
				'default'     => 'username',
			),
			'test_api_password' => array(
				'title'       => __( 'Test API-lösenord', 'krokedil-demo-payments' ),
				'type'        => 'password',
				'description' => __( 'Används för Basic Auth och som nyckel för callback-signaturer i testmiljön.', 'krokedil-demo-payments' ),
				'default'     => '',
			),
		);
	}

	/**
	 * Put the order on hold for manual payment confirmation.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array{result: 'success'|'failure', redirect?: string}
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( 'Ordern kunde inte hittas. Kontakta butiken.', 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_status( 'on-hold', 'Väntar på manuell betalning.' );

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Render the thank-you text on the order confirmation page.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function kdp_thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || 'kdp_gateway' !== $order->get_payment_method() ) {
			return;
		}

		if ( $order->is_paid() || $order->has_status( 'on-hold' ) ) {
			echo wp_kses_post( $this->get_instructions() );
		}
	}

	/**
	 * Add payment instructions to the email.
	 *
	 * @param WC_Order|int $order Order object or order ID.
	 * @param bool         $sent_to_admin Whether the email is for admin.
	 * @param bool         $plain_text Whether the email is plain text.
	 * @return void
	 */
	public function kdp_email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order instanceof WC_Order || 'kdp_gateway' !== $order->get_payment_method() ) {
			return;
		}

		if ( $order->is_paid() || $order->has_status( 'on-hold' ) ) {
			echo wp_kses_post( $this->get_instructions( $plain_text ) );
		}
	}

	/**
	 * Get configured instructions in HTML or plain-text email format.
	 *
	 * @param bool $plain_text Whether to return plain text.
	 * @return string
	 */
	private function get_instructions( $plain_text = false ) {
		$instructions = trim( $this->get_option( 'instructions' ) );
		if ( '' === $instructions ) {
			return '';
		}

		if ( $plain_text ) {
			return wp_strip_all_tags( wp_kses_post( $instructions ) );
		}

		return wpautop( $instructions );
	}
}
