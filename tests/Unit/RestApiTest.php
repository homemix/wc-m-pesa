<?php
/**
 * Tests for WC_MPesa_REST_API.
 *
 * @package WC_MPesa\Tests\Unit
 */

namespace WCMPesa\Tests\Unit;

use Brain\Monkey\Functions;
use WC_MPesa_REST_API;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers WC_MPesa_REST_API
 */
class RestApiTest extends WCMPesaTestCase {

	public function test_require_auth_without_bearer_token_returns_error(): void {
		$api = new WC_MPesa_REST_API();
		$req = new WP_REST_Request();

		$result = $api->require_auth( $req );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unauthorized', $result->get_error_code() );
	}

	public function test_require_auth_rejects_non_https_requests(): void {
		Functions\when( 'is_ssl' )->justReturn( false );

		$api = new WC_MPesa_REST_API();
		$req = new WP_REST_Request();
		$req->set_header( 'authorization', 'Bearer token' );

		$result = $api->require_auth( $req );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'https_required', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_require_auth_accepts_wc_uac_jwt_bearer_token(): void {
		Functions\expect( 'get_option' )
			->with( 'wc_uac_jwt_secret', '' )
			->andReturn( 'shared_secret' );
		Functions\expect( 'get_userdata' )
			->once()
			->andReturn( new \WP_User( 7 ) );
		Functions\expect( 'wp_set_current_user' )
			->once()
			->with( 7 );

		$api = new WC_MPesa_REST_API();
		$req = new WP_REST_Request();
		$req->set_header( 'authorization', 'Bearer ' . $this->make_jwt( 7, 'shared_secret', time() + 3600 ) );

		$result = $api->require_auth( $req );

		$this->assertTrue( $result );
		$this->assertSame( 7, $req->get_param( '_wc_mpesa_user_id' ) );
	}

	public function test_register_routes_exposes_no_mpesa_auth_routes(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) use ( &$routes ) {
				$routes[] = $route;
				return true;
			}
		);

		$api = new WC_MPesa_REST_API();
		$api->register_routes();

		$this->assertContains( '/payments/initiate', $routes );
		$this->assertContains( '/mobile/endpoints', $routes );
		$this->assertNotContains( '/auth/login', $routes );
		$this->assertNotContains( '/auth/token/refresh', $routes );
	}

	public function test_get_payment_status_returns_not_found_for_unknown_order(): void {
		Functions\expect( 'wc_get_order' )->once()->andReturn( null );

		$api = new WC_MPesa_REST_API();
		$req = new WP_REST_Request();
		$req->set_param( '_wc_mpesa_user_id', 1 );
		$req->set_param( 'order_id', 9999 );

		$response = $api->get_payment_status( $req );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 404, $response->status );
		$this->assertFalse( $response->data['success'] );
	}

	public function test_get_mobile_endpoints_returns_full_urls(): void {
		Functions\when( 'rest_url' )->alias(
			function ( $path ) {
				if ( 'wc-mpesa/v1' === $path ) {
					return 'http://example.com/wp-json/wc-mpesa/v1';
				}
				if ( 'wc-uac/v1' === $path ) {
					return 'http://example.com/wp-json/wc-uac/v1';
				}
				return '';
			}
		);
		Functions\when( 'set_url_scheme' )->alias(
			function ( $url, $scheme ) {
				return preg_replace( '/^https?:/', $scheme . ':', $url );
			}
		);

		$api = new WC_MPesa_REST_API();
		$response = $api->get_mobile_endpoints();

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->status );
		$this->assertSame( 'https://example.com/wp-json/wc-mpesa/v1/', $response->data['base_url'] );
		$this->assertSame( 'https://example.com/wp-json/wc-uac/v1/auth/login', $response->data['auth']['access_token_url'] );
		$this->assertSame( 'https://example.com/wp-json/wc-mpesa/v1/payments/initiate', $response->data['endpoints']['payment_initiate']['url'] );
	}

	private function make_jwt( int $user_id, string $secret, int $exp ): string {
		$header = array(
			'typ' => 'JWT',
			'alg' => 'HS256',
		);

		$payload = array(
			'sub' => $user_id,
			'iat' => time(),
			'exp' => $exp,
		);

		$head = rtrim( strtr( base64_encode( json_encode( $header ) ), '+/', '-_' ), '=' );
		$body = rtrim( strtr( base64_encode( json_encode( $payload ) ), '+/', '-_' ), '=' );
		$sig  = hash_hmac( 'sha256', $head . '.' . $body, $secret, true );

		return $head . '.' . $body . '.' . rtrim( strtr( base64_encode( $sig ), '+/', '-_' ), '=' );
	}
}
