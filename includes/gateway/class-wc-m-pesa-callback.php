<?php
/**
 * Handles the Daraja IPN callback and updates WooCommerce orders accordingly.
 *
 * @package WC_MPesa\Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and processes the Daraja STK Push callback REST endpoint.
 */
class WC_MPesa_Callback {

	private const REST_NAMESPACE = 'wc-mpesa/v1';
	private const REST_ROUTE     = '/callback';

	/**
	 * Register the REST route with WordPress.
	 * Called on rest_api_init.
	 */
	public function register_rest_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'verify_secret' ),
			)
		);
	}

	/**
	 * Verify that the incoming request carries the correct callback secret.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool
	 */
	public function verify_secret( \WP_REST_Request $request ): bool {
		$stored_secret   = get_option( 'wc_mpesa_callback_secret', '' );
		$provided_secret = $request->get_param( 'secret' );

		if ( empty( $stored_secret ) || empty( $provided_secret ) ) {
			return false;
		}

		return hash_equals( $stored_secret, $provided_secret );
	}

	/**
	 * Process the Daraja callback payload.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();

		// Guard against empty or non-array body (e.g. health-check probes, PHP 8 TypeError).
		if ( empty( $body ) || ! is_array( $body ) ) {
			return new \WP_REST_Response( array( 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ), 200 );
		}

		$stk_callback = $body['Body']['stkCallback'] ?? null;

		if ( null === $stk_callback ) {
			return new \WP_REST_Response( array( 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ), 200 );
		}

		$result_code         = (int) ( $stk_callback['ResultCode'] ?? -1 );
		$checkout_request_id = sanitize_text_field( $stk_callback['CheckoutRequestID'] ?? '' );

		$order = $this->find_order_by_checkout_id( $checkout_request_id );

		if ( ! $order ) {
			wc_get_logger()->error(
				sprintf( '[WC M-Pesa] Callback received for unknown CheckoutRequestID: %s', $checkout_request_id ),
				array( 'source' => 'wc-m-pesa' )
			);
			return new \WP_REST_Response( array( 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ), 200 );
		}

		if ( 0 === $result_code ) {
			$items = $stk_callback['CallbackMetadata']['Item'] ?? array();
			$meta  = $this->parse_callback_metadata( $items );

			// Idempotency: if receipt already stored, this is a duplicate callback.
			if ( ! empty( $order->get_meta( '_mpesa_receipt_number' ) ) ) {
				return new \WP_REST_Response( array( 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ), 200 );
			}

			// Amount verification: paid amount must match order total.
			$paid_amount  = isset( $meta['Amount'] ) ? (float) $meta['Amount'] : 0.0;
			$order_total  = (float) $order->get_total();

			if ( $paid_amount < $order_total ) {
				$order->update_status(
					'on-hold',
					sprintf(
						/* translators: 1: paid amount, 2: expected amount */
						__( '[M-Pesa] Payment amount mismatch. Received KES %1$.2f, expected KES %2$.2f. Manual review required.', 'm-pesa-payment-gateway' ),
						$paid_amount,
						$order_total
					)
				);
				$order->save();
				wc_get_logger()->warning(
					sprintf(
						'[WC M-Pesa] Amount mismatch for order #%d. Paid: %.2f, Expected: %.2f',
						$order->get_id(),
						$paid_amount,
						$order_total
					),
					array( 'source' => 'wc-m-pesa' )
				);
				return new \WP_REST_Response( array( 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ), 200 );
			}

			$receipt = sanitize_text_field( (string) ( $meta['MpesaReceiptNumber'] ?? '' ) );

			if ( '' === $receipt ) {
				$order->update_status(
					'on-hold',
					__( '[M-Pesa] Success callback missing receipt number. Manual review required.', 'm-pesa-payment-gateway' )
				);
				$order->save();
				wc_get_logger()->warning(
					sprintf(
						'[WC M-Pesa] Success callback missing MpesaReceiptNumber for order #%d.',
						$order->get_id()
					),
					array( 'source' => 'wc-m-pesa' )
				);
				return new \WP_REST_Response( array( 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ), 200 );
			}

			$order->update_meta_data( '_mpesa_receipt_number',   $receipt );
			$order->update_meta_data( '_mpesa_transaction_date', sanitize_text_field( (string) ( $meta['TransactionDate'] ?? '' ) ) );
			$order->update_meta_data( '_mpesa_phone_used',       sanitize_text_field( (string) ( $meta['PhoneNumber'] ?? '' ) ) );
			$order->payment_complete( $receipt );
			$order->add_order_note(
				sprintf(
					/* translators: %s: M-Pesa receipt number */
					__( 'M-Pesa payment completed. Receipt: %s', 'm-pesa-payment-gateway' ),
					$receipt
				)
			);
		} else {
			$result_desc = sanitize_text_field( $stk_callback['ResultDesc'] ?? '' );
			$friendly    = $this->friendly_result_message( $result_code, $result_desc );
			$order->update_status( 'failed', '[M-Pesa] ' . $friendly );
		}

		$order->save();

		return new \WP_REST_Response( array( 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ), 200 );
	}

	/**
	 * Find a WooCommerce order by the stored _mpesa_checkout_request_id meta value.
	 * Uses meta_query for HPOS compatibility.
	 *
	 * Also checks already-completed orders for idempotency (order may have moved
	 * from on-hold to processing via an earlier callback delivery).
	 *
	 * @param string $checkout_request_id
	 * @return \WC_Order|null
	 */
	private function find_order_by_checkout_id( string $checkout_request_id ): ?\WC_Order {
		if ( empty( $checkout_request_id ) ) {
			return null;
		}

		$orders = wc_get_orders( array(
			'limit'      => 1,
			'status'     => array( 'wc-pending', 'wc-on-hold', 'wc-processing', 'wc-completed' ),
			'meta_query' => array(
				array(
					'key'   => '_mpesa_checkout_request_id',
					'value' => $checkout_request_id,
				),
			),
		) );

		return ! empty( $orders ) ? $orders[0] : null;
	}

	/**
	 * Map known Daraja STK Push result codes to human-readable messages.
	 *
	 * @param int    $code        Daraja ResultCode.
	 * @param string $raw_desc    Raw ResultDesc from Daraja (used as fallback).
	 * @return string
	 */
	private function friendly_result_message( int $code, string $raw_desc ): string {
		$map = array(
			1    => __( 'Insufficient funds in the M-Pesa account.', 'm-pesa-payment-gateway' ),
			17   => __( 'M-Pesa system internal error. Please try again.', 'm-pesa-payment-gateway' ),
			1001 => __( 'Unable to lock subscriber — please try again shortly.', 'm-pesa-payment-gateway' ),
			1032 => __( 'Payment request was cancelled by the user.', 'm-pesa-payment-gateway' ),
			1037 => __( 'Request timed out — the customer could not be reached on M-Pesa.', 'm-pesa-payment-gateway' ),
			2001 => __( 'Wrong M-Pesa PIN entered. Payment declined.', 'm-pesa-payment-gateway' ),
		);

		return $map[ $code ] ?? ( $raw_desc ?: __( 'Payment failed.', 'm-pesa-payment-gateway' ) );
	}

	/**
	 * Convert the flat Item array from the callback metadata into an associative array.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @return array<string, mixed>
	 */
	private function parse_callback_metadata( array $items ): array {
		$meta = array();
		foreach ( $items as $item ) {
			if ( isset( $item['Name'] ) ) {
				// 'Value' may be absent on the Balance item — default to empty string.
				$meta[ $item['Name'] ] = $item['Value'] ?? '';
			}
		}
		return $meta;
	}
}
