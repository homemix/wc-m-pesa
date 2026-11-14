<?php
/**
 * Tests for WC_MPesa_STK_Push â€” initiation, phone formatting, HTTPS guard.
 *
 * @package WC_MPesa\Tests\Unit
 */

namespace WCMPesa\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use WC_MPesa_API;
use WC_MPesa_STK_Push;
use WC_Order;
use WP_Error;

/**
 * @covers WC_MPesa_STK_Push
 */
class StkPushTest extends WCMPesaTestCase {

	private function make_order( int $id = 1, float $total = 100.0 ): WC_Order {
		$order = new WC_Order( $id );
		$order->set_total( $total );
		return $order;
	}

	private function default_settings(): array {
		return array(
			'shortcode'               => '174379',
			'passkey'                 => 'bfb279f9',
			'transaction_type'        => 'CustomerPayBillOnline',
			'account_reference'       => 'TestShop',
			'transaction_description' => 'WooCommerce',
		);
	}

	// â”€â”€ Successful STK push initiation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_initiate_stores_checkout_request_id_in_order_meta(): void {
		$api = Mockery::mock( WC_MPesa_API::class );
		$api->shouldReceive( 'stk_push' )
			->once()
			->andReturn( array(
				'CheckoutRequestID'   => 'checkout-abc',
				'MerchantRequestID'   => 'merchant-xyz',
				'ResponseCode'        => '0',
			) );

		Functions\expect( 'get_option' )->andReturn( 'valid_secret' );
		Functions\expect( 'rest_url' )->andReturn( 'https://example.com/wp-json/wc-mpesa/v1/callback' );
		Functions\expect( 'add_query_arg' )->andReturnFirstArg();

		$order   = $this->make_order();
		$handler = new WC_MPesa_STK_Push( $api );
		$result  = $handler->initiate( $order, '254712345678', $this->default_settings() );

		$this->assertIsArray( $result );
		$this->assertSame( 'checkout-abc', $order->get_meta( '_mpesa_checkout_request_id' ) );
		$this->assertSame( 'merchant-xyz', $order->get_meta( '_mpesa_merchant_request_id' ) );
	}

	public function test_initiate_returns_error_when_request_ids_are_missing(): void {
		$api = Mockery::mock( WC_MPesa_API::class );
		$api->shouldReceive( 'stk_push' )
			->once()
			->andReturn( array(
				'ResponseCode' => '0',
			) );

		Functions\expect( 'get_option' )->andReturn( 'valid_secret' );
		Functions\expect( 'rest_url' )->andReturn( 'https://example.com/wp-json/wc-mpesa/v1/callback' );
		Functions\expect( 'add_query_arg' )->andReturnFirstArg();

		$order   = $this->make_order();
		$handler = new WC_MPesa_STK_Push( $api );
		$result  = $handler->initiate( $order, '254712345678', $this->default_settings() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_mpesa_invalid_stk_response', $result->get_error_code() );
	}

	// â”€â”€ API error propagation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_initiate_propagates_api_wp_error(): void {
		$api = Mockery::mock( WC_MPesa_API::class );
		$api->shouldReceive( 'stk_push' )
			->once()
			->andReturn( new WP_Error( 'wc_mpesa_api_error', 'Bad credentials' ) );

		Functions\expect( 'get_option' )->andReturn( 'secret' );
		Functions\expect( 'rest_url' )->andReturn( 'https://example.com/wp-json/wc-mpesa/v1/callback' );
		Functions\expect( 'add_query_arg' )->andReturnFirstArg();

		$order   = $this->make_order();
		$handler = new WC_MPesa_STK_Push( $api );
		$result  = $handler->initiate( $order, '254712345678', $this->default_settings() );

		$this->assertInstanceOf( WP_Error::class, $result );
		// Meta should NOT be stored on error.
		$this->assertSame( '', $order->get_meta( '_mpesa_checkout_request_id' ) );
	}

	// â”€â”€ Zero-amount guard is in gateway (not stk-push class directly) â”€â”€â”€â”€â”€â”€â”€â”€â”€
	// Tested via GatewayTest; here we just verify the amount is passed through.

	public function test_initiate_passes_ceil_amount_to_api(): void {
		$api = Mockery::mock( WC_MPesa_API::class );
		$api->shouldReceive( 'stk_push' )
			->once()
			->withArgs( function ( $phone, $amount ) {
				return $amount === 101; // ceil(100.20) = 101
			} )
			->andReturn( array( 'CheckoutRequestID' => 'c1', 'MerchantRequestID' => 'm1', 'ResponseCode' => '0' ) );

		Functions\expect( 'get_option' )->andReturn( 'secret' );
		Functions\expect( 'rest_url' )->andReturn( 'https://example.com/wp-json/wc-mpesa/v1/callback' );
		Functions\expect( 'add_query_arg' )->andReturnFirstArg();

		$order = $this->make_order( 1, 100.20 );
		( new WC_MPesa_STK_Push( $api ) )->initiate( $order, '254712345678', $this->default_settings() );
		$this->assertTrue( true ); // assertion is inside withArgs
	}
}
