<?php
/**
 * Betalningsmetoden.
 *
 * @package KrokedilDemoPayments
 */

/**
 * Betalningsmetoden, med tre lägen:
 * - Manuellt: inget API-anrop, ordern väntar på manuell bekräftelse.
 * - Inline: butikens server anropar betal-API:t medan kunden stannar i kassan.
 * - Redirect: kunden skickas till en betalsida och tillbaka till handle_return().
 */
class KDP_WC_Gateway extends WC_Payment_Gateway {

	// Egen källa: gör att loggarna får en egen fil och går att filtrera fram i WooCommerce → Status → Loggar.
	private const LOG_SOURCE = 'krokedil-demo-payments';

	/**
	 * Sätter upp betalningsmetoden och dess hooks.
	 */
	public function __construct() {
		$this->id                 = 'kdp_gateway';
		$this->method_title       = 'Krokedil Demo Payments';
		$this->method_description = __( 'En demo-betalningsmetod', 'krokedil-demo-payments' );
		$this->icon               = plugin_dir_url( __DIR__ ) . 'icon.jpg';

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		// Sparar inställningarna när butiksägaren klickar på Spara.
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			function () {
				$this->process_admin_options();
			}
		);

		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_instructions' ), 10, 3 );

		// Tar emot kunden när hen kommer tillbaka från betalsidan (redirect-läget).
		add_action( 'woocommerce_api_' . $this->id . '_return', array( $this, 'handle_return' ) );
	}

	/**
	 * Inställningsfälten finns i KDP_Settings.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = KDP_Settings::fields();
	}

	/* ---------- Betalningen ---------- */

	/**
	 * Körs när kunden lägger beställningen.
	 *
	 * @param int $order_id Order-ID.
	 * @return array<string, string>
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		// wc_get_order() kan returnera false eller en återbetalning.
		if ( ! $order instanceof WC_Order ) {
			return $this->failure();
		}

		$mode = $this->get_option( 'mode' );
		if ( 'manual' === $mode ) {
			return $this->process_manual( $order );
		}

		$endpoint = $this->get_endpoint();
		if ( '' === $endpoint ) {
			return $this->outcome_error( $order, 'Endpoint-URL saknas eller är ogiltig.' );
		}

		// Läget är manual, inline eller redirect, och manual hanteras ovan.
		return 'inline' === $mode ? $this->process_inline( $order, $endpoint ) : $this->process_redirect( $order, $endpoint );
	}

	/**
	 * Manuellt: inget API-anrop. Ordern väntar på att någon bekräftar betalningen i efterhand.
	 *
	 * @param WC_Order $order Ordern.
	 * @return array<string, string>
	 */
	private function process_manual( $order ) {
		$order->update_status( 'on-hold', 'Väntar på manuell betalning.' );
		WC()->cart->empty_cart();
		return $this->success( $order );
	}

	/**
	 * Endpoint-URL:en från inställningarna, eller en tom sträng om den saknas eller är ogiltig.
	 *
	 * Adressen kommer från inställningarna, så att test- och produktionsmiljö kan ha olika
	 * adresser utan kodändring.
	 *
	 * @return string
	 */
	private function get_endpoint() {
		$endpoint = trim( $this->get_option( 'endpoint_url' ) );
		return wp_http_validate_url( $endpoint ) ? $endpoint : '';
	}

	/* ---------- Inline ---------- */

	/**
	 * Inline: anropar betal-API:t och hanterar svaret medan kunden stannar i kassan.
	 *
	 * @param WC_Order $order    Ordern.
	 * @param string   $endpoint Adress till betal-API:t.
	 * @return array<string, string>
	 */
	private function process_inline( $order, $endpoint ) {
		$response = $this->send_inline_request( $order, $endpoint );

		// FEL 1: anropet nådde aldrig fram (timeout, DNS, nätverk, blockerad av proxy ...).
		if ( is_wp_error( $response ) ) {
			return $this->handle_connection_error( $order, $response );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$this->log( "Svar från leverantören: HTTP {$code}, body: " . substr( $raw, 0, 500 ), 'info', $order );

		// FEL 2: servern svarade, men med en felkod (t.ex. 400 eller 500).
		if ( $code < 200 || $code >= 300 ) {
			return $this->outcome_error( $order, "HTTP {$code}: " . substr( $raw, 0, 200 ) );
		}

		// FEL 3: 200 OK men svaret går inte att tolka (trasig JSON eller saknad status).
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			return $this->outcome_unknown( $order, 'Ogiltig JSON: ' . substr( $raw, 0, 200 ) );
		}

		return $this->handle_inline_status( $order, $data );
	}

	/**
	 * Skickar betalförfrågan med WordPress HTTP-API, som följer sajtens proxy-, timeout-,
	 * SSL- och filterinställningar.
	 *
	 * @param WC_Order $order    Ordern.
	 * @param string   $endpoint Adress till betal-API:t.
	 * @return array<string, mixed>|WP_Error
	 */
	private function send_inline_request( $order, $endpoint ) {
		$this->log( sprintf( 'Skickar betalförfrågan till %s (%s %s).', $endpoint, $order->get_total(), $order->get_currency() ), 'info', $order );

		return wp_remote_post(
			$endpoint,
			array(
				'timeout' => 15,
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
	}

	/**
	 * Anropet nådde aldrig fram.
	 *
	 * Vid timeout vet vi inte om betalningen gick igenom, så ordern väntar på manuell kontroll.
	 * Andra anslutningsfel betyder att ingen kan ha debiterats.
	 *
	 * @param WC_Order $order Ordern.
	 * @param WP_Error $error Felet från WordPress HTTP-API.
	 * @return array<string, string>
	 */
	private function handle_connection_error( $order, $error ) {
		if ( $this->is_timeout( $error ) ) {
			return $this->outcome_unknown( $order, 'Timeout: ' . $error->get_error_message() );
		}

		return $this->outcome_error( $order, 'Anslutningsfel: ' . $error->get_error_message() );
	}

	/**
	 * Väljer utfall efter statusen i ett giltigt svar.
	 *
	 * @param WC_Order            $order Ordern.
	 * @param array<mixed, mixed> $data  Det avkodade svaret.
	 * @return array<string, string>
	 */
	private function handle_inline_status( $order, $data ) {
		$status    = $this->text_field( $data, 'status', '' );
		$reference = $this->text_field( $data, 'transaction_id', '' );

		switch ( $status ) {
			case 'approved':
				return $this->outcome_approved( $order, $reference );
			case 'pending':
				return $this->outcome_pending( $order, $reference );
			case 'declined':
				return $this->outcome_declined( $order, $reference, $this->text_field( $data, 'reason', 'okänd orsak' ) );
			default:
				// Status vi inte känner igen: aldrig godkänt, men inte heller säkert nekat.
				return $this->outcome_unknown( $order, 'Okänd status från leverantören: ' . $status );
		}
	}

	/* ---------- Redirect ---------- */

	/**
	 * Redirect: skickar kunden till leverantörens betalsida med en signerad förfrågan.
	 *
	 * Ordern ligger kvar som "pending" tills kunden kommer tillbaka till handle_return().
	 *
	 * @param WC_Order $order    Ordern.
	 * @param string   $endpoint Adress till betalsidan.
	 * @return array<string, string>
	 */
	private function process_redirect( $order, $endpoint ) {
		if ( '' === $this->get_option( 'shared_secret' ) ) {
			return $this->outcome_error( $order, 'Delad hemlighet saknas, kan inte signera förfrågan.' );
		}

		$return_url = $this->get_return_url_for_provider( $order );
		// Beloppet räknas ut en gång så att signaturen och URL:en garanterat har samma värde.
		$amount = $this->format_amount( $order->get_total() );

		$pay_url = add_query_arg(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => $amount,
				'return_url' => rawurlencode( $return_url ),
				// Om order, belopp eller retur-URL ändras på vägen blir signaturen ogiltig.
				'signature'  => $this->sign( array( $order->get_id(), $amount, $return_url ) ),
			),
			$endpoint
		);

		return array(
			'result'   => 'success',
			'redirect' => $pay_url,
		);
	}

	/**
	 * Adressen kunden ska tillbaka till. Byggs av WooCommerce, inte hårdkodad.
	 * Order-nyckeln gör att ingen kan gissa sig till någon annans order.
	 *
	 * @param WC_Order $order Ordern.
	 * @return string
	 */
	private function get_return_url_for_provider( $order ) {
		return add_query_arg(
			array(
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
			),
			WC()->api_request_url( $this->id . '_return' )
		);
	}

	/**
	 * Kunden kommer tillbaka från betalsidan.
	 *
	 * Statusen i adressen godtas bara om signaturen stämmer med den delade hemligheten,
	 * eftersom vem som helst kan ändra en adress.
	 *
	 * @return void
	 */
	public function handle_return() {
		$params = $this->get_return_params();
		$order  = $this->get_returned_order( $params );

		if ( ! $this->is_valid_return_signature( $params ) ) {
			$this->reject_return( $order, $params['status'] );
		}

		// Signaturen är giltig, men stämmer beloppet med ordern? Skyddar mot att någon ändrar
		// beloppet i URL:en på väg TILL betalsidan och betalar 1 kr för en order på 390 kr.
		$order_amount = $this->format_amount( $order->get_total() );
		if ( $params['amount'] !== $order_amount ) {
			$result = $this->outcome_unknown( $order, "Beloppet stämmer inte: betalt {$params['amount']}, ordern är {$order_amount}." );
			$this->redirect( $result['redirect'] );
		}

		$this->log( 'Kunden kom tillbaka från betalsidan med status: ' . $this->or_missing( $params['status'] ), 'info', $order );

		// Redan betald (t.ex. kunden laddade om sidan): hantera inte samma retur två gånger.
		if ( $order->is_paid() ) {
			$this->redirect( $this->get_return_url( $order ) );
		}

		$result = $this->handle_return_status( $order, $params['status'], $params['reference'] );

		// Lyckat → tack-sidan. Misslyckat → tillbaka till kassan där felmeddelandet visas.
		$this->redirect( 'success' === $result['result'] ? $result['redirect'] : wc_get_checkout_url() );
	}

	/**
	 * Parametrarna som betalsidan skickar tillbaka.
	 *
	 * @return array{order_id: int, key: string, status: string, reference: string, amount: string, signature: string}
	 */
	private function get_return_params() {
		return array(
			'order_id'  => absint( $this->query_arg( 'order_id' ) ),
			'key'       => $this->query_arg( 'key' ),
			'status'    => sanitize_key( $this->query_arg( 'status' ) ),
			'reference' => $this->query_arg( 'transaction_id' ),
			'amount'    => $this->query_arg( 'amount' ),
			'signature' => $this->query_arg( 'signature' ),
		);
	}

	/**
	 * Ordern som returen gäller. Avbryter med ett fel om ordern inte finns, om order-nyckeln
	 * inte stämmer (annars kan vem som helst ändra andras ordrar) eller om ingen hemlighet finns.
	 *
	 * @param array{order_id: int, key: string} $params Returparametrarna.
	 * @return WC_Order
	 */
	private function get_returned_order( $params ) {
		$order = $params['order_id'] ? wc_get_order( $params['order_id'] ) : false;

		if ( ! $order instanceof WC_Order || ! $order->key_is_valid( $params['key'] ) || $this->id !== $order->get_payment_method() ) {
			wp_die( esc_html__( 'Ogiltig order.', 'krokedil-demo-payments' ), esc_html__( 'Fel', 'krokedil-demo-payments' ), array( 'response' => 400 ) );
		}

		// Felkonfiguration: utan nyckel kan inget svar verifieras, så inget godkänns.
		if ( '' === $this->get_option( 'shared_secret' ) ) {
			$this->log( 'Delad hemlighet saknas i inställningarna, retur kan inte verifieras.', 'error', $order );
			wp_die( esc_html__( 'Betalsättet är inte korrekt konfigurerat.', 'krokedil-demo-payments' ), esc_html__( 'Fel', 'krokedil-demo-payments' ), array( 'response' => 500 ) );
		}

		return $order;
	}

	/**
	 * Kontrollerar att returen kommer från betalleverantören.
	 *
	 * Jämförelsen med hash_equals() tar lika lång tid oavsett var signaturerna skiljer sig,
	 * så att man inte kan gissa signaturen tecken för tecken.
	 *
	 * @param array{order_id: int, status: string, reference: string, amount: string, signature: string} $params Returparametrarna.
	 * @return bool
	 */
	private function is_valid_return_signature( $params ) {
		$expected = $this->sign( array( $params['order_id'], $params['status'], $params['reference'], $params['amount'] ) );
		return hash_equals( $expected, $params['signature'] );
	}

	/**
	 * Avvisar en retur med ogiltig signatur. Orderstatusen ändras inte.
	 *
	 * @param WC_Order $order  Ordern.
	 * @param string   $status Statusen i adressen.
	 * @return never
	 */
	private function reject_return( $order, $status ) {
		$this->log( 'OGILTIG SIGNATUR vid retur. Möjligt manipulationsförsök. Status i URL: ' . $status, 'error', $order );
		$order->add_order_note( 'Retur med ogiltig signatur avvisades. Orderstatus ändrades inte.' );
		wc_add_notice( esc_html__( 'Betalningen kunde inte verifieras. Kontakta butiken om du har betalat.', 'krokedil-demo-payments' ), 'error' );
		$this->redirect( wc_get_checkout_url() );
	}

	/**
	 * Väljer utfall efter statusen från betalsidan.
	 *
	 * @param WC_Order $order     Ordern.
	 * @param string   $status    Statusen från betalsidan.
	 * @param string   $reference Leverantörens referens.
	 * @return array<string, string>
	 */
	private function handle_return_status( $order, $status, $reference ) {
		switch ( $status ) {
			case 'approved':
				return $this->outcome_approved( $order, $reference );
			case 'declined':
				return $this->outcome_declined( $order, $reference, 'nekad på betalsidan' );
			default:
				// Betalsidan skickar bara approved eller declined. Annat ger on-hold för manuell kontroll.
				return $this->outcome_unknown( $order, 'Okänd status vid retur: ' . $this->or_missing( $status ) );
		}
	}

	/* ---------- Utfall ---------- */

	/**
	 * GODKÄNT: status processing, lagret dras, referensen sparas.
	 *
	 * @param WC_Order $order     Ordern.
	 * @param string   $reference Leverantörens referens.
	 * @return array<string, string>
	 */
	private function outcome_approved( $order, $reference ) {
		$this->log( 'Godkänd. Referens: ' . $this->or_missing( $reference ), 'info', $order );

		// payment_complete() sparar transaktions-ID, sätter betaldatum,
		// byter status till processing och drar lagret.
		$order->payment_complete( $reference );
		$order->add_order_note(
			'' !== $reference
				? 'Betalning godkänd. Leverantörens referens: ' . $reference
				: 'Betalning godkänd, men leverantören skickade ingen referens. Stäm av manuellt.'
		);

		WC()->cart->empty_cart();
		return $this->success( $order );
	}

	/**
	 * VÄNTANDE: status on-hold, lagret reserveras.
	 *
	 * @param WC_Order $order     Ordern.
	 * @param string   $reference Leverantörens referens.
	 * @return array<string, string>
	 */
	private function outcome_pending( $order, $reference ) {
		$this->log( 'Väntande. Referens: ' . $this->or_missing( $reference ), 'info', $order );
		$this->save_reference( $order, $reference );

		// WooCommerce drar lagret automatiskt när status blir on-hold.
		$order->update_status( 'on-hold', 'Betalningen väntar på bekräftelse från leverantören. Referens: ' . $this->or_missing( $reference ) );

		WC()->cart->empty_cart();
		return $this->success( $order );
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
		$this->log( "Nekad ({$reason}). Referens: " . $this->or_missing( $reference ), 'warning', $order );
		$this->save_reference( $order, $reference );

		// Lämna tillbaka lager om det redan dragits (t.ex. vid ett tidigare försök som blev on-hold).
		// Funktionen gör ingenting om inget lager har dragits.
		wc_increase_stock_levels( $order );
		$order->update_status( 'failed', "Betalningen nekades ({$reason}). Referens: " . $this->or_missing( $reference ) );

		wc_add_notice( esc_html__( 'Betalningen nekades. Försök igen eller välj ett annat betalsätt.', 'krokedil-demo-payments' ), 'error' );
		return $this->failure();
	}

	/**
	 * TEKNISKT FEL (säkert ingen betalning): som nekat, men loggas.
	 *
	 * @param WC_Order $order   Ordern.
	 * @param string   $message Felmeddelande.
	 * @return array<string, string>
	 */
	private function outcome_error( $order, $message ) {
		$this->log( 'Tekniskt fel: ' . $message, 'error', $order );

		wc_increase_stock_levels( $order );
		$order->update_status( 'failed', 'Tekniskt fel, ingen betalning genomförd: ' . $message );

		wc_add_notice( esc_html__( 'Betalningen kunde inte genomföras just nu. Försök igen om en stund.', 'krokedil-demo-payments' ), 'error' );
		return $this->failure();
	}

	/**
	 * OKÄNT UTFALL (timeout, otolkbart svar): on-hold, en människa kontrollerar.
	 *
	 * @param WC_Order $order   Ordern.
	 * @param string   $message Felmeddelande.
	 * @return array<string, string>
	 */
	private function outcome_unknown( $order, $message ) {
		$this->log( 'OKÄNT UTFALL, kräver manuell kontroll: ' . $message, 'error', $order );
		$order->update_status( 'on-hold', 'OKÄNT UTFALL. Kontrollera hos betalleverantören innan kunden debiteras igen. ' . $message );

		// Kunden får inte försöka igen i kassan (risk för dubbeldebitering).
		WC()->cart->empty_cart();
		return $this->success( $order );
	}

	/**
	 * Svaret till WooCommerce när kunden ska till tack-sidan.
	 *
	 * @param WC_Order $order Ordern.
	 * @return array<string, string>
	 */
	private function success( $order ) {
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Svaret till WooCommerce när kunden ska stanna i kassan.
	 *
	 * @return array<string, string>
	 */
	private function failure() {
		return array( 'result' => 'failure' );
	}

	/* ---------- Tack-sidan och mejlen ---------- */

	/**
	 * Tack-sidan: visar inställningen "Instruktioner".
	 *
	 * @param int $order_id Order-ID.
	 * @return void
	 */
	public function thankyou_page( $order_id ) {
		$this->print_instructions( wc_get_order( $order_id ) );
	}

	/**
	 * Ordermejlen: visar inställningen "Instruktioner", i HTML- eller textmejl.
	 *
	 * @param WC_Order|int $order         Ordern eller dess ID.
	 * @param bool         $sent_to_admin Om mejlet skickas till butiken.
	 * @param bool         $plain_text    Om mejlet är i ren text.
	 * @return void
	 */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		// WooCommerce skickar orderobjektet. wc_get_order() klarar både ett orderobjekt och ett ID.
		$this->print_instructions( wc_get_order( $order ), (bool) $plain_text );
	}

	/**
	 * Skriver ut inställningen "Instruktioner". Tack-sidan och mejlen använder samma funktion,
	 * så att det butiksägaren skriver i inställningen alltid syns på båda ställena.
	 *
	 * @param WC_Order|WC_Order_Refund|false $order      Ordern.
	 * @param bool                           $plain_text Om texten ska skrivas ut för ett textmejl.
	 * @return void
	 */
	private function print_instructions( $order, $plain_text = false ) {
		$instructions = trim( $this->get_option( 'instructions' ) );
		if ( '' === $instructions || ! $this->should_show_instructions( $order ) ) {
			return;
		}

		// wp_kses_post() tillåter samma HTML som i ett inlägg och tar bort resten, till exempel <script>.
		if ( $plain_text ) {
			// Inga <p>-taggar i textmejl. WooCommerce tar bort eventuella taggar när textmejlet byggs.
			echo wp_kses_post( $instructions ) . "\n\n";
		} else {
			echo wp_kses_post( wpautop( wptexturize( $instructions ) ) );
		}
	}

	/**
	 * Instruktionerna visas bara för ordrar med det här betalsättet som är betalda eller pausade,
	 * inte när betalningen har misslyckats.
	 *
	 * @param WC_Order|WC_Order_Refund|false $order Ordern.
	 * @return bool
	 * @phpstan-assert-if-true WC_Order $order
	 */
	private function should_show_instructions( $order ) {
		return $order instanceof WC_Order
			&& $this->id === $order->get_payment_method()
			&& ( $order->is_paid() || $order->has_status( 'on-hold' ) );
	}

	/* ---------- Hjälpfunktioner ---------- */

	/**
	 * Signerar delarna med den delade hemligheten (HMAC-SHA256).
	 *
	 * @param array<int, int|string> $parts Värdena som ska signeras, i ordning.
	 * @return string
	 */
	private function sign( $parts ) {
		return hash_hmac( 'sha256', implode( '|', $parts ), $this->get_option( 'shared_secret' ) );
	}

	/**
	 * Skickar kunden vidare och avslutar anropet.
	 *
	 * @param string $url Adressen.
	 * @return never
	 */
	private function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Sparar referensen i WooCommerce standardfält för transaktions-ID.
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
	 * Loggar till WooCommerce → Status → Loggar om inställningen Loggning är på.
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
	 * Hämtar ett textfält ur API-svaret som rensad text. JSON-värden kan ha vilken typ som helst.
	 *
	 * @param array<mixed, mixed> $data     Det avkodade svaret.
	 * @param string              $key      Fältets namn.
	 * @param string              $fallback Värdet om fältet saknas eller inte är text.
	 * @return string
	 */
	private function text_field( $data, $key, $fallback ) {
		return isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? sanitize_text_field( $data[ $key ] ) : $fallback;
	}

	/**
	 * Texten, eller "saknas" om den är tom. Används i loggar och ordernoteringar.
	 *
	 * @param string $value Texten.
	 * @return string
	 */
	private function or_missing( $value ) {
		return '' !== $value ? $value : 'saknas';
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
