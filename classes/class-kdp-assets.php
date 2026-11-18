<?php
/**
 * CSS för betalningsmetoden.
 *
 * @package KrokedilDemoPayments
 */

/**
 * Laddar pluginets CSS-fil.
 */
class KDP_Assets {
	/**
	 * Laddar CSS:en som gör ikonen mindre, bara i kassan där betalsätten visas.
	 *
	 * @return void
	 */
	public static function enqueue_checkout_style() {
		if ( ! is_checkout() ) {
			return;
		}

		wp_enqueue_style( 'kdp-checkout', plugin_dir_url( __DIR__ ) . 'assets/css/frontend.css', array(), '1.0.0' );
	}
}
