<?php
/**
 * Handles OAuth2 access token retrieval and caching for the Daraja API.
 *
 * @package WC_MPesa\API
 */

defined( 'ABSPATH' ) || exit;

/**
 * Obtains and caches a Daraja OAuth2 Bearer token.
 *
 * Tokens are valid for 3600 seconds; we cache them in a WP transient with a
 * 55-minute TTL (3300 s) to avoid using an expired token.
 */
class WC_MPesa_Auth {

	private const TOKEN_TTL = 3300; // 55 minutes in seconds.

	/** @var WC_MPesa_Request */
	private WC_MPesa_Request $request;

	/** @var string */
	private string $consumer_key;

	/** @var string */
	private string $consumer_secret;

	/** @var string */
	private string $environment;

	/** @var string Transient key scoped to environment + credentials. */
	private string $transient_key;

	/**
	 * @param WC_MPesa_Request $request         HTTP client.
	 * @param string           $consumer_key    Daraja app consumer key.
	 * @param string           $consumer_secret Daraja app consumer secret.
	 * @param string           $environment     'sandbox' or 'production'.
	 */
	public function __construct(
		WC_MPesa_Request $request,
		string $consumer_key,
		string $consumer_secret,
		string $environment = 'sandbox'
	) {
		$this->request       = $request;
		$this->consumer_key  = $consumer_key;
		$this->consumer_secret = $consumer_secret;
		$this->environment   = $environment;
		// Scope the transient key so sandbox and production tokens never collide.
		$this->transient_key = 'wc_mpesa_token_' . md5( $environment . $consumer_key );
	}

	/**
	 * Return a valid Bearer token, fetching a new one if the cached one has expired.
	 *
	 * @return string|\WP_Error
	 */
	public function get_token() {
		$cached = get_transient( $this->transient_key );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		if ( empty( $this->consumer_key ) || empty( $this->consumer_secret ) ) {
			return new \WP_Error(
				'wc_mpesa_missing_credentials',
				__( 'M-Pesa Consumer Key and Consumer Secret must be configured in the gateway settings.', 'wc-m-pesa' )
			);
		}

		$url = $this->base_url() . '/oauth/v1/generate?grant_type=client_credentials';

		$credentials = base64_encode( $this->consumer_key . ':' . $this->consumer_secret );

		$response = $this->request->get(
			$url,
			array( 'Authorization' => 'Basic ' . $credentials )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$token = isset( $response['access_token'] ) ? trim( (string) $response['access_token'] ) : '';
		if ( '' === $token ) {
			return new \WP_Error(
				'wc_mpesa_auth_failed',
				__( 'M-Pesa authentication failed: no access_token in response.', 'wc-m-pesa' ),
				$response
			);
		}

		set_transient( $this->transient_key, $token, self::TOKEN_TTL );

		return $token;
	}

	/**
	 * Return the Daraja base URL for the configured environment.
	 *
	 * @return string
	 */
	public function base_url(): string {
		return 'production' === $this->environment
			? 'https://api.safaricom.co.ke'
			: 'https://sandbox.safaricom.co.ke';
	}

	/**
	 * Invalidate the cached token (e.g. after a 401 response).
	 */
	public function invalidate_token(): void {
		delete_transient( $this->transient_key );
	}
}
