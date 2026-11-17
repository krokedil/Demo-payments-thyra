<?php
/**
 * Build MockPay payment data from a WooCommerce order. With help from Copilot
 *
 * @package KrokedilDemoPayments
 */

/**
 * Map WooCommerce orders to the MockPay create-payment contract.
 */
class KDP_MockPay_Order_Payload {
	/**
	 * Build a MockPay payment payload.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array
	 */
	public static function from_order( $order ) {
		$billing_address  = self::address( $order, 'billing' );
		$shipping_address = self::address( $order, 'shipping', $order->get_address( 'billing' ) );

		// MockPay accepts totals only as integer minor units.
		$total = self::to_minor_units( $order->get_total() );
		$tax   = self::to_minor_units( $order->get_total_tax() );

		$net_total = $total - $tax;
		$vat_rate  = $net_total > 0 ? (int) round( $tax / $net_total * 10000 ) : 0;

		$order_number = (string) $order->get_order_number();
		$row_type     = self::order_row_type( $order );

		return array(
			'currency'            => strtoupper( $order->get_currency() ),
			'merchant_reference'  => (string) $order->get_id(),
			'merchant_reference2' => $order_number,
			'order_total'         => $total,
			'order_vat_amount'    => $tax,
			'billing_address'     => $billing_address,
			'shipping_address'    => $shipping_address,
			// MockPay requires an order row; one summary row represents this WooCommerce order.
			'order_rows'          => array(
				array(
					'type'         => $row_type,
					'sku'          => 'WC-ORDER-' . $order->get_id(),
					'name'         => sprintf( 'WooCommerce order %s', $order_number ),
					'reference'    => $order_number,
					'quantity'     => 1,
					'unit_amount'  => $total,
					'total_amount' => $total,
					'vat_amount'   => $tax,
					'vat_rate'     => $vat_rate,
				),
			),
		);
	}

	/**
	 * Build an API address, using billing details when shipping details are absent.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $type Address type.
	 * @param array    $fallback Fallback address.
	 * @return array
	 */
	private static function address( $order, $type, $fallback = array() ) {
		$source = $order->get_address( $type );

		// Map required address fields; MockPay reports missing or invalid values.
		$fields = array(
			'first_name'     => 'first_name',
			'last_name'      => 'last_name',
			'street_address' => 'address_1',
			'postal_code'    => 'postcode',
			'city'           => 'city',
			'country'        => 'country',
			'email'          => 'email',
			'phone'          => 'phone',
		);
		$values = array();

		foreach ( $fields as $api_key => $order_key ) {
			$value = trim( (string) ( $source[ $order_key ] ?? '' ) );

			if ( '' === $value ) {
				$value = trim( (string) ( $fallback[ $order_key ] ?? '' ) );
			}

			$values[ $api_key ] = sanitize_text_field( $value );
		}

		// These extra address fields are optional and are sent as null when absent.
		$values['company']         = self::nullable_text( $source['company'] ?? $fallback['company'] ?? '' );
		$values['street_address2'] = self::nullable_text( $source['address_2'] ?? $fallback['address_2'] ?? '' );
		$values['region']          = self::nullable_text( $source['state'] ?? $fallback['state'] ?? '' );
		$values['email']           = sanitize_email( $values['email'] );
		$values['country']         = strtoupper( $values['country'] );

		return $values;
	}

	/**
	 * Choose the closest MockPay row type for the order summary.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	private static function order_row_type( $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}

			$product = $item->get_product();
			if ( $product && $product->is_virtual() ) {
				continue;
			}

			return 'physical';
		}

		return 'digital';
	}

	/**
	 * Convert a WooCommerce decimal amount to MockPay minor units.
	 *
	 * @param string|float $amount WooCommerce amount.
	 * @return int
	 */
	private static function to_minor_units( $amount ) {
		return (int) wc_add_number_precision( (float) $amount );
	}

	/**
	 * Return nullable text for optional address fields.
	 *
	 * @param string $value Address value.
	 * @return string|null
	 */
	private static function nullable_text( $value ) {
		$value = trim( (string) $value );
		return '' === $value ? null : sanitize_text_field( $value );
	}
}
