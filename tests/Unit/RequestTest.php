<?php
/**
 * Tests for WC_MPesa_Request
 *
 * @package WC_MPesa\Tests\Unit
 */

namespace WCMPesa\Tests\Unit;

use Brain\Monkey\Functions;
use WC_MPesa_Request;
use WP_Error;

/**
 * @covers WC_MPesa_Request
 */
class RequestTest extends WCMPesaTestCase {

	// â”€â”€ POST â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_post_success_returns_decoded_array(): void {
		Functions\expect( 'wp_json_encode' )
			->once()
			->andReturn( '{"foo":"bar"}' );

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturn( array( '_response_mock' => true ) );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( '{"MerchantRequestID":"abc","CheckoutRequestID":"xyz"}' );

		$req    = new WC_MPesa_Request();
		$result = $req->post( 'https://example.com/api', array( 'foo' => 'bar' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'abc', $result['MerchantRequestID'] );
	}

	public function test_post_wp_error_propagated(): void {
		$error = new WP_Error( 'http_request_failed', 'Connection refused' );

		Functions\expect( 'wp_json_encode' )->once()->andReturn( '{}' );
		Functions\expect( 'wp_remote_post' )->once()->andReturn( $error );

		$req    = new WC_MPesa_Request();
		$result = $req->post( 'https://example.com/api', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
	}

	public function test_post_encodes_body_as_json_false_returns_wp_error(): void {
		Functions\expect( 'wp_json_encode' )->once()->andReturn( false );

		$req    = new WC_MPesa_Request();
		$result = $req->post( 'https://example.com/api', array( "\x80" => 'invalid-utf8' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_mpesa_encode_error', $result->get_error_code() );
	}

	// â”€â”€ HTTP error codes â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_post_400_returns_wp_error(): void {
		Functions\expect( 'wp_json_encode' )->once()->andReturn( '{}' );
		Functions\expect( 'wp_remote_post' )->once()->andReturn( array() );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 400 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( '{"errorMessage":"Bad request","errorCode":"400.002.02"}' );

		$req    = new WC_MPesa_Request();
		$result = $req->post( 'https://example.com/api', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_mpesa_api_error', $result->get_error_code() );
		$data = $result->get_error_data( 'wc_mpesa_api_error' );
		$this->assertSame( 400, $data['status'] );
	}

	public function test_post_401_returns_wp_error_with_401_status(): void {
		Functions\expect( 'wp_json_encode' )->once()->andReturn( '{}' );
		Functions\expect( 'wp_remote_post' )->once()->andReturn( array() );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 401 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( '{"errorMessage":"Invalid credentials"}' );

		$req    = new WC_MPesa_Request();
		$result = $req->post( 'https://example.com/api', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$data = $result->get_error_data( 'wc_mpesa_api_error' );
		$this->assertSame( 401, $data['status'] );
	}

	public function test_post_empty_status_code_returns_wp_error(): void {
		Functions\expect( 'wp_json_encode' )->once()->andReturn( '{}' );
		Functions\expect( 'wp_remote_post' )->once()->andReturn( array() );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( '' ); // malformed
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( 'garbage' );

		$req    = new WC_MPesa_Request();
		$result = $req->post( 'https://example.com/api', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_mpesa_bad_response', $result->get_error_code() );
	}

	public function test_post_invalid_json_returns_wp_error(): void {
		Functions\expect( 'wp_json_encode' )->once()->andReturn( '{}' );
		Functions\expect( 'wp_remote_post' )->once()->andReturn( array() );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( 'not-json!!!' );

		$req    = new WC_MPesa_Request();
		$result = $req->post( 'https://example.com/api', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wc_mpesa_invalid_json', $result->get_error_code() );
	}

	// â”€â”€ GET â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

	public function test_get_success_returns_decoded_array(): void {
		Functions\expect( 'wp_remote_get' )->once()->andReturn( array() );
		Functions\expect( 'wp_remote_retrieve_response_code' )->once()->andReturn( 200 );
		Functions\expect( 'wp_remote_retrieve_body' )->once()->andReturn( '{"access_token":"tok123","expires_in":"3599"}' );

		$req    = new WC_MPesa_Request();
		$result = $req->get( 'https://example.com/oauth', array( 'Authorization' => 'Basic abc' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'tok123', $result['access_token'] );
	}
}
