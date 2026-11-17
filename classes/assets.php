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
	 *
	 * @return void
	 */
	public function enqueue_checkout_style() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_enqueue_style( 'kdp-checkout', plugins_url( '../assets/css/frontend.css', __FILE__ ), array(), '1.0.0' );
	}
}
