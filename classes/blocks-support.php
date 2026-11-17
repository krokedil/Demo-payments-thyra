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
	 *
	 * @return void
	 */
	public function initialize() {
		$settings       = get_option( 'woocommerce_kdp_gateway_settings', array() );
		$this->settings = is_array( $settings ) ? $settings : array();
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
	 * @return list<string>
	 */
	public function get_payment_method_script_handles() {
		$script_url = plugins_url( '../assets/js/index.js', __FILE__ );
		if ( '' === $script_url ) {
			_doing_it_wrong( __METHOD__, 'The payment method script URL must not be empty.', '1.0.0' );
			return array();
		}

		wp_register_script(
			'kdp-blocks',
			$script_url,
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element' ),
			'1.0.0',
			true
		);
		return array( 'kdp-blocks' );
	}

	/**
	 * Provide data for the block payment method.
	 *
	 * @return array{
	 *     name: string,
	 *     title: string,
	 *     description: string,
	 *     supports: list<string>
	 * }
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
