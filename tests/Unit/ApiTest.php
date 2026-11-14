<?php
/**
 * Tests for WC_MPesa_API â€” STK Push and query.
 *
 * @package WC_MPesa\Tests\Unit
 */

namespace WCMPesa\Tests\Unit;

use Mockery;
use WC_MPesa_API;
use WC_MPesa_Auth;
use WC_MPesa_Request;
use WP_Error;

/**
 * @covers WC_MPesa_API
 */
class ApiTest extends WCMPesaTestCase {

	/** @return array{ api: WC_MPesa_API, auth: WC_MPesa_Auth&Mockery\MockInterface, request: WC_MPesa_Request&Mockery\MockInterface } */
	private function make(): array {
		$auth    = Mockery::mock( WC_MPesa_Auth::class );
		$request = Mockery::mock( WC_MPesa_Request::class );
		$api     = new WC_MPesa_API( $auth, $request );
		return compact( 'api', 'auth', 'request' );
	}

	private function stk_success_response(): array {
		return array(
			'MerchantRequestID'  => 'merchant-001',
			'CheckoutRequestID'  => 'checkout-001',
			'ResponseCode'       => '0',
			'ResponseDescription'=> 'Success. Request accepted for processing',
			'CustomerMessage'    => 'Success. Request accepted for processing',
		);
	}

	// â”€â”€ STK Push success â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_stk_push_success_returns_response_array(): void {
		[ 'api' => $api, 'auth' => $auth, 'request' => $request ] = $this->make();

		$auth->shouldReceive( 'get_token' )->once()->andReturn( 'valid_token' );
		$auth->shouldReceive( 'base_url' )->once()->andReturn( 'https://sandbox.safaricom.co.ke' );

		$request->shouldReceive( 'post' )
			->once()
			->with( Mockery::type( 'string' ), Mockery::type( 'array' ), Mockery::type( 'array' ) )
			->andReturn( $this->stk_success_response() );

		$result = $api->stk_push(
			'254712345678', 100, '174379', 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919',
			'https://example.com/callback', 'Order123', 'WooCommerce'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'checkout-001', $result['CheckoutRequestID'] );
	}

	// â”€â”€ Auth failure propagation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_stk_push_propagates_auth_error(): void {
		[ 'api' => $api, 'auth' => $auth ] = $this->make();

		$error = new WP_Error( 'wc_mpesa_missing_credentials', 'No credentials' );
		$auth->shouldReceive( 'get_token' )->once()->andReturn( $error );

		$result = $api->stk_push( '254712345678', 100, '174379', 'passkey', 'https://cb.example.com', 'Ref', 'Desc' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_mpesa_missing_credentials', $result->get_error_code() );
	}

	// â”€â”€ 401 token invalidation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_stk_push_401_invalidates_token(): void {
		[ 'api' => $api, 'auth' => $auth, 'request' => $request ] = $this->make();

		$auth->shouldReceive( 'get_token' )->once()->andReturn( 'stale_token' );
		$auth->shouldReceive( 'base_url' )->once()->andReturn( 'https://sandbox.safaricom.co.ke' );
		$auth->shouldReceive( 'invalidate_token' )->once();

		$error_401 = new WP_Error( 'wc_mpesa_api_error', 'Unauthorized', array( 'status' => 401 ) );
		$request->shouldReceive( 'post' )->once()->andReturn( $error_401 );

		$result = $api->stk_push( '254712345678', 100, '174379', 'passkey', 'https://cb.example.com', 'Ref', 'Desc' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// â”€â”€ Non-401 error does NOT invalidate token â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_stk_push_500_does_not_invalidate_token(): void {
		[ 'api' => $api, 'auth' => $auth, 'request' => $request ] = $this->make();

		$auth->shouldReceive( 'get_token' )->once()->andReturn( 'good_token' );
		$auth->shouldReceive( 'base_url' )->once()->andReturn( 'https://sandbox.safaricom.co.ke' );
		$auth->shouldReceive( 'invalidate_token' )->never();

		$error_500 = new WP_Error( 'wc_mpesa_api_error', 'Server Error', array( 'status' => 500 ) );
		$request->shouldReceive( 'post' )->once()->andReturn( $error_500 );

		$result = $api->stk_push( '254712345678', 100, '174379', 'passkey', 'https://cb.example.com', 'Ref', 'Desc' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// â”€â”€ AccountReference / TransactionDesc truncation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_account_reference_truncated_to_12_chars(): void {
		[ 'api' => $api, 'auth' => $auth, 'request' => $request ] = $this->make();

		$auth->shouldReceive( 'get_token' )->once()->andReturn( 'tok' );
		$auth->shouldReceive( 'base_url' )->once()->andReturn( 'https://sandbox.safaricom.co.ke' );

		$request->shouldReceive( 'post' )
			->once()
			->withArgs( function ( $url, $body ) {
				return strlen( $body['AccountReference'] ) <= 12;
			} )
			->andReturn( $this->stk_success_response() );

		$api->stk_push( '254712345678', 100, '174379', 'passkey', 'https://cb.example.com', 'VeryLongAccountRef', 'Desc' );
		$this->assertTrue( true ); // assertion is inside withArgs
	}

	public function test_transaction_desc_truncated_to_13_chars(): void {
		[ 'api' => $api, 'auth' => $auth, 'request' => $request ] = $this->make();

		$auth->shouldReceive( 'get_token' )->once()->andReturn( 'tok' );
		$auth->shouldReceive( 'base_url' )->once()->andReturn( 'https://sandbox.safaricom.co.ke' );

		$request->shouldReceive( 'post' )
			->once()
			->withArgs( function ( $url, $body ) {
				return strlen( $body['TransactionDesc'] ) <= 13;
			} )
			->andReturn( $this->stk_success_response() );

		$api->stk_push( '254712345678', 100, '174379', 'passkey', 'https://cb.example.com', 'Ref', 'This description is way too long' );
		$this->assertTrue( true );
	}

	// â”€â”€ EAT timestamp â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_stk_push_timestamp_is_14_digits(): void {
		[ 'api' => $api, 'auth' => $auth, 'request' => $request ] = $this->make();

		$auth->shouldReceive( 'get_token' )->once()->andReturn( 'tok' );
		$auth->shouldReceive( 'base_url' )->once()->andReturn( 'https://sandbox.safaricom.co.ke' );

		$request->shouldReceive( 'post' )
			->once()
			->withArgs( function ( $url, $body ) {
				return preg_match( '/^\d{14}$/', $body['Timestamp'] ) === 1;
			} )
			->andReturn( $this->stk_success_response() );

		$api->stk_push( '254712345678', 100, '174379', 'passkey', 'https://cb.example.com', 'Ref', 'Desc' );
		$this->assertTrue( true );
	}
}

