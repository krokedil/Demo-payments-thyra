<?php
/**
 * Plugin bootstrap and registration.
 *
 * @package KrokedilDemoPayments
 */

namespace KrokedilDemoPayments;

/**
 * Main plugin bootstrapper.
 */
final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Assets helper instance.
	 *
	 * @var KDP_Assets
	 */
	private $assets;

	/**
	 * Create or return the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function boot() {
		require_once __DIR__ . '/assets.php';

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->assets = new \KDP_Assets();
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'load_gateway' ) );
		add_action( 'wp_head', array( $this->assets, 'enqueue_checkout_style' ) );
	}

	/**
	 * Declare compatibility with WooCommerce Blocks.
	 */
	public function declare_compatibility() {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', dirname( __DIR__ ) . '/krokedil-demo-payments.php', true );
		}
	}

	/**
	 * Load the gateway and block registration classes.
	 */
	public function load_gateway() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		require_once __DIR__ . '/gateway.php';
		require_once __DIR__ . '/blocks-support.php';
		require_once __DIR__ . '/mockpay-client.php';
		require_once __DIR__ . '/mockpay-order-payload.php';

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
	}

	/**
	 * Register the gateway with WooCommerce.
	 *
	 * @param array $methods Existing payment gateways.
	 * @return array
	 */
	public function register_gateway( $methods ) {
		$methods[] = 'KDP_WC_Gateway';
		return $methods;
	}
}
