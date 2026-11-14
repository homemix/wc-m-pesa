<?php
/**
 * Builds and sends the STK Push request via the Daraja API.
 *
 * @package WC_MPesa\Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles the STK Push initiation for an order.
 */
class WC_MPesa_STK_Push {

	/** @var WC_MPesa_API */
	private WC_MPesa_API $api;

	/** @param WC_MPesa_API $api */
	public function __construct( WC_MPesa_API $api ) {
		$this->api = $api;
	}

	/**
	 * Initiate an STK Push for a WooCommerce order.
	 *
	 * @param \WC_Order            $order        The order to pay for.
	 * @param string               $phone        Customer phone (2547XXXXXXXX).
	 * @param array<string, mixed> $settings     Gateway settings array.
	 * @return array<string, mixed>|\WP_Error    Daraja STK Push response or error.
	 */
	public function initiate( \WC_Order $order, string $phone, array $settings ) {
		$shortcode        = $settings['shortcode'];
		$passkey          = $settings['passkey'];
		$transaction_type = $settings['transaction_type'] ?? 'CustomerPayBillOnline';
		$account_ref      = ! empty( $settings['account_reference'] )
			? $settings['account_reference']
			: 'Order#' . $order->get_id();
		$description      = ! empty( $settings['transaction_description'] )
			? $settings['transaction_description']
			: 'WooCommerce';

		$callback_url = $this->build_callback_url();
		$amount       = (int) ceil( (float) $order->get_total() );

		$response = $this->api->stk_push(
			$phone,
			$amount,
			$shortcode,
			$passkey,
			$callback_url,
			$account_ref,
			$description,
			$transaction_type
		);

		if ( ! is_wp_error( $response ) ) {
			$checkout_request_id = is_array( $response ) && isset( $response['CheckoutRequestID'] ) ? sanitize_text_field( (string) $response['CheckoutRequestID'] ) : '';
			$merchant_request_id = is_array( $response ) && isset( $response['MerchantRequestID'] ) ? sanitize_text_field( (string) $response['MerchantRequestID'] ) : '';

			if ( '' === $checkout_request_id || '' === $merchant_request_id ) {
				return new \WP_Error(
					'wc_mpesa_invalid_stk_response',
					__( 'M-Pesa STK Push response was missing required request IDs.', 'wc-m-pesa' ),
					is_array( $response ) ? $response : array()
				);
			}

			$order->update_meta_data( '_mpesa_checkout_request_id', $checkout_request_id );
			$order->update_meta_data( '_mpesa_merchant_request_id', $merchant_request_id );
			$order->update_meta_data( '_mpesa_phone', sanitize_text_field( $phone ) );
			$order->save();
		}

		return $response;
	}

	/**
	 * Build the callback URL using the WP REST API namespace.
	 *
	 * @return string
	 */
	private function build_callback_url(): string {
		$secret = get_option( 'wc_mpesa_callback_secret', '' );
		return add_query_arg(
			'secret',
			$secret,
			rest_url( 'wc-mpesa/v1/callback' )
		);
	}

	/**
	 * Format a phone number to the 2547XXXXXXXX format expected by Daraja.
	 *
	 * Accepted input formats (Safaricom Kenya only):
	 *  - 7XXXXXXXX        (9 digits, starts with 7)
	 *  - 1XXXXXXXXX       (9 digits, starts with 1 — 011x Safaricom range)
	 *  - 07XXXXXXXX       (10 digits, starts with 07)
	 *  - 01XXXXXXXXX      (10 digits, starts with 01 — 011x Safaricom range)
	 *  - 254XXXXXXXXX     (12 digits, starts with 254)
	 *  - +254XXXXXXXXX    (13 chars — + stripped by digit filter)
	 *  - 00254XXXXXXXXX   (14 digits, starts with 00254)
	 *
	 * Returns null for any unrecognised or non-Safaricom format.
	 *
	 * @param string $phone Raw phone number (spaces, hyphens, + are stripped).
	 * @return string|null 12-digit international number (2547XXXXXXXX), or null on failure.
	 */
	public static function format_phone( string $phone ): ?string {
		// Strip everything except digits.
		$phone = (string) preg_replace( '/\D/', '', $phone );

		// 00254XXXXXXXXX → 254XXXXXXXXX
		if ( strlen( $phone ) === 14 && strncmp( $phone, '00254', 5 ) === 0 ) {
			$phone = substr( $phone, 2 );
		}

		// Already in 254XXXXXXXXX format (12 digits).
		if ( strlen( $phone ) === 12 && strncmp( $phone, '254', 3 ) === 0 ) {
			return $phone;
		}

		// 07XXXXXXXX or 01XXXXXXXXX (10 digits starting with 0).
		if ( strlen( $phone ) === 10 && strncmp( $phone, '0', 1 ) === 0 ) {
			$phone = '254' . substr( $phone, 1 );
			return self::is_safaricom( $phone ) ? $phone : null;
		}

		// 7XXXXXXXX or 1XXXXXXXXX (9 digits, no leading 0).
		if ( strlen( $phone ) === 9 ) {
			$phone = '254' . $phone;
			return self::is_safaricom( $phone ) ? $phone : null;
		}

		return null;
	}

	/**
	 * Check whether a fully-formed 254XXXXXXXXX number belongs to Safaricom Kenya.
	 *
	 * Safaricom prefixes (post-254): 70x, 71x, 72x, 74x, 75x, 76x, 79x, 110, 111, 112, 113, 114, 115.
	 *
	 * @param string $phone 12-digit number in 254XXXXXXXXX format.
	 * @return bool
	 */
	private static function is_safaricom( string $phone ): bool {
		$prefix      = substr( $phone, 3, 3 );
		$prefix_pair = substr( $phone, 3, 2 );

		if ( in_array( $prefix_pair, array( '70', '71', '72', '74', '75', '76', '79' ), true ) ) {
			return true;
		}

		return in_array( $prefix, array( '110', '111', '112', '113', '114', '115' ), true );
	}
}
