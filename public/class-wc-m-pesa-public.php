<?php
/**
 * Public-facing functionality.
 *
 * @package WC_MPesa\Public
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles public-side asset enqueueing and REST route registration for
 * the order status polling endpoint used after an STK Push.
 */
class WC_MPesa_Public {

	/** @var string */
	private string $plugin_name;

	/** @var string */
	private string $version;

	/**
	 * @param string $plugin_name Plugin slug.
	 * @param string $version     Plugin version.
	 */
	public function __construct( string $plugin_name, string $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Enqueue the public stylesheet on checkout and order-received pages.
	 */
	public function enqueue_styles(): void {
		if ( ! is_checkout() ) {
			return;
		}

		wp_enqueue_style(
			$this->plugin_name,
			WC_MPESA_PLUGIN_URL . 'public/css/wc-m-pesa-public.css',
			array(),
			$this->version
		);
	}

	/**
	 * Enqueue the public JS (polling script) on the order-received page.
	 */
	public function enqueue_scripts(): void {
		if ( ! is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		wp_enqueue_script(
			$this->plugin_name . '-polling',
			WC_MPESA_PLUGIN_URL . 'public/js/wc-m-pesa-public.js',
			array( 'jquery' ),
			$this->version,
			true
		);

		// Pass data needed by the polling script.
		global $wp;
		$order_id = absint( $wp->query_vars['order-received'] ?? 0 );
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		wp_localize_script(
			$this->plugin_name . '-polling',
			'wcMpesa',
			array(
				'statusEndpoint' => rest_url( 'wc-mpesa/v1/status/' . $order_id ),
				'orderKey'       => $order ? $order->get_order_key() : '',
				'orderId'        => $order_id,
				'pollInterval'   => 3000,
				'maxAttempts'    => 20,
				'i18n'           => array(
					'pending'    => __( 'Waiting for M-Pesa payment confirmation…', 'm-pesa-payment-gateway' ),
					'success'    => __( 'Payment confirmed! Redirecting…', 'm-pesa-payment-gateway' ),
					'failed'     => __( 'Payment was not completed. Please try again.', 'm-pesa-payment-gateway' ),
					'timeout'    => __( 'We could not confirm your payment yet. Please check your M-Pesa messages.', 'm-pesa-payment-gateway' ),
				),
			)
		);
	}

	/**
	 * Register the REST route used for client-side order status polling.
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'wc-mpesa/v1',
			'/status/(?P<order_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_order_status' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'order_id' => array(
						'validate_callback' => function ( $v ) { return is_numeric( $v ) && (int) $v > 0; },
						'sanitize_callback' => 'absint',
					),
					'key'      => array(
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
				),
			)
		);
	}

	/**
	 * Return the current payment status of an order as JSON.
	 *
	 * The caller must supply ?key=<order_key> to prove order ownership.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_order_status( \WP_REST_Request $request ): \WP_REST_Response {
		$order_id  = (int) $request->get_param( 'order_id' );
		$order_key = (string) $request->get_param( 'key' );
		$order     = wc_get_order( $order_id );

		if ( ! $order ) {
			return new \WP_REST_Response( array( 'status' => 'not_found' ), 404 );
		}

		// Verify the caller knows the order key — prevents order status enumeration.
		if ( empty( $order_key ) || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			return new \WP_REST_Response( array( 'status' => 'forbidden' ), 403 );
		}

		$status = $order->get_status();

		if ( in_array( $status, array( 'processing', 'completed' ), true ) ) {
			$polling_status = 'success';
		} elseif ( 'failed' === $status ) {
			$polling_status = 'failed';
		} else {
			$polling_status = 'pending';
		}

		return new \WP_REST_Response(
			array(
				'status'       => $polling_status,
				'order_status' => $status,
				'redirect'     => 'success' === $polling_status ? $order->get_checkout_order_received_url() : null,
			),
			200
		);
	}
}
