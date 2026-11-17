<?php
/**
 * WooCommerce Blocks support for the payment method.
 *
 * @package KrokedilDemoPayments
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Register the payment method with WooCommerce Blocks.
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
			plugins_url( '../assets/js/index.js', __FILE__ ),
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
