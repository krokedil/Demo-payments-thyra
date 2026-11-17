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
		$assets = new \KDP_Assets();
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'load_gateway' ) );
		add_action( 'wp_enqueue_scripts', array( $assets, 'enqueue_checkout_style' ) );
	}

	/**
	 * Declare compatibility with WooCommerce order storage and Blocks.
	 *
	 * @return void
	 */
	public function declare_compatibility() {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			$plugin_file = dirname( __DIR__ ) . '/krokedil-demo-payments.php';
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', $plugin_file, true ); // Hpos kompatibilitet.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', $plugin_file, true );
		}
	}

	/**
	 * Load the gateway and block registration classes.
	 *
	 * @return void
	 */
	public function load_gateway() {
		// The plugin header blocks normal activation without WooCommerce; keep this guard for later deactivation too.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		require_once __DIR__ . '/gateway.php';
		require_once __DIR__ . '/blocks-support.php';
		require_once __DIR__ . '/mockpay-client.php';

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
	}

	/**
	 * Register the gateway with WooCommerce.
	 *
	 * @param array<int, string> $methods Existing payment gateways.
	 * @return array<int, string>
	 */
	public function register_gateway( $methods ) {
		$methods[] = 'KDP_WC_Gateway';
		return $methods;
	}
}
