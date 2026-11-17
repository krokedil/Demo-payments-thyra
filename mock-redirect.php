<?php
/**
 * Mock payment page used as a provider in redirect mode.
 *
 * @package KrokedilDemoPayments
 */

// Same key as in the gateway settings. In a real payment provider, this would be
// kept in the database or a configuration file instead of in source code.
const SHARED_SECRET = 'hmo<iRvl\_N25!u362IrC3;d';

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$return_url = esc_url_raw( wp_unslash( $_GET['return_url'] ?? '' ) );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$order_id = absint( wp_unslash( $_GET['order_id'] ?? 0 ) );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$amount = sanitize_text_field( wp_unslash( $_GET['amount'] ?? '' ) );

// Only allow real HTTP(S) destinations to avoid javascript: payloads.
$scheme = wp_parse_url( $return_url, PHP_URL_SCHEME );
if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
	http_response_code( 400 );
	exit( 'Ogiltig eller saknad return_url.' );
}

// Verify that the request came from the store and was not altered in transit.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$signature = sanitize_text_field( wp_unslash( $_GET['signature'] ?? '' ) );
$expected  = hash_hmac( 'sha256', implode( '|', array( $order_id, $amount, $return_url ) ), SHARED_SECRET );

if ( ! hash_equals( $expected, $signature ) ) {
	http_response_code( 400 );
	echo 'Ogiltig förfrågan: signaturen stämmer inte. Ingen betalning har gjorts.';
	exit;
}

/**
 * Build a return URL with query parameters.
 *
 * @param string $return_url Original return URL.
 * @param array  $params     Query parameters.
 * @return string
 */
function result_link( $return_url, $params ) {
	$sep = ( false === strpos( $return_url, '?' ) ) ? '?' : '&';
	return $return_url . $sep . http_build_query( $params );
}

/**
 * Sign the result payload for a return trip.
 *
 * @param int    $order_id       Order identifier.
 * @param string $status         Payment status.
 * @param string $transaction_id Transaction reference.
 * @param string $amount         Amount to verify.
 * @return string
 */
function sign_result( $order_id, $status, $transaction_id, $amount ) {
	$data = implode( '|', array( $order_id, $status, $transaction_id, $amount ) );
	return hash_hmac( 'sha256', $data, SHARED_SECRET );
}

/**
 * Create a signed callback URL.
 *
 * @param string $return_url     Original return URL.
 * @param int    $order_id       Order identifier.
 * @param string $status         Payment status.
 * @param string $transaction_id Transaction reference.
 * @param string $amount         Paid amount.
 * @return string
 */
function signed_link( $return_url, $order_id, $status, $transaction_id, $amount ) {
	return result_link(
		$return_url,
		array(
			'status'         => $status,
			'transaction_id' => $transaction_id,
			'amount'         => $amount,
			'signature'      => sign_result( $order_id, $status, $transaction_id, $amount ),
		)
	);
}

$approve_url = signed_link( $return_url, $order_id, 'approved', 'TX' . random_int( 1000, 9999 ), $amount );
$decline_url = signed_link( $return_url, $order_id, 'declined', '', $amount );
?>
<!doctype html>
<html lang="sv">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Mock-betalsida</title>
	<style>
		body {
			font-family: sans-serif;
			max-width: 400px;
			margin: 60px auto;
			padding: 0 16px;
		}

		a {
			display: block;
			margin: 12px 0;
			padding: 14px;
			border-radius: 6px;
			text-align: center;
			text-decoration: none;
			color: #fff;
			font-weight: bold;
		}

		.approve {
			background: #2e7d32;
		}

		.decline {
			background: #c62828;
		}
	</style>
</head>
<body>
	<h1>Mock-betalning</h1>
	<p>Order <strong><?php echo esc_html( (string) $order_id ); ?></strong><br>
		Belopp <strong><?php echo esc_html( $amount ); ?></strong></p>

	<a class="approve" href="<?php echo esc_url( $approve_url ); ?>">Godkänn</a>
	<a class="decline" href="<?php echo esc_url( $decline_url ); ?>">Neka</a>
</body>
</html>
