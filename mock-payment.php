<?php
/**
 * Inline payment mock endpoint used to simulate payment-provider responses.
 *
 * @package KrokedilDemoPayments
 */

// This demo endpoint is intentionally lightweight and mirrors a provider response.
$scenario = sanitize_key( wp_unslash( $_GET['scenario'] ?? '' ) );

switch ( $scenario ) {
	case 'approved':
		header( 'Content-Type: application/json' );
		echo wp_json_encode(
			array(
				'status'         => 'approved',
				'transaction_id' => 'TX' . wp_rand( 1000, 9999 ),
			)
		);
		break;
	case 'declined':
		header( 'Content-Type: application/json' );
		echo wp_json_encode(
			array(
				'status' => 'declined',
				'reason' => 'insufficient_funds',
			)
		);
		break;
	case 'pending':
		header( 'Content-Type: application/json' );
		echo wp_json_encode( array( 'status' => 'pending' ) );
		break;
	case 'error500':
		http_response_code( 500 );
		echo 'Internal Server Error';
		break;
	case 'error400':
		http_response_code( 400 );
		echo 'Bad Request';
		break;
	case 'badjson':
		header( 'Content-Type: application/json' );
		echo '{ "status": "approved", "transaction_id": "TX1234" '; // Missing closing brace.
		break;
	case 'timeout':
		sleep( 20 ); // Longer than the gateway timeout of 15 seconds.
		header( 'Content-Type: application/json' );
		echo wp_json_encode(
			array(
				'status'         => 'approved',
				'transaction_id' => 'TX' . wp_rand( 1000, 9999 ),
			)
		);
		break;
	default:
		http_response_code( 400 );
		echo 'Invalid scenario';
		break;
}
