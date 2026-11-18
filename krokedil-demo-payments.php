<?php
/**
 * Plugin Name: Krokedil demo payments
 * Description: Betalplugin demo payments
 * Version: 1.0.0
 * Author: Thyra Nyström
 * Text Domain: krokedil-demo-payments
 * Requires at least: 7
 * Requires Plugins: woocommerce
 *
 * @package KrokedilDemoPayments
 */

// Stoppar direktanrop av filen utanför WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Talar om för WooCommerce att pluginet fungerar med HPOS (custom_order_tables) och blockkassan.
// Med HPOS sparas ordrar i egna tabeller.
add_action(
	'before_woocommerce_init',
	function () {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
);

add_action( 'plugins_loaded', 'kdp_init' );

/**
 * Laddar betalningsmetoden när alla plugins är inlästa.
 *
 * @return void
 */
function kdp_init() {
	// "Requires Plugins: woocommerce" i headern gör att WordPress inte låter pluginet aktiveras
	// utan WooCommerce, och visar själv ett meddelande under Tillägg. Kontrollen här skyddar om
	// WooCommerce ändå saknas, till exempel om mappen har tagits bort.
	if ( ! class_exists( 'WooCommerce' ) ) {
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

	// Registrerar betalningsmetoden i blockkassan.
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
