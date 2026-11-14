<?php
/**
 * Tests for WC_MPesa_Callback â€” Daraja IPN handler.
 *
 * Covers: success, all failure codes, duplicate delivery, malformed payload,
 * amount mismatch, missing/wrong secret, empty body (PHP 8 guard).
 *
 * @package WC_MPesa\Tests\Unit
 */

namespace WCMPesa\Tests\Unit;

use Brain\Monkey\Functions;
use WC_MPesa_Callback;
use WC_Order;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers WC_MPesa_Callback
 */
class CallbackTest extends WCMPesaTestCase {

	// â”€â”€ Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	private function make_request( ?array $json = null, string $secret = 'valid_secret' ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_json_params( $json );
		$req->set_param( 'secret', $secret );
		return $req;
	}

	private function success_payload( string $checkout_id = 'checkout-001', float $amount = 100.0 ): array {
		return array(
			'Body' => array(
				'stkCallback' => array(
					'MerchantRequestID'  => 'merchant-001',
					'CheckoutRequestID'  => $checkout_id,
					'ResultCode'         => 0,
					'ResultDesc'         => 'The service request is processed successfully.',
					'CallbackMetadata'   => array(
						'Item' => array(
							array( 'Name' => 'Amount',              'Value' => $amount ),
							array( 'Name' => 'MpesaReceiptNumber',  'Value' => 'LGR7EE1ABC' ),
							array( 'Name' => 'Balance' ),
							array( 'Name' => 'TransactionDate',     'Value' => 20240101120000 ),
							array( 'Name' => 'PhoneNumber',         'Value' => 254712345678 ),
						),
					),
				),
			),
		);
	}

	private function failure_payload( int $code, string $desc, string $checkout_id = 'checkout-001' ): array {
		return array(
			'Body' => array(
				'stkCallback' => array(
					'MerchantRequestID' => 'merchant-001',
					'CheckoutRequestID' => $checkout_id,
					'ResultCode'        => $code,
					'ResultDesc'        => $desc,
				),
			),
		);
	}

	private function mock_find_order( ?WC_Order $order ): void {
		Functions\expect( 'wc_get_orders' )
			->once()
			->andReturn( $order ? array( $order ) : array() );
	}

	// â”€â”€ Secret verification â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_verify_secret_returns_false_when_no_stored_secret(): void {
		Functions\expect( 'get_option' )->once()->andReturn( '' );

		$cb  = new WC_MPesa_Callback();
		$req = $this->make_request( null, 'anything' );

		$this->assertFalse( $cb->verify_secret( $req ) );
	}

	public function test_verify_secret_returns_false_when_no_provided_secret(): void {
		Functions\expect( 'get_option' )->once()->andReturn( 'stored_secret' );

		$cb  = new WC_MPesa_Callback();
		$req = new WP_REST_Request();
		$req->set_param( 'secret', '' );

		$this->assertFalse( $cb->verify_secret( $req ) );
	}

	public function test_verify_secret_returns_false_on_wrong_secret(): void {
		Functions\expect( 'get_option' )->once()->andReturn( 'correct_secret' );

		$cb  = new WC_MPesa_Callback();
		$req = $this->make_request( null, 'wrong_secret' );

		$this->assertFalse( $cb->verify_secret( $req ) );
	}

	public function test_verify_secret_returns_true_on_correct_secret(): void {
		Functions\expect( 'get_option' )->once()->andReturn( 'correct_secret' );

		$cb  = new WC_MPesa_Callback();
		$req = $this->make_request( null, 'correct_secret' );

		$this->assertTrue( $cb->verify_secret( $req ) );
	}

	// â”€â”€ Null / empty body guard â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_handle_null_body_returns_200(): void {
		$cb  = new WC_MPesa_Callback();
		$req = $this->make_request( null );

		$response = $cb->handle( $req );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->status );
		$this->assertSame( 0, $response->data['ResultCode'] );
	}

	public function test_handle_empty_array_body_returns_200(): void {
		$cb  = new WC_MPesa_Callback();
		$req = $this->make_request( array() );

		$response = $cb->handle( $req );

		$this->assertSame( 200, $response->status );
	}

	public function test_handle_missing_stk_callback_node_returns_200(): void {
		$cb  = new WC_MPesa_Callback();
		$req = $this->make_request( array( 'Body' => array( 'unexpected' => 'data' ) ) );

		$response = $cb->handle( $req );

		$this->assertSame( 200, $response->status );
	}

	// â”€â”€ Unknown order â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_handle_unknown_checkout_id_logs_and_returns_200(): void {
		Functions\expect( 'wc_get_orders' )->once()->andReturn( array() );
		Functions\expect( 'wc_get_logger' )->once()->andReturn( new class {
			public function error( $msg, $ctx = array() ) {}
		} );

		$cb  = new WC_MPesa_Callback();
		$req = $this->make_request( $this->success_payload() );

		$response = $cb->handle( $req );

		$this->assertSame( 200, $response->status );
	}

	// â”€â”€ Payment success â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_handle_success_marks_order_processing(): void {
		$order = new WC_Order( 1 );
		$order->set_total( 100.0 );
		$this->mock_find_order( $order );

		$cb  = new WC_MPesa_Callback();
		$req = $this->make_request( $this->success_payload( 'checkout-001', 100.0 ) );

		$response = $cb->handle( $req );

		$this->assertSame( 200, $response->status );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( 'LGR7EE1ABC', $order->get_meta( '_mpesa_receipt_number' ) );
	}

	public function test_handle_success_without_receipt_keeps_order_on_hold(): void {
		$order = new WC_Order( 1 );
		$order->set_total( 100.0 );
		$this->mock_find_order( $order );
		Functions\expect( 'wc_get_logger' )->once()->andReturn( new class {
			public function warning( $msg, $ctx = array() ) {}
		} );

		$payload = $this->success_payload();
		unset( $payload['Body']['stkCallback']['CallbackMetadata']['Item'][1] );
		$payload['Body']['stkCallback']['CallbackMetadata']['Item'] = array(
			array( 'Name' => 'Amount', 'Value' => 100.0 ),
			array( 'Name' => 'PhoneNumber', 'Value' => 254712345678 ),
		);

		$cb  = new WC_MPesa_Callback();
		$req = $this->make_request( $payload );

		$response = $cb->handle( $req );

		$this->assertSame( 200, $response->status );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_mpesa_receipt_number' ) );
	}

	// â”€â”€ Idempotency (duplicate callback) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_handle_duplicate_callback_is_idempotent(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( '_mpesa_receipt_number', 'LGR7EE1ABC' ); // already processed
		$this->mock_find_order( $order );

		$initial_status = $order->get_status();

		$cb  = new WC_MPesa_Callback();
		$req = $this->make_request( $this->success_payload() );

		$response = $cb->handle( $req );

		$this->assertSame( 200, $response->status );
		// Status must NOT change (no second payment_complete call).
		$this->assertSame( $initial_status, $order->get_status() );
	}

	// â”€â”€ Amount mismatch â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_handle_amount_less_than_order_total_sets_on_hold(): void {
		$order = new WC_Order( 1 );
		$order->set_total( 200.0 ); // expect 200 but receive 50
		$this->mock_find_order( $order );
		Functions\expect( 'wc_get_logger' )->once()->andReturn( new class {
			public function warning( $m, $c = array() ) {}
		} );

		$cb  = new WC_MPesa_Callback();
		$req = $this->make_request( $this->success_payload( 'checkout-001', 50.0 ) );

		$response = $cb->handle( $req );

		$this->assertSame( 200, $response->status );
		$this->assertSame( 'on-hold', $order->get_status() );
	}

	// â”€â”€ Failure result codes â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	/** @dataProvider failureCodeProvider */
	public function test_handle_failure_codes_set_order_failed( int $code, string $raw_desc, string $expected_fragment ): void {
		$order = new WC_Order( 1 );
		$this->mock_find_order( $order );

		$cb  = new WC_MPesa_Callback();
		$req = $this->make_request( $this->failure_payload( $code, $raw_desc ) );

		$response = $cb->handle( $req );

		$this->assertSame( 200, $response->status );
		$this->assertSame( 'failed', $order->get_status() );
	}

	public function failureCodeProvider(): array {
		return array(
			'insufficient_funds'         => array( 1,    'The balance is insufficient',     'Insufficient funds' ),
			'user_cancelled'             => array( 1032, 'Request cancelled by user',       'cancelled by the user' ),
			'wrong_pin'                  => array( 2001, 'Wrong PIN',                        'Wrong M-Pesa PIN' ),
			'ds_timeout'                 => array( 1037, 'DS timeout user cannot be reached','timed out' ),
			'mpesa_system_internal_error'=> array( 17,   'System internal error',            'internal error' ),
			'unable_to_lock'             => array( 1001, 'Unable to lock subscriber',        'Unable to lock' ),
			'unknown_code'               => array( 9999, 'Some unknown error',               'Some unknown error' ),
		);
	}
}
