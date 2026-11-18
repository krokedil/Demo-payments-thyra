<?php
/**
 * Plugin Name: Krokedil demo payments
 * Description: Betalplugin demo payments
 * Version: 1.0.0
 * Author: Thyra Nyström
 * Text Domain: krokedil-demo-payments
 * Requires at least: 7
 *
 * @package KrokedilDemoPayments
 */

// Abspath.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Funktionalitet för att WooCommerce Blocks ska känna igen pluginet.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);


// Prefix kdp - Krokedil demo payments.

// Från claude, kunde ej hitta hur det gjordes.
add_action( 'plugins_loaded', 'kdp_init' );

/**
 * Laddar betalningsmetoden när alla plugins är inlästa.
 *
 * @return void
 */
function kdp_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		// Visa felmeddelande.
		return;
	}

	// Klasserna laddas först här, när WooCommerce finns.
	require_once __DIR__ . '/classes/gateway.php';
	require_once __DIR__ . '/classes/blocks-support.php';
	require_once __DIR__ . '/classes/assets.php';

	// Laddar CSS:en för ikonen i kassan.
	add_action( 'wp_enqueue_scripts', array( 'KDP_Assets', 'enqueue_checkout_style' ) );

	// Filter, så att WooCommerce känner igen betalningsmetoden.
	add_filter( 'woocommerce_payment_gateways', 'kdp_add_gateway' );

	// Copilot, registrerar betalningsmetoden för WooCommerce Blocks.
	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( $registry ) {
			$registry->register( new KDP_Blocks_Support() );
		}
	);
}


/**
 * Lägger till betalningsmetoden i WooCommerce.
 *
 * @param string[] $methods Betalningsmetoder.
 * @return string[]
 */
function kdp_add_gateway( $methods ) {
	$methods[] = 'KDP_WC_Gateway';
	return $methods;
}
