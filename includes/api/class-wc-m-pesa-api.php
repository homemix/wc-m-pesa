<?php
/**
 * High-level Daraja API facade.
 *
 * Provides a clean interface for the STK Push endpoint, handling token
 * retrieval, request construction and timestamp generation internally.
 *
 * @package WC_MPesa\API
 */

defined( 'ABSPATH' ) || exit;

/**
 * Daraja API client — STK Push only.
 */
class WC_MPesa_API {

	/** @var WC_MPesa_Auth */
	private WC_MPesa_Auth $auth;

	/** @var WC_MPesa_Request */
	private WC_MPesa_Request $request;

	/**
	 * @param WC_MPesa_Auth    $auth    Authentication handler.
	 * @param WC_MPesa_Request $request HTTP client.
	 */
	public function __construct( WC_MPesa_Auth $auth, WC_MPesa_Request $request ) {
		$this->auth    = $auth;
		$this->request = $request;
	}

	/**
	 * Initiate an STK Push (Lipa Na M-Pesa Online) request.
	 *
	 * @param string $phone            Customer phone number in the format 2547XXXXXXXX.
	 * @param int    $amount           Amount in KES (whole number).
	 * @param string $shortcode        Business shortcode (Paybill or Till number).
	 * @param string $passkey          Lipa Na M-Pesa online passkey.
	 * @param string $callback_url     URL Daraja will POST the result to.
	 * @param string $account_ref      Account reference shown on customer M-Pesa statement (max 12 chars).
	 * @param string $transaction_desc Short description (max 13 chars).
	 * @param string $transaction_type 'CustomerPayBillOnline' or 'CustomerBuyGoodsOnline'.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function stk_push(
		string $phone,
		int $amount,
		string $shortcode,
		string $passkey,
		string $callback_url,
		string $account_ref,
		string $transaction_desc,
		string $transaction_type = 'CustomerPayBillOnline'
	) {
		$token = $this->auth->get_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// Daraja requires the timestamp in EAT (UTC+3 / Africa/Nairobi).
		$timestamp = ( new \DateTime( 'now', new \DateTimeZone( 'Africa/Nairobi' ) ) )->format( 'YmdHis' );
		$password  = base64_encode( $shortcode . $passkey . $timestamp );

		$body = array(
			'BusinessShortCode' => $shortcode,
			'Password'          => $password,
			'Timestamp'         => $timestamp,
			'TransactionType'   => $transaction_type,
			'Amount'            => $amount,
			'PartyA'            => $phone,
			'PartyB'            => $shortcode,
			'PhoneNumber'       => $phone,
			'CallBackURL'       => $callback_url,
			'AccountReference'  => mb_substr( $account_ref, 0, 12 ),
			'TransactionDesc'   => mb_substr( $transaction_desc, 0, 13 ),
		);

		$url = $this->auth->base_url() . '/mpesa/stkpush/v1/processrequest';

		$response = $this->request->post(
			$url,
			$body,
			array( 'Authorization' => 'Bearer ' . $token )
		);

		// On a 401 the cached token may be stale — invalidate it so the next request re-fetches.
		if ( is_wp_error( $response ) ) {
			$error_data  = $response->get_error_data( 'wc_mpesa_api_error' );
			$status_code = is_array( $error_data ) ? (int) ( $error_data['status'] ?? 0 ) : 0;
			if ( 401 === $status_code ) {
				$this->auth->invalidate_token();
			}
		}

		return $response;
	}

	/**
	 * Query the status of an existing STK Push transaction.
	 *
	 * @param string $checkout_request_id The CheckoutRequestID returned by stk_push().
	 * @param string $shortcode           Business shortcode.
	 * @param string $passkey             Lipa Na M-Pesa passkey.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function stk_push_query( string $checkout_request_id, string $shortcode, string $passkey ) {
		$token = $this->auth->get_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$timestamp = ( new \DateTime( 'now', new \DateTimeZone( 'Africa/Nairobi' ) ) )->format( 'YmdHis' );
		$password  = base64_encode( $shortcode . $passkey . $timestamp );

		$body = array(
			'BusinessShortCode'  => $shortcode,
			'Password'           => $password,
			'Timestamp'          => $timestamp,
			'CheckoutRequestID'  => $checkout_request_id,
		);

		$url = $this->auth->base_url() . '/mpesa/stkpushquery/v1/query';

		return $this->request->post(
			$url,
			$body,
			array( 'Authorization' => 'Bearer ' . $token )
		);
	}
}
