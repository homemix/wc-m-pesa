<?php
/**
 * Tests for WC_MPesa_Auth â€” OAuth token retrieval and caching.
 *
 * @package WC_MPesa\Tests\Unit
 */

namespace WCMPesa\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use WC_MPesa_Auth;
use WC_MPesa_Request;
use WP_Error;

/**
 * @covers WC_MPesa_Auth
 */
class AuthTest extends WCMPesaTestCase {

	private function make_auth( string $env = 'sandbox', string $key = 'key', string $secret = 'secret' ): WC_MPesa_Auth {
		$request = Mockery::mock( WC_MPesa_Request::class );
		return new WC_MPesa_Auth( $request, $key, $secret, $env );
	}

	// â”€â”€ Cache hit â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_get_token_returns_cached_token(): void {
		Functions\expect( 'get_transient' )
			->once()
			->andReturn( 'cached_token_abc' );

		$auth  = $this->make_auth();
		$token = $auth->get_token();

		$this->assertSame( 'cached_token_abc', $token );
	}

	// â”€â”€ Cache miss + successful fetch â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_get_token_fetches_and_caches_when_no_transient(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );
		Functions\expect( 'set_transient' )->once()->andReturn( true );

		$request = Mockery::mock( WC_MPesa_Request::class );
		$request->shouldReceive( 'get' )
			->once()
			->andReturn( array( 'access_token' => 'fresh_token_xyz', 'expires_in' => '3599' ) );

		$auth  = new WC_MPesa_Auth( $request, 'key', 'secret', 'sandbox' );
		$token = $auth->get_token();

		$this->assertSame( 'fresh_token_xyz', $token );
	}

	// â”€â”€ Missing credentials â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_get_token_empty_consumer_key_returns_wp_error(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );

		$request = Mockery::mock( WC_MPesa_Request::class );
		$auth    = new WC_MPesa_Auth( $request, '', 'secret', 'sandbox' );
		$result  = $auth->get_token();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_mpesa_missing_credentials', $result->get_error_code() );
	}

	public function test_get_token_empty_consumer_secret_returns_wp_error(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );

		$request = Mockery::mock( WC_MPesa_Request::class );
		$auth    = new WC_MPesa_Auth( $request, 'key', '', 'sandbox' );
		$result  = $auth->get_token();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_mpesa_missing_credentials', $result->get_error_code() );
	}

	// â”€â”€ API error â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_get_token_propagates_wp_error_from_request(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );

		$error   = new WP_Error( 'wc_mpesa_api_error', 'Unauthorized', array( 'status' => 401 ) );
		$request = Mockery::mock( WC_MPesa_Request::class );
		$request->shouldReceive( 'get' )->once()->andReturn( $error );

		$auth   = new WC_MPesa_Auth( $request, 'key', 'secret', 'sandbox' );
		$result = $auth->get_token();

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_get_token_missing_access_token_field_returns_wp_error(): void {
		Functions\expect( 'get_transient' )->once()->andReturn( false );

		$request = Mockery::mock( WC_MPesa_Request::class );
		$request->shouldReceive( 'get' )->once()->andReturn( array( 'unexpected_field' => 'value' ) );

		$auth   = new WC_MPesa_Auth( $request, 'key', 'secret', 'sandbox' );
		$result = $auth->get_token();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_mpesa_auth_failed', $result->get_error_code() );
	}

	// â”€â”€ Token invalidation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_invalidate_token_deletes_transient(): void {
		Functions\expect( 'delete_transient' )->once()->andReturn( true );

		$request = Mockery::mock( WC_MPesa_Request::class );
		$auth    = new WC_MPesa_Auth( $request, 'key', 'secret', 'sandbox' );
		$auth->invalidate_token(); // should not throw
		$this->assertTrue( true );
	}

	// â”€â”€ Transient key scoping â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_sandbox_and_production_use_different_transient_keys(): void {
		$keys_seen = array();

		Functions\when( 'get_transient' )->alias( function ( $key ) use ( &$keys_seen ) {
			$keys_seen[] = $key;
			return false;
		} );
		Functions\when( 'set_transient' )->justReturn( true );

		$req1 = Mockery::mock( WC_MPesa_Request::class );
		$req1->shouldReceive( 'get' )->andReturn( array( 'access_token' => 'sandbox_tok' ) );

		$req2 = Mockery::mock( WC_MPesa_Request::class );
		$req2->shouldReceive( 'get' )->andReturn( array( 'access_token' => 'prod_tok' ) );

		( new WC_MPesa_Auth( $req1, 'k', 's', 'sandbox' ) )->get_token();
		( new WC_MPesa_Auth( $req2, 'k', 's', 'production' ) )->get_token();

		$this->assertCount( 2, $keys_seen );
		$this->assertNotSame( $keys_seen[0], $keys_seen[1] );
	}

	// â”€â”€ Base URL â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_base_url_sandbox(): void {
		$auth = $this->make_auth( 'sandbox' );
		$this->assertStringContainsString( 'sandbox.safaricom.co.ke', $auth->base_url() );
	}

	public function test_base_url_production(): void {
		$auth = $this->make_auth( 'production' );
		$this->assertStringContainsString( 'api.safaricom.co.ke', $auth->base_url() );
		$this->assertStringNotContainsString( 'sandbox', $auth->base_url() );
	}
}

