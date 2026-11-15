<?php
/**
 * Lightweight HTTP request wrapper around WordPress's wp_remote_* functions.
 *
 * @package WC_MPesa\API
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provides thin wrappers for HTTP requests so the rest of the plugin
 * does not depend directly on the WP HTTP API.
 */
class WC_MPesa_Request {

	/**
	 * Send a POST request and return the decoded JSON body.
	 *
	 * @param string               $url     Full request URL.
	 * @param array<string, mixed> $body    Request body (will be JSON-encoded).
	 * @param array<string, mixed> $headers HTTP headers.
	 * @return array<string, mixed>|\WP_Error Decoded response body or WP_Error on failure.
	 */
	public function post( string $url, array $body, array $headers = array() ) {
		$json = wp_json_encode( $body );
		if ( false === $json ) {
			return new \WP_Error(
				'wc_mpesa_encode_error',
				__( 'Failed to JSON-encode the M-Pesa request body. Ensure all values are UTF-8.', 'm-pesa-payment-gateway' )
			);
		}

		$args = array(
			'method'  => 'POST',
			'headers' => array_merge(
				array( 'Content-Type' => 'application/json' ),
				$headers
			),
			'body'    => $json,
			'timeout' => 30,
		);

		$response = wp_remote_post( $url, $args );

		return $this->parse_response( $response );
	}

	/**
	 * Send a GET request and return the decoded JSON body.
	 *
	 * @param string               $url     Full request URL.
	 * @param array<string, mixed> $headers HTTP headers.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get( string $url, array $headers = array() ) {
		$args = array(
			'headers' => $headers,
			'timeout' => 30,
		);

		$response = wp_remote_get( $url, $args );

		return $this->parse_response( $response );
	}

	/**
	 * Parse a WP HTTP API response into a decoded array or WP_Error.
	 *
	 * @param array<string, mixed>|\WP_Error $response
	 * @return array<string, mixed>|\WP_Error
	 */
	private function parse_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Empty status code means a malformed HTTP response — treat as error.
		if ( 0 === $status_code ) {
			return new \WP_Error(
				'wc_mpesa_bad_response',
				__( 'M-Pesa API returned an unreadable response (no HTTP status code).', 'm-pesa-payment-gateway' ),
				array( 'body' => $body )
			);
		}

		$decoded = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error(
				'wc_mpesa_invalid_json',
				__( 'M-Pesa API returned an invalid JSON response.', 'm-pesa-payment-gateway' ),
				array( 'status' => $status_code, 'body' => $body )
			);
		}

		if ( $status_code >= 400 ) {
			$error_message = sanitize_text_field(
				$decoded['errorMessage'] ?? $decoded['ResultDesc'] ?? __( 'Unknown M-Pesa API error.', 'm-pesa-payment-gateway' )
			);
			return new \WP_Error(
				'wc_mpesa_api_error',
				$error_message,
				array( 'status' => $status_code, 'body' => $decoded )
			);
		}

		return is_array( $decoded ) ? $decoded : array();
	}
}
