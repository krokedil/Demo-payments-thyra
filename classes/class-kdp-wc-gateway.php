<?php
/**
 * Betalningsmetoden.
 *
 * @package KrokedilDemoPayments
 */

/**
 * Betalningsmetoden.
 */
class KDP_WC_Gateway extends WC_Payment_Gateway {

	// Egen källa: gör att loggarna får en egen fil och går att filtrera fram i WooCommerce → Status → Loggar.
	const LOG_SOURCE = 'krokedil-demo-payments';

	/**
	 * Sätter upp betalningsmetoden och dess hooks.
	 */
	public function __construct() {
		$this->id                 = 'kdp_gateway';
		$this->method_title       = 'Krokedil Demo Payments';
		$this->method_description = 'En demo-betalningsmetod';
		$this->icon               = plugin_dir_url( __DIR__ ) . 'icon.jpg';
		$this->has_fields         = false;

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled     = $this->get_option( 'enabled' );
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		// Hook för att spara inställningar.
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			function () {
				$this->process_admin_options();
			}
		);

		add_action( 'woocommerce_thankyou_kdp_gateway', array( $this, 'kdp_thankyou_page' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'kdp_email_instructions' ), 10, 3 );

		// Tar emot kunden när hen kommer tillbaka från betalsidan (redirect-läget).
		add_action( 'woocommerce_api_' . $this->id . '_return', array( $this, 'handle_return' ) );
	}

	/**
	 * Inställningar för betalningsmetoden.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
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
				'description' => __( 'Visas på tack-sidan efter genomförd betalning.', 'krokedil-demo-payments' ),
				'default'     => __( 'Tack för din beställning!', 'krokedil-demo-payments' ),
				'desc_tip'    => true,
			),
			// Del 3, punkt 2.
			'endpoint_url'  => array(
				'title'       => 'Endpoint-URL',
				'type'        => 'url',
				'description' => 'Adress till betal-API:t (test eller produktion).',
				'default'     => '',
			),
			'mode'          => array(
				'title'   => 'Läge',
				'type'    => 'select',
				'options' => array(
					'manual'   => 'Manuellt',
					'inline'   => 'Inline',
					'redirect' => 'Redirect',
				),
				'default' => 'manual',
			),
			// Loggning, punkt 3.7.
			'logging'       => array(
				'title'       => __( 'Loggning', 'krokedil-demo-payments' ),
				'type'        => 'checkbox',
				'label'       => __( 'Logga händelser och API-anrop', 'krokedil-demo-payments' ),
				'description' => sprintf(
					/* translators: %s: adress till WooCommerces loggsida. */
					__( 'Loggarna finns under <a href="%s">WooCommerce → Status → Loggar</a> med källan "krokedil-demo-payments".', 'krokedil-demo-payments' ),
					admin_url( 'admin.php?page=wc-status&tab=logs' )
				),
				'default'     => 'yes',
			),
			// Secret key för att verifiera returer från leverantören (redirect-läget).
			'shared_secret' => array(
				'title'       => __( 'Delad hemlighet', 'krokedil-demo-payments' ),
				'type'        => 'password',
				'description' => __( 'Nyckeln som betalleverantören signerar svaren med. Måste vara samma som i betalsidan.', 'krokedil-demo-payments' ),
				'default'     => '',
			),
		);
	}

	/**
	 * Funktion som körs när betalningen genomförs.
	 *
	 * @param int $order_id Order-ID.
	 * @return array<string, string>
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$mode  = $this->get_option( 'mode' );

		// wc_get_order() kan returnera false eller en återbetalning.
		if ( ! $order instanceof WC_Order ) {
			return array( 'result' => 'failure' );
		}

		// Del 3, lägger till funktion för api-anrop.
		// MANUELLT: inget API-anrop. Ordern väntar på att någon bekräftar betalningen i efterhand.
		if ( 'manual' === $mode ) {
			$order->update_status( 'on-hold', 'Väntar på manuell betalning.' );
			WC()->cart->empty_cart();
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		// Inline och redirect behöver en adress. Den kommer från inställningarna,
		// så att test- och produktionsmiljö kan ha olika adresser utan kodändring.
		$endpoint = trim( $this->get_option( 'endpoint_url' ) );

		// Felhantering.
		if ( '' === $endpoint || ! wp_http_validate_url( $endpoint ) ) {
			return $this->outcome_error( $order, 'Endpoint-URL saknas eller är ogiltig.' );
		}

		// Switch istället för if, så att det blir lättare.
		switch ( $mode ) {
			case 'inline':
				return $this->process_inline( $order, $endpoint );
			case 'redirect':
				return $this->process_redirect( $order, $endpoint ); // Punkt 4.2, skicka till betalsidan.
			default:
				// Okänt läge (t.ex. trasig inställning).
				return $this->outcome_error( $order, 'Okänt läge: ' . $mode );
		}
	}

	/**
	 * Tack-sidan.
	 *
	 * @param int $order_id Order-ID.
	 * @return void
	 */
	public function kdp_thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || 'kdp_gateway' !== $order->get_payment_method() ) {
			return;
		}

		if ( $order->is_paid() ) {
			echo '<p>Tack! Din betalning är mottagen.</p>';
		} elseif ( $order->has_status( 'on-hold' ) && 'manual' === $this->get_option( 'mode' ) ) {
			echo '<p>Tack för din beställning! Vänligen överför summan i orderbekräftelsen till vårt konto.</p>';
		} elseif ( $order->has_status( 'on-hold' ) ) {
			echo '<p>Tack! Vi väntar på bekräftelse av din betalning och mejlar dig när den är klar.</p>';
		}
	}

	/**
	 * Instruktioner i ordermejlen.
	 *
	 * @param WC_Order|int $order         Ordern eller dess ID.
	 * @param bool         $sent_to_admin Om mejlet skickas till butiken.
	 * @param bool         $plain_text    Om mejlet är i ren text.
	 * @return void
	 */
	public function kdp_email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		// $order_id fanns inte här. wc_get_order() klarar både ett orderobjekt och ett ID.
		$order = wc_get_order( $order );

		if ( ! $order instanceof WC_Order || 'kdp_gateway' !== $order->get_payment_method() ) {
			return;
		}

		if ( $order->is_paid() ) {
			echo '<p>Tack! Din betalning är mottagen.</p>';
		} elseif ( $order->has_status( 'on-hold' ) && 'manual' === $this->get_option( 'mode' ) ) {
			echo '<p>Tack för din beställning! Vänligen överför summan i orderbekräftelsen till vårt konto.</p>';
		} elseif ( $order->has_status( 'on-hold' ) ) {
			echo '<p>Tack! Vi väntar på bekräftelse av din betalning och mejlar dig när den är klar.</p>';
		}
	}

	/**
	 * Inline: anropar betal-API:t och hanterar svaret. Del 3, punkt 2. Gjort med claude.
	 *
	 * @param WC_Order $order    Ordern.
	 * @param string   $endpoint Adress till betal-API:t.
	 * @return array<string, string>
	 */
	private function process_inline( $order, $endpoint ) {
		// Loggning, punkt 3.7.
		$this->log( sprintf( 'Skickar betalförfrågan till %s (%s %s).', $endpoint, $order->get_total(), $order->get_currency() ), 'info', $order );
		// WordPress HTTP-API i stället för curl/file_get_contents:
		// följer sajtens proxy-, timeout-, SSL- och filterinställningar.
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 15, // Sätter timeout till 15 sek.
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'order_id' => $order->get_id(),
						'amount'   => $order->get_total(),
						'currency' => $order->get_currency(),
					)
				),
			)
		);

		// Felhantering.
		// FEL 1: anropet nådde aldrig fram (timeout, DNS, nätverk, blockerad av proxy ...).
		if ( is_wp_error( $response ) ) {
			if ( $this->is_timeout( $response ) ) {
				// Vi vet inte om betalningen gick igenom, så kunden får inte försöka igen.
				return $this->outcome_unknown( $order, 'Timeout: ' . $response->get_error_message() );
			}
			// Förfrågan nådde aldrig fram, så ingen kan ha debiterats.
			return $this->outcome_error( $order, 'Anslutningsfel: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		// Loggning.
		$this->log( "Svar från leverantören: HTTP {$code}, body: " . substr( $raw, 0, 500 ), 'info', $order );
		$data = json_decode( $raw, true );

		// FEL 2: servern svarade, men med en felkod (t.ex. 400 eller 500).
		if ( $code < 200 || $code >= 300 ) {
			return $this->outcome_error( $order, "HTTP {$code}: " . substr( $raw, 0, 200 ) );
		}

		// FEL 3: 200 OK men svaret går inte att tolka (trasig JSON eller saknad status).
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) || empty( $data['status'] ) ) {
			return $this->outcome_unknown( $order, 'Ogiltig JSON: ' . substr( $raw, 0, 200 ) );
		}

		// Svaret är giltigt, agera utifrån status. JSON-värdena kontrolleras så att de är text.
		$status    = is_string( $data['status'] ) ? $data['status'] : '';
		$reason    = is_string( $data['reason'] ?? null ) ? $data['reason'] : 'okänd orsak';
		$reference = sanitize_text_field( is_string( $data['transaction_id'] ?? null ) ? $data['transaction_id'] : '' ); // Sparar referens.

		switch ( $status ) {
			case 'approved':
				return $this->outcome_approved( $order, $reference );
			case 'pending':
				return $this->outcome_pending( $order, $reference );
			case 'declined':
				return $this->outcome_declined( $order, $reference, sanitize_text_field( $reason ) );
			default:
				// Status vi inte känner igen: aldrig godkänt, men inte heller säkert nekat.
				return $this->outcome_unknown( $order, 'Okänd status från leverantören: ' . sanitize_text_field( $status ) );
		}
	}

	/**
	 * Redirect: skickar kunden till leverantörens betalsida.
	 *
	 * @param WC_Order $order    Ordern.
	 * @param string   $endpoint Adress till betalsidan.
	 * @return array<string, string>
	 */
	private function process_redirect( $order, $endpoint ) {
		// Adressen kunden ska tillbaka till. Byggs av WooCommerce, inte hårdkodad.
		// Order-nyckeln gör att ingen kan gissa sig till någon annans order.
		$secret = $this->get_option( 'shared_secret' );
		if ( '' === $secret ) {
			return $this->outcome_error( $order, 'Delad hemlighet saknas, kan inte signera förfrågan.' );
		}

		$return_url = add_query_arg(
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
			),
			WC()->api_request_url( $this->id . '_return' )
		);

		// Beloppet räknas ut en gång så att signaturen och URL:en garanterat har samma värde.
		$amount = $this->format_amount( $order->get_total() );
		// Signatur: order, belopp och retur-URL. Om något ändras på vägen blir signaturen ogiltig.
		$signature = hash_hmac( 'sha256', implode( '|', array( $order->get_id(), $amount, $return_url ) ), $secret );

		// Leverantörens betalsida = endpoint-URL:en från inställningarna + orderinfo.
		$pay_url = add_query_arg(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => $amount,
				'return_url' => rawurlencode( $return_url ),
				'signature'  => $signature,
			),
			$endpoint
		);

		// Ordern ligger kvar som "pending" tills kunden kommer tillbaka till handle_return().
		return array(
			'result'   => 'success',
			'redirect' => $pay_url,
		);
	}

	/**
	 * Kunden kommer tillbaka från betalsidan. Punkt 4.4, hantera returen.
	 *
	 * OBS: i en riktig integration litar man inte på ?status= i URL:en,
	 * utan verifierar en signatur eller frågar leverantörens API.
	 *
	 * @return void
	 */
	public function handle_return() {
		$order_id  = absint( $this->query_arg( 'order_id' ) );
		$key       = $this->query_arg( 'key' );
		$status    = sanitize_key( $this->query_arg( 'status' ) );
		$reference = $this->query_arg( 'transaction_id' );
		$order     = $order_id ? wc_get_order( $order_id ) : false;

		// Order-nyckeln måste stämma, annars kan vem som helst ändra andras ordrar.
		if ( ! $order instanceof WC_Order || ! $order->key_is_valid( $key ) || $this->id !== $order->get_payment_method() ) {
			wp_die( 'Ogiltig order.', 'Fel', array( 'response' => 400 ) );
		}

		// --- Verifiera att svaret kommer från betalleverantören ---
		$secret    = $this->get_option( 'shared_secret' );
		$amount    = $this->query_arg( 'amount' );
		$signature = $this->query_arg( 'signature' );

		if ( '' === $secret ) {
			// Felkonfiguration: utan nyckel kan inget svar verifieras, så inget godkänns.
			$this->log( 'Delad hemlighet saknas i inställningarna, retur kan inte verifieras.', 'error', $order );
			wp_die( 'Betalsättet är inte korrekt konfigurerat.', 'Fel', array( 'response' => 500 ) );
		}

		$expected = hash_hmac( 'sha256', implode( '|', array( $order_id, $status, $reference, $amount ) ), $secret );

		// hash_equals() jämför på konstant tid, så att man inte kan gissa signaturen tecken för tecken.
		if ( ! hash_equals( $expected, $signature ) ) {
			$this->log( 'OGILTIG SIGNATUR vid retur. Möjligt manipulationsförsök. Status i URL: ' . $status, 'error', $order );
			$order->add_order_note( 'Retur med ogiltig signatur avvisades. Orderstatus ändrades inte.' );
			wc_add_notice( 'Betalningen kunde inte verifieras. Kontakta butiken om du har betalat.', 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		// Signaturen är giltig, men stämmer beloppet med ordern?
		// Skyddar mot att någon ändrar beloppet i URL:en på väg TILL betalsidan
		// och betalar 1 kr för en order på 390 kr.
		if ( $amount !== $this->format_amount( $order->get_total() ) ) {
			$result = $this->outcome_unknown( $order, "Beloppet stämmer inte: betalt {$amount}, ordern är " . $this->format_amount( $order->get_total() ) . '.' );
			wp_safe_redirect( $result['redirect'] );
			exit;
		}

		$this->log( 'Kunden kom tillbaka från betalsidan med status: ' . ( $status ? $status : 'saknas' ), 'info', $order );

		// Redan betald (t.ex. kunden laddade om sidan): hantera inte samma retur två gånger.
		if ( $order->is_paid() ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		switch ( $status ) {
			case 'approved':
				$result = $this->outcome_approved( $order, $reference );
				break;
			case 'pending':
				$result = $this->outcome_pending( $order, $reference );
				break;
			case 'declined':
				$result = $this->outcome_declined( $order, $reference, 'nekad på betalsidan' );
				break;
			case 'cancelled':
				// Kunden avbröt själv: ordern ligger kvar som pending och kan betalas igen.
				$this->log( 'Kunden avbröt betalningen.', 'info', $order );
				$order->add_order_note( 'Kunden avbröt betalningen på betalsidan.' );
				wc_add_notice( 'Betalningen avbröts.', 'notice' );
				$result = array( 'result' => 'failure' );
				break;
			default:
				$result = $this->outcome_unknown( $order, 'Okänd status vid retur: ' . ( $status ? $status : 'saknas' ) );
		}

		// Lyckat → tack-sidan. Misslyckat → tillbaka till kassan där felmeddelandet visas.
		wp_safe_redirect( 'success' === $result['result'] ? $result['redirect'] : wc_get_checkout_url() );
		exit;
	}

	/**
	 * GODKÄNT: status processing, lagret dras, referensen sparas.
	 *
	 * Mappar ordern till orderstatus och hanterar lagret. Punkt 3.5.
	 *
	 * @param WC_Order $order     Ordern.
	 * @param string   $reference Leverantörens referens.
	 * @return array<string, string>
	 */
	private function outcome_approved( $order, $reference ) {
		// Loggning.
		$this->log( 'Godkänd. Referens: ' . ( $reference ? $reference : 'saknas' ), 'info', $order );
		// payment_complete() sparar transaktions-ID, sätter betaldatum,
		// byter status till processing och drar lagret.
		$order->payment_complete( $reference );

		$order->add_order_note(
			$reference
			? 'Betalning godkänd. Leverantörens referens: ' . $reference
			: 'Betalning godkänd, men leverantören skickade ingen referens. Stäm av manuellt.'
		);

		WC()->cart->empty_cart();
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * VÄNTANDE: status on-hold, lagret reserveras.
	 *
	 * @param WC_Order $order     Ordern.
	 * @param string   $reference Leverantörens referens.
	 * @return array<string, string>
	 */
	private function outcome_pending( $order, $reference ) {
		// Loggning.
		$this->log( 'Väntande. Referens: ' . ( $reference ? $reference : 'saknas' ), 'info', $order );

		$this->save_reference( $order, $reference );

		// WooCommerce drar lagret automatiskt när status blir on-hold.
		$order->update_status( 'on-hold', 'Betalningen väntar på bekräftelse från leverantören. Referens: ' . ( $reference ? $reference : 'saknas' ) );

		WC()->cart->empty_cart();
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * NEKAT: status failed, lagret återställs, kunden stannar i kassan.
	 *
	 * @param WC_Order $order     Ordern.
	 * @param string   $reference Leverantörens referens.
	 * @param string   $reason    Orsaken till att betalningen nekades.
	 * @return array<string, string>
	 */
	private function outcome_declined( $order, $reference, $reason ) {
		// Loggning.
		$this->log( "Nekad ({$reason}). Referens: " . ( $reference ? $reference : 'saknas' ), 'warning', $order );

		$this->save_reference( $order, $reference );

		// Lämna tillbaka lager om det redan dragits (t.ex. vid ett tidigare försök som blev on-hold).
		// Funktionen gör ingenting om inget lager har dragits.
		wc_increase_stock_levels( $order );

		$order->update_status( 'failed', "Betalningen nekades ({$reason}). Referens: " . ( $reference ? $reference : 'saknas' ) );

		wc_add_notice( 'Betalningen nekades. Försök igen eller välj ett annat betalsätt.', 'error' );
		return array( 'result' => 'failure' );
	}

	/**
	 * TEKNISKT FEL (säkert ingen betalning): som nekat, men loggas.
	 *
	 * @param WC_Order $order   Ordern.
	 * @param string   $message Felmeddelande.
	 * @return array<string, string>
	 */
	private function outcome_error( $order, $message ) {
		// Loggning.
		$this->log( 'Tekniskt fel: ' . $message, 'error', $order );

		wc_increase_stock_levels( $order );

		$order->update_status( 'failed', 'Tekniskt fel, ingen betalning genomförd: ' . $message );

		wc_add_notice( 'Betalningen kunde inte genomföras just nu. Försök igen om en stund.', 'error' );
		return array( 'result' => 'failure' );
	}

	/**
	 * OKÄNT UTFALL (timeout, otolkbart svar): on-hold, en människa kontrollerar.
	 *
	 * @param WC_Order $order   Ordern.
	 * @param string   $message Felmeddelande.
	 * @return array<string, string>
	 */
	private function outcome_unknown( $order, $message ) {
		// Loggning.
		$this->log( 'OKÄNT UTFALL, kräver manuell kontroll: ' . $message, 'error', $order );

		$order->update_status( 'on-hold', 'OKÄNT UTFALL. Kontrollera hos betalleverantören innan kunden debiteras igen. ' . $message );

		// Kunden får inte försöka igen i kassan (risk för dubbeldebitering).
		WC()->cart->empty_cart();
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/* ---------- Hjälpfunktioner ---------- */

	/**
	 * Sparar referensen i WooCommerce standardfält för transaktions-ID, punkt 3.6.
	 *
	 * @param WC_Order $order     Ordern.
	 * @param string   $reference Leverantörens referens.
	 * @return void
	 */
	private function save_reference( $order, $reference ) {
		if ( '' !== $reference ) {
			$order->set_transaction_id( $reference );
			$order->save();
		}
	}

	/**
	 * Timeout känns igen på felmeddelandet (cURL error 28 / "timed out").
	 *
	 * @param WP_Error $error Felet från WordPress HTTP-API.
	 * @return bool
	 */
	private function is_timeout( $error ) {
		$message = strtolower( $error->get_error_message() );
		return str_contains( $message, 'timed out' ) || str_contains( $message, 'curl error 28' );
	}

	/**
	 * Loggning: punkt 3.7.
	 *
	 * @param string        $message Meddelande.
	 * @param string        $level   Loggnivå.
	 * @param WC_Order|null $order   Ordern, om meddelandet gäller en order.
	 * @return void
	 */
	private function log( $message, $level = 'info', $order = null ) {
		if ( 'yes' !== $this->get_option( 'logging' ) ) {
			return;
		}

		if ( $order ) {
			$message = sprintf( 'Order %d: %s', $order->get_id(), $message );
		}

		wc_get_logger()->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
	}

	/**
	 * Hämtar en parameter från adressen som rensad text, eller en tom sträng.
	 *
	 * Värdet kan vara en array om någon manipulerar adressen, därför kontrolleras typen.
	 *
	 * @param string $name Parameterns namn.
	 * @return string
	 */
	private function query_arg( $name ) {
		// En nonce passar inte här: anropet kommer från betalsidan, inte från ett formulär på sajten.
		// Returen skyddas i stället av order-nyckeln och HMAC-signaturen i handle_return().
		if ( ! isset( $_GET[ $name ] ) || ! is_string( $_GET[ $name ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verifieras med HMAC-signatur.
			return '';
		}
		return sanitize_text_field( wp_unslash( $_GET[ $name ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verifieras med HMAC-signatur.
	}

	/**
	 * Samma format på båda sidor, annars blir signaturen olika för "390.9" och "390.90".
	 *
	 * @param float|string $amount Beloppet.
	 * @return string
	 */
	private function format_amount( $amount ) {
		return number_format( (float) $amount, 2, '.', '' );
	}
}
