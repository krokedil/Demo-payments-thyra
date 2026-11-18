<?php
/**
 * Inställningarna för betalningsmetoden.
 *
 * @package KrokedilDemoPayments
 */

/**
 * Fälten som visas under WooCommerce → Inställningar → Betalningar → Krokedil Demo Payments.
 */
class KDP_Settings {
	/**
	 * Alla inställningsfält. WooCommerce sparar dem i optionen woocommerce_kdp_gateway_settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields() {
		return array(
			'enabled'       => array(
				'title'   => __( 'Aktivera', 'krokedil-demo-payments' ),
				'type'    => 'checkbox',
				'label'   => __( 'Aktivera Krokedil Demo Payments', 'krokedil-demo-payments' ),
				'default' => 'yes',
			),
			'title'         => array(
				'title'       => __( 'Rubrik', 'krokedil-demo-payments' ),
				'type'        => 'text',
				'description' => __( 'Visas som betalningsalternativ i kassan.', 'krokedil-demo-payments' ),
				'default'     => __( 'Krokedil Demo Payments', 'krokedil-demo-payments' ),
				'desc_tip'    => true,
			),
			'description'   => array(
				'title'       => __( 'Beskrivning', 'krokedil-demo-payments' ),
				'type'        => 'textarea',
				'description' => __( 'Visas under betalningsalternativet i kassan.', 'krokedil-demo-payments' ),
				'default'     => __( 'Betala med Krokedil Demo Payments!', 'krokedil-demo-payments' ),
				'desc_tip'    => true,
			),
			'instructions'  => array(
				'title'       => __( 'Instruktioner', 'krokedil-demo-payments' ),
				'type'        => 'textarea',
				'description' => __( 'Visas på tack-sidan och i ordermejlen.', 'krokedil-demo-payments' ),
				'default'     => __( 'Tack för din beställning!', 'krokedil-demo-payments' ),
				'desc_tip'    => true,
			),
			'endpoint_url'  => array(
				'title'       => __( 'Endpoint-URL', 'krokedil-demo-payments' ),
				'type'        => 'url',
				'description' => __( 'Adress till betal-API:t (test eller produktion). Måste börja med https://.', 'krokedil-demo-payments' ),
				'default'     => '',
			),
			'mode'          => array(
				'title'   => __( 'Läge', 'krokedil-demo-payments' ),
				'type'    => 'select',
				'options' => array(
					'manual'   => __( 'Manuellt', 'krokedil-demo-payments' ),
					'inline'   => __( 'Inline', 'krokedil-demo-payments' ),
					'redirect' => __( 'Redirect', 'krokedil-demo-payments' ),
				),
				'default' => 'manual',
			),
			'logging'       => array(
				'title'       => __( 'Loggning', 'krokedil-demo-payments' ),
				'type'        => 'checkbox',
				'label'       => __( 'Logga händelser och API-anrop', 'krokedil-demo-payments' ),
				'description' => sprintf(
					/* translators: %s: adress till WooCommerces loggsida. */
					__( 'Loggarna finns under <a href="%s">WooCommerce → Status → Loggar</a> med källan "krokedil-demo-payments".', 'krokedil-demo-payments' ),
					esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) )
				),
				'default'     => 'yes',
			),
			// Används för att signera och verifiera inline-svaret och redirect-flödet.
			'shared_secret' => array(
				'title'       => __( 'Delad hemlighet', 'krokedil-demo-payments' ),
				'type'        => 'password',
				'description' => __( 'Nyckeln som betalleverantören signerar svaren med, i både inline- och redirect-läget. Måste vara samma som i mock-config.php.', 'krokedil-demo-payments' ),
				'default'     => '',
			),
		);
	}
}
