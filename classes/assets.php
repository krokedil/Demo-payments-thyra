<?php
/**
 * Frontend assets used by the payment method.
 *
 * @package KrokedilDemoPayments
 */

/**
 * Register and enqueue frontend styles.
 */
class KDP_Assets {
	/**
	 * Enqueue the checkout icon styling.
	 */
	public function enqueue_checkout_style() {
		wp_register_style( 'kdp-checkout', plugins_url( '../assets/css/frontend.css', __FILE__ ), array(), '1.0.0' );
		wp_enqueue_style( 'kdp-checkout' );
	}
}
