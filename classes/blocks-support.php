<?php
/**
 * Stöd för WooCommerce Blocks.
 *
 * @package KrokedilDemoPayments
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Lägger till stöd för WooCommerce Blocks.
 */
class KDP_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Betalningsmetodens ID.
	 *
	 * @var string
	 */
	protected $name = 'kdp_gateway';

	/**
	 * Initierar betalningsmetoden.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->name     = 'kdp_gateway';
		$settings       = get_option( 'woocommerce_kdp_gateway_settings', array() );
		$this->settings = is_array( $settings ) ? $settings : array();
	}

	/**
	 * Ser till att betalningsmetoden är aktiv.
	 *
	 * @return bool
	 */
	public function is_active() {
		return isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	/**
	 * Hämtar skript som behövs för betalningsmetoden.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'kdp-blocks',
			plugin_dir_url( __DIR__ ) . 'index.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element' ),
			'1.0.0',
			true
		);
		return array( 'kdp-blocks' );
	}

	/**
	 * Hämtar data som behövs för betalningsmetoden.
	 *
	 * @return array<string, mixed>
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
