<?php
/**
 * Customer-facing REST API for M-Pesa payment actions.
 *
 * @package WC_MPesa\API
 */

defined( 'ABSPATH' ) || exit;

class WC_MPesa_REST_API {
	private const JWT_ALGO = 'HS256';
	private const REST_NAMESPACE = 'wc-mpesa/v1';

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/payments/initiate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'initiate_payment' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/payments/(?P<order_id>\d+)/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_payment_status' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/payments/(?P<order_id>\d+)/query',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'query_payment_status' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/mobile/endpoints',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_mobile_endpoints' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Phone + password login using _wc_uac_phone.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function login( \WP_REST_Request $request ): \WP_REST_Response {
		$phone    = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		$password = (string) $request->get_param( 'password' );

		if ( '' === $phone || '' === $password ) {
			return $this->error_response( 'invalid_credentials', __( 'Invalid login credentials.', 'm-pesa-payment-gateway' ), 400 );
		}

		$user = $this->get_user_by_phone( $phone );
		if ( ! $user instanceof \WP_User || ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			return $this->error_response( 'invalid_credentials', __( 'Invalid login credentials.', 'm-pesa-payment-gateway' ), 401 );
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'user'    => array(
					'id'           => (int) $user->ID,
					'email'        => (string) $user->user_email,
					'display_name' => (string) $user->display_name,
					'phone'        => (string) get_user_meta( $user->ID, '_wc_uac_phone', true ),
				),
				'tokens'  => $this->issue_tokens_for_user( (int) $user->ID ),
			),
			200
		);
	}

	/**
	 * Refresh token endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function refresh_token( \WP_REST_Request $request ): \WP_REST_Response {
		$refresh_token = sanitize_text_field( (string) $request->get_param( 'refresh_token' ) );
		if ( '' === $refresh_token ) {
			return $this->error_response( 'unauthorized', __( 'Unauthorized.', 'm-pesa-payment-gateway' ), 401 );
		}

		$user_id = $this->validate_refresh_token( $refresh_token );
		if ( $user_id <= 0 ) {
			return $this->error_response( 'unauthorized', __( 'Unauthorized.', 'm-pesa-payment-gateway' ), 401 );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof \WP_User ) {
			return $this->error_response( 'unauthorized', __( 'Unauthorized.', 'm-pesa-payment-gateway' ), 401 );
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'tokens'  => $this->issue_tokens_for_user( (int) $user_id ),
			),
			200
		);
	}

	/**
	 * Initiate STK push payment for an existing order.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function initiate_payment( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id  = (int) $request->get_param( '_wc_mpesa_user_id' );
		$order_id = absint( $request->get_param( 'order_id' ) );
		$phone    = sanitize_text_field( (string) $request->get_param( 'phone' ) );

		if ( $order_id <= 0 || '' === $phone ) {
			return $this->error_response( 'invalid_request', __( 'Order ID and phone are required.', 'm-pesa-payment-gateway' ), 400 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->error_response( 'order_not_found', __( 'Order not found.', 'm-pesa-payment-gateway' ), 404 );
		}

		if ( ! $this->can_access_order( $order, $user_id ) ) {
			return $this->error_response( 'forbidden', __( 'You cannot access this order.', 'm-pesa-payment-gateway' ), 403 );
		}

		$status = $order->get_status();
		if ( in_array( $status, array( 'processing', 'completed' ), true ) ) {
			return $this->error_response( 'already_paid', __( 'This order is already paid.', 'm-pesa-payment-gateway' ), 409 );
		}

		$formatted_phone = WC_MPesa_STK_Push::format_phone( $phone );
		if ( null === $formatted_phone ) {
			return $this->error_response( 'invalid_phone', __( 'Invalid Safaricom phone number.', 'm-pesa-payment-gateway' ), 400 );
		}

		$amount = (int) ceil( (float) $order->get_total() );
		if ( $amount < 1 ) {
			return $this->error_response( 'invalid_amount', __( 'M-Pesa cannot process orders with a zero total.', 'm-pesa-payment-gateway' ), 400 );
		}

		$settings = $this->get_gateway_settings();
		$required = array( 'consumer_key', 'consumer_secret', 'shortcode', 'passkey' );
		foreach ( $required as $required_key ) {
			if ( empty( $settings[ $required_key ] ) ) {
				return $this->error_response( 'gateway_not_configured', __( 'M-Pesa gateway is not fully configured.', 'm-pesa-payment-gateway' ), 500 );
			}
		}

		$api      = $this->build_api_client( $settings );
		$stk_push = new WC_MPesa_STK_Push( $api );
		$response = $stk_push->initiate( $order, $formatted_phone, $settings );

		if ( is_wp_error( $response ) ) {
			$order->update_status( 'failed', '[M-Pesa API] STK Push initiation failed: ' . $response->get_error_message() );
			$order->save();
			return $this->error_response( 'stk_push_failed', $response->get_error_message(), 400 );
		}

		$order->update_status(
			'on-hold',
			__( 'Awaiting M-Pesa STK Push payment confirmation.', 'm-pesa-payment-gateway' )
		);
		$order->save();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'order'   => $this->build_order_status_payload( $order ),
				'mpesa'   => array(
					'merchant_request_id' => sanitize_text_field( (string) ( $response['MerchantRequestID'] ?? '' ) ),
					'checkout_request_id' => sanitize_text_field( (string) ( $response['CheckoutRequestID'] ?? '' ) ),
					'customer_message'    => sanitize_text_field( (string) ( $response['CustomerMessage'] ?? '' ) ),
					'response_code'       => sanitize_text_field( (string) ( $response['ResponseCode'] ?? '' ) ),
					'response_description'=> sanitize_text_field( (string) ( $response['ResponseDescription'] ?? '' ) ),
				),
			),
			200
		);
	}

	/**
	 * Return mapped order/payment status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_payment_status( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id  = (int) $request->get_param( '_wc_mpesa_user_id' );
		$order_id = absint( $request->get_param( 'order_id' ) );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return $this->error_response( 'order_not_found', __( 'Order not found.', 'm-pesa-payment-gateway' ), 404 );
		}
		if ( ! $this->can_access_order( $order, $user_id ) ) {
			return $this->error_response( 'forbidden', __( 'You cannot access this order.', 'm-pesa-payment-gateway' ), 403 );
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'order'   => $this->build_order_status_payload( $order ),
			),
			200
		);
	}

	/**
	 * Query Daraja directly using stored checkout request ID.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function query_payment_status( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id  = (int) $request->get_param( '_wc_mpesa_user_id' );
		$order_id = absint( $request->get_param( 'order_id' ) );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return $this->error_response( 'order_not_found', __( 'Order not found.', 'm-pesa-payment-gateway' ), 404 );
		}
		if ( ! $this->can_access_order( $order, $user_id ) ) {
			return $this->error_response( 'forbidden', __( 'You cannot access this order.', 'm-pesa-payment-gateway' ), 403 );
		}

		$checkout_request_id = (string) $order->get_meta( '_mpesa_checkout_request_id' );
		if ( '' === $checkout_request_id ) {
			return $this->error_response( 'missing_checkout_request_id', __( 'No M-Pesa CheckoutRequestID found for this order.', 'm-pesa-payment-gateway' ), 409 );
		}

		$settings = $this->get_gateway_settings();
		$required = array( 'consumer_key', 'consumer_secret', 'shortcode', 'passkey' );
		foreach ( $required as $required_key ) {
			if ( empty( $settings[ $required_key ] ) ) {
				return $this->error_response( 'gateway_not_configured', __( 'M-Pesa gateway is not fully configured.', 'm-pesa-payment-gateway' ), 500 );
			}
		}

		$api      = $this->build_api_client( $settings );
		$response = $api->stk_push_query(
			$checkout_request_id,
			(string) $settings['shortcode'],
			(string) $settings['passkey']
		);

		if ( is_wp_error( $response ) ) {
			return $this->error_response( 'query_failed', $response->get_error_message(), 400 );
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'order'   => $this->build_order_status_payload( $order ),
				'daraja'  => $response,
			),
			200
		);
	}

	/**
	 * Return the mobile app payment endpoint map.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_mobile_endpoints(): \WP_REST_Response {
		$base_url = rtrim( set_url_scheme( (string) rest_url( self::REST_NAMESPACE ), 'https' ), '/' ) . '/';
		$uac_url  = rtrim( set_url_scheme( (string) rest_url( 'wc-uac/v1' ), 'https' ), '/' ) . '/';

		return new \WP_REST_Response(
			array(
				'success'   => true,
				'namespace' => self::REST_NAMESPACE,
				'base_url'  => $base_url,
				'auth'      => array(
					'scheme'            => 'Bearer',
					'access_token_url'  => $uac_url . 'auth/login',
					'refresh_token_url' => $uac_url . 'auth/token/refresh',
				),
				'endpoints' => array(
					'payment_initiate' => array(
						'method'      => 'POST',
						'protected'   => true,
						'url'         => $base_url . 'payments/initiate',
						'description' => __( 'Initiate an STK Push for an order.', 'm-pesa-payment-gateway' ),
					),
					'payment_status'   => array(
						'method'      => 'GET',
						'protected'   => true,
						'url'         => $base_url . 'payments/{order_id}/status',
						'description' => __( 'Get the mapped WooCommerce order/payment status.', 'm-pesa-payment-gateway' ),
					),
					'payment_query'    => array(
						'method'      => 'GET',
						'protected'   => true,
						'url'         => $base_url . 'payments/{order_id}/query',
						'description' => __( 'Query Daraja using the stored CheckoutRequestID.', 'm-pesa-payment-gateway' ),
					),
					'public_status'    => array(
						'method'      => 'GET',
						'protected'   => false,
						'url'         => $base_url . 'status/{order_id}?key=<order_key>',
						'description' => __( 'Public checkout polling endpoint.', 'm-pesa-payment-gateway' ),
					),
					'callback'         => array(
						'method'      => 'POST',
						'protected'   => false,
						'url'         => $base_url . 'callback?secret=<callback_secret>',
						'description' => __( 'Daraja STK Push callback endpoint.', 'm-pesa-payment-gateway' ),
					),
				),
			),
			200
		);
	}

	/**
	 * Permission callback for protected endpoints.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function require_auth( \WP_REST_Request $request ) {
		if ( ! is_ssl() ) {
			return new \WP_Error( 'https_required', __( 'HTTPS is required for authenticated M-Pesa API requests.', 'm-pesa-payment-gateway' ), array( 'status' => 403 ) );
		}

		$token = $this->get_bearer_token( $request );
		if ( '' === $token ) {
			return new \WP_Error( 'unauthorized', __( 'Unauthorized.', 'm-pesa-payment-gateway' ), array( 'status' => 401 ) );
		}

		$payload = $this->decode_jwt( $token );
		if ( is_wp_error( $payload ) ) {
			return new \WP_Error( 'unauthorized', __( 'Unauthorized.', 'm-pesa-payment-gateway' ), array( 'status' => 401 ) );
		}

		$user_id = isset( $payload['sub'] ) ? (int) $payload['sub'] : 0;
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'unauthorized', __( 'Unauthorized.', 'm-pesa-payment-gateway' ), array( 'status' => 401 ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'unauthorized', __( 'Unauthorized.', 'm-pesa-payment-gateway' ), array( 'status' => 401 ) );
		}

		if ( class_exists( 'WC_UAC_User_Meta' ) && 'suspended' === WC_UAC_User_Meta::get_status( $user_id ) ) {
			return new \WP_Error( 'unauthorized', __( 'Unauthorized.', 'm-pesa-payment-gateway' ), array( 'status' => 401 ) );
		}

		wp_set_current_user( $user_id );
		$request->set_param( '_wc_mpesa_user_id', $user_id );
		return true;
	}

	/**
	 * Build gateway settings from WC gateway options.
	 *
	 * @return array<string, string>
	 */
	private function get_gateway_settings(): array {
		$settings = get_option( 'woocommerce_wc_mpesa_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		return array(
			'environment'             => (string) ( $settings['environment'] ?? 'sandbox' ),
			'consumer_key'            => (string) ( $settings['consumer_key'] ?? '' ),
			'consumer_secret'         => (string) ( $settings['consumer_secret'] ?? '' ),
			'shortcode'               => (string) ( $settings['shortcode'] ?? '' ),
			'passkey'                 => (string) ( $settings['passkey'] ?? '' ),
			'transaction_type'        => (string) ( $settings['transaction_type'] ?? 'CustomerPayBillOnline' ),
			'account_reference'       => (string) ( $settings['account_reference'] ?? '' ),
			'transaction_description' => (string) ( $settings['transaction_description'] ?? 'WooCommerce' ),
		);
	}

	/**
	 * Build API client from gateway settings.
	 *
	 * @param array<string, string> $settings Gateway settings.
	 * @return WC_MPesa_API
	 */
	private function build_api_client( array $settings ): WC_MPesa_API {
		$request = new WC_MPesa_Request();
		$auth    = new WC_MPesa_Auth(
			$request,
			(string) $settings['consumer_key'],
			(string) $settings['consumer_secret'],
			(string) $settings['environment']
		);
		return new WC_MPesa_API( $auth, $request );
	}

	/**
	 * Ensure a user can access an order.
	 *
	 * @param \WC_Order $order   Order.
	 * @param int       $user_id Authenticated user ID.
	 * @return bool
	 */
	private function can_access_order( \WC_Order $order, int $user_id ): bool {
		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}
		return $user_id > 0 && (int) $order->get_user_id() === $user_id;
	}

	/**
	 * Build order payment status payload.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<string, mixed>
	 */
	private function build_order_status_payload( \WC_Order $order ): array {
		$order_status = (string) $order->get_status();
		if ( in_array( $order_status, array( 'processing', 'completed' ), true ) ) {
			$payment_status = 'success';
		} elseif ( 'failed' === $order_status ) {
			$payment_status = 'failed';
		} else {
			$payment_status = 'pending';
		}

		return array(
			'id'                    => (int) $order->get_id(),
			'order_status'          => $order_status,
			'payment_status'        => $payment_status,
			'total'                 => (float) $order->get_total(),
			'currency'              => (string) $order->get_currency(),
			'checkout_request_id'   => (string) $order->get_meta( '_mpesa_checkout_request_id' ),
			'merchant_request_id'   => (string) $order->get_meta( '_mpesa_merchant_request_id' ),
			'receipt_number'        => (string) $order->get_meta( '_mpesa_receipt_number' ),
			'transaction_date'      => (string) $order->get_meta( '_mpesa_transaction_date' ),
			'phone_used'            => (string) $order->get_meta( '_mpesa_phone_used' ),
			'return_url'            => $order->get_checkout_order_received_url(),
		);
	}

	/**
	 * Find WP user by UAC phone formats.
	 *
	 * @param string $phone Raw phone.
	 * @return \WP_User|null
	 */
	private function get_user_by_phone( string $phone ): ?\WP_User {
		$candidate_values = array();
		$raw              = trim( $phone );

		if ( '' !== $raw ) {
			$candidate_values[] = $raw;
		}

		$formatted = WC_MPesa_STK_Push::format_phone( $raw );
		if ( null !== $formatted ) {
			$candidate_values[] = $formatted;
			$candidate_values[] = '+' . $formatted;
		}

		$candidate_values = array_values( array_unique( $candidate_values ) );
		foreach ( $candidate_values as $value ) {
			$users = get_users(
				array(
					'meta_key'   => '_wc_uac_phone',
					'meta_value' => sanitize_text_field( $value ),
					'number'     => 1,
				)
			);
			if ( ! empty( $users ) && $users[0] instanceof \WP_User ) {
				return $users[0];
			}
		}

		return null;
	}

	/**
	 * Issue access + refresh tokens.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, mixed>
	 */
	private function issue_tokens_for_user( int $user_id ): array {
		$access_ttl = $this->get_access_token_ttl();
		$access     = $this->encode_jwt(
			array(
				'sub' => $user_id,
				'iat' => time(),
				'exp' => time() + $access_ttl,
			)
		);

		return array(
			'token_type'         => 'Bearer',
			'access_token'       => $access,
			'expires_in'         => $access_ttl,
			'refresh_token'      => $this->create_refresh_token( $user_id ),
			'refresh_expires_in' => $this->get_refresh_token_ttl(),
		);
	}

	/**
	 * Encode JWT.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @return string
	 */
	private function encode_jwt( array $payload ): string {
		$header = array(
			'typ' => 'JWT',
			'alg' => self::JWT_ALGO,
		);

		$head      = $this->base64url_encode( wp_json_encode( $header ) );
		$body      = $this->base64url_encode( wp_json_encode( $payload ) );
		$signature = hash_hmac( 'sha256', $head . '.' . $body, $this->get_jwt_secret(), true );

		return $head . '.' . $body . '.' . $this->base64url_encode( $signature );
	}

	/**
	 * Decode and validate JWT.
	 *
	 * @param string $token Token.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function decode_jwt( string $token ) {
		$jwt_secret = $this->get_jwt_secret();
		if ( '' === $jwt_secret ) {
			return new \WP_Error( 'invalid_token', 'JWT secret not configured.' );
		}

		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return new \WP_Error( 'invalid_token', 'Invalid token format.' );
		}

		list( $head, $body, $sig ) = $parts;
		$header  = json_decode( $this->base64url_decode( $head ), true );
		$payload = json_decode( $this->base64url_decode( $body ), true );

		if ( ! is_array( $header ) || ! is_array( $payload ) || ( $header['alg'] ?? '' ) !== self::JWT_ALGO ) {
			return new \WP_Error( 'invalid_token', 'Invalid token payload.' );
		}

		$expected = hash_hmac( 'sha256', $head . '.' . $body, $jwt_secret, true );
		$actual   = $this->base64url_decode( $sig );

		if ( ! hash_equals( $expected, $actual ) ) {
			return new \WP_Error( 'invalid_token', 'Invalid token signature.' );
		}

		$exp = isset( $payload['exp'] ) ? (int) $payload['exp'] : 0;
		if ( $exp <= 0 || time() >= $exp ) {
			return new \WP_Error( 'expired_token', 'Token expired.' );
		}

		return $payload;
	}

	/**
	 * Bearer token parser.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private function get_bearer_token( \WP_REST_Request $request ): string {
		$header = (string) $request->get_header( 'authorization' );
		if ( '' === $header ) {
			return '';
		}
		if ( 1 !== preg_match( '/Bearer\s+(.+)$/i', $header, $matches ) ) {
			return '';
		}
		return trim( $matches[1] );
	}

	/**
	 * JWT secret provider.
	 *
	 * @return string
	 */
	private function get_jwt_secret(): string {
		$secret = (string) get_option( 'wc_uac_jwt_secret', '' );
		if ( '' !== $secret ) {
			return $secret;
		}
		return '';
	}

	/**
	 * Access token TTL.
	 *
	 * @return int
	 */
	private function get_access_token_ttl(): int {
		$ttl = (int) get_option( 'wc_mpesa_api_jwt_access_ttl', 3600 );
		return max( 300, min( 86400, $ttl ) );
	}

	/**
	 * Refresh token TTL.
	 *
	 * @return int
	 */
	private function get_refresh_token_ttl(): int {
		$ttl = (int) get_option( 'wc_mpesa_api_jwt_refresh_ttl', 1209600 );
		return max( 3600, min( 2592000, $ttl ) );
	}

	/**
	 * Create refresh token.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function create_refresh_token( int $user_id ): string {
		$token = wp_generate_password( 64, true, true );
		$key   = 'wc_mpesa_refresh_' . hash( 'sha256', $token );
		$ttl   = $this->get_refresh_token_ttl();

		set_transient(
			$key,
			array(
				'user_id' => $user_id,
			),
			$ttl
		);

		return $token;
	}

	/**
	 * Validate and consume refresh token.
	 *
	 * @param string $token Refresh token.
	 * @return int
	 */
	private function validate_refresh_token( string $token ): int {
		$key  = 'wc_mpesa_refresh_' . hash( 'sha256', $token );
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			return 0;
		}

		$user_id = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;
		if ( $user_id <= 0 ) {
			return 0;
		}

		delete_transient( $key );
		return $user_id;
	}

	/**
	 * Base64URL encode helper.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function base64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Base64URL decode helper.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function base64url_decode( string $value ): string {
		$remainder = strlen( $value ) % 4;
		if ( $remainder ) {
			$value .= str_repeat( '=', 4 - $remainder );
		}

		$decoded = base64_decode( strtr( $value, '-_', '+/' ) );
		return false === $decoded ? '' : $decoded;
	}

	/**
	 * Standardized error response helper.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status.
	 * @return \WP_REST_Response
	 */
	private function error_response( string $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'success' => false,
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}
}
