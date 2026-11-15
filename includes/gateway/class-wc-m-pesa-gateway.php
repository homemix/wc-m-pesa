<?php
/**
 * M-Pesa Payment Gateway.
 *
 * Extends WC_Payment_Gateway to add M-Pesa STK Push as a checkout payment option.
 *
 * @package WC_MPesa\Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * M-Pesa STK Push WooCommerce payment gateway.
 */
class WC_MPesa_Gateway extends \WC_Payment_Gateway {

	/** @var WC_MPesa_STK_Push|null Lazy-initialised STK push handler. */
	private ?WC_MPesa_STK_Push $stk_push_handler = null;

	/**
	 * Constructor — sets up gateway properties and loads settings.
	 */
	public function __construct() {
		$icon_file = WC_MPESA_PLUGIN_DIR . 'public/images/mpesa-logo.svg';

		$this->id                 = 'wc_mpesa';
		$this->icon               = apply_filters(
			'wc_mpesa_icon',
			file_exists( $icon_file ) ? WC_MPESA_PLUGIN_URL . 'public/images/mpesa-logo.svg' : ''
		);
		$this->has_fields         = true;
		$this->method_title       = __( 'M-Pesa (STK Push)', 'm-pesa-payment-gateway' );
		$this->method_description = __( 'Accept payments via Safaricom M-Pesa Lipa Na M-Pesa Online (STK Push). Customers receive a USSD prompt on their phone to complete payment.', 'm-pesa-payment-gateway' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		// Map settings to class properties used by WooCommerce.
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		// Save admin settings.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	// -------------------------------------------------------------------------
	// Availability
	// -------------------------------------------------------------------------

	/**
	 * Check whether the gateway is available for checkout.
	 *
	 * Hides the gateway if:
	 *  - Required credentials are blank.
	 *  - The site is in production mode but not served over HTTPS
	 *    (Daraja rejects non-HTTPS callback URLs).
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}

		$required = array( 'consumer_key', 'consumer_secret', 'shortcode', 'passkey' );
		foreach ( $required as $key ) {
			if ( empty( $this->get_option( $key ) ) ) {
				return false;
			}
		}

		if ( 'production' === $this->get_option( 'environment', 'sandbox' ) && ! is_ssl() ) {
			return false;
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	/**
	 * Define the gateway's settings fields displayed in WooCommerce > Settings > Payments.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'                  => array(
				'title'   => __( 'Enable / Disable', 'm-pesa-payment-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable M-Pesa Payment Gateway', 'm-pesa-payment-gateway' ),
				'default' => 'no',
			),
			'title'                    => array(
				'title'       => __( 'Title', 'm-pesa-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to the customer during checkout.', 'm-pesa-payment-gateway' ),
				'default'     => __( 'M-Pesa', 'm-pesa-payment-gateway' ),
				'desc_tip'    => true,
			),
			'description'              => array(
				'title'       => __( 'Description', 'm-pesa-payment-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown to the customer during checkout.', 'm-pesa-payment-gateway' ),
				'default'     => __( 'Pay via M-Pesa. You will receive an STK Push prompt on your phone.', 'm-pesa-payment-gateway' ),
				'desc_tip'    => true,
			),
			'environment'              => array(
				'title'       => __( 'Environment', 'm-pesa-payment-gateway' ),
				'type'        => 'select',
				'description' => __( 'Use Sandbox for testing; switch to Production before going live.', 'm-pesa-payment-gateway' ),
				'default'     => 'sandbox',
				'desc_tip'    => true,
				'options'     => array(
					'sandbox'    => __( 'Sandbox (Testing)', 'm-pesa-payment-gateway' ),
					'production' => __( 'Production (Live)', 'm-pesa-payment-gateway' ),
				),
			),
			'consumer_key'             => array(
				'title'       => __( 'Consumer Key', 'm-pesa-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Your Daraja app Consumer Key from developer.safaricom.co.ke.', 'm-pesa-payment-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'consumer_secret'          => array(
				'title'       => __( 'Consumer Secret', 'm-pesa-payment-gateway' ),
				'type'        => 'password',
				'description' => __( 'Your Daraja app Consumer Secret from developer.safaricom.co.ke.', 'm-pesa-payment-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'shortcode'                => array(
				'title'       => __( 'Business Shortcode', 'm-pesa-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Your M-Pesa Paybill or Till number.', 'm-pesa-payment-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'passkey'                  => array(
				'title'       => __( 'Lipa Na M-Pesa Passkey', 'm-pesa-payment-gateway' ),
				'type'        => 'password',
				'description' => __( 'Lipa Na M-Pesa Online Passkey provided by Safaricom.', 'm-pesa-payment-gateway' ),
				'default'     => '',
				'desc_tip'    => true,
			),
			'transaction_type'         => array(
				'title'       => __( 'Transaction Type', 'm-pesa-payment-gateway' ),
				'type'        => 'select',
				'description' => __( 'Use CustomerPayBillOnline for Paybill, CustomerBuyGoodsOnline for Till numbers.', 'm-pesa-payment-gateway' ),
				'default'     => 'CustomerPayBillOnline',
				'desc_tip'    => true,
				'options'     => array(
					'CustomerPayBillOnline'   => __( 'CustomerPayBillOnline (Paybill)', 'm-pesa-payment-gateway' ),
					'CustomerBuyGoodsOnline'  => __( 'CustomerBuyGoodsOnline (Till)', 'm-pesa-payment-gateway' ),
				),
			),
			'account_reference'        => array(
				'title'       => __( 'Account Reference', 'm-pesa-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Reference shown on the customer\'s M-Pesa statement (max 12 characters). Leave blank to use the order number.', 'm-pesa-payment-gateway' ),
				'default'     => get_bloginfo( 'name' ),
				'desc_tip'    => true,
			),
			'transaction_description'  => array(
				'title'       => __( 'Transaction Description', 'm-pesa-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Short description sent to Daraja (max 13 characters).', 'm-pesa-payment-gateway' ),
				'default'     => 'WooCommerce',
				'desc_tip'    => true,
			),
		);
	}

	// -------------------------------------------------------------------------
	// Checkout rendering
	// -------------------------------------------------------------------------

	/**
	 * Render the payment fields (phone number input) on the checkout page.
	 */
	public function payment_fields(): void {
		if ( $this->description ) {
			echo '<p>' . wp_kses_post( $this->description ) . '</p>';
		}

		wc_get_template(
			'checkout/mpesa-payment-form.php',
			array(),
			'wc-m-pesa/',
			WC_MPESA_PLUGIN_DIR . 'templates/'
		);
	}

	/**
	 * Validate the phone number field submitted at checkout.
	 *
	 * @return bool
	 */
	public function validate_fields(): bool {
		$phone = isset( $_POST['mpesa_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['mpesa_phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( empty( $phone ) ) {
			wc_add_notice( __( 'Please enter your M-Pesa phone number.', 'm-pesa-payment-gateway' ), 'error' );
			return false;
		}

		$formatted = WC_MPesa_STK_Push::format_phone( $phone );
		if ( null === $formatted ) {
			wc_add_notice( __( 'Please enter a valid Safaricom phone number (e.g. 0712345678 or 254712345678).', 'm-pesa-payment-gateway' ), 'error' );
			return false;
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Payment processing
	// -------------------------------------------------------------------------

	/**
	 * Process the payment for a given order.
	 *
	 * Called by WooCommerce after checkout form submission.
	 *
	 * @param int $order_id
	 * @return array<string, mixed>|null  WC redirect result array, or null on failure.
	 */
	public function process_payment( $order_id ): ?array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'm-pesa-payment-gateway' ), 'error' );
			return array( 'result' => 'fail' );
		}

		$raw_phone = isset( $_POST['mpesa_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['mpesa_phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$phone     = WC_MPesa_STK_Push::format_phone( $raw_phone );

		if ( null === $phone ) {
			wc_add_notice( __( 'Invalid M-Pesa phone number.', 'm-pesa-payment-gateway' ), 'error' );
			return array( 'result' => 'fail' );
		}

		// Guard against zero-value orders (free products / full-coupon orders).
		$amount = (int) ceil( (float) $order->get_total() );
		if ( $amount < 1 ) {
			wc_add_notice( __( 'M-Pesa cannot process orders with a zero total. Please use another payment method.', 'm-pesa-payment-gateway' ), 'error' );
			return array( 'result' => 'fail' );
		}

		$response = $this->get_stk_push_handler()->initiate( $order, $phone, $this->get_gateway_settings() );

		if ( is_wp_error( $response ) ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: error message */
					__( 'M-Pesa payment initiation failed: %s', 'm-pesa-payment-gateway' ),
					$response->get_error_message()
				),
				'error'
			);
			$order->update_status( 'failed', '[M-Pesa] STK Push initiation failed: ' . $response->get_error_message() );
			return array( 'result' => 'fail' );
		}

		$order->update_status(
			'on-hold',
			__( 'Awaiting M-Pesa STK Push payment confirmation.', 'm-pesa-payment-gateway' )
		);

		// Empty the cart only after a successful STK Push initiation.
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Collect the gateway-specific settings into an array for the STK push handler.
	 *
	 * @return array<string, string>
	 */
	private function get_gateway_settings(): array {
		return array(
			'shortcode'               => $this->get_option( 'shortcode' ),
			'passkey'                 => $this->get_option( 'passkey' ),
			'transaction_type'        => $this->get_option( 'transaction_type', 'CustomerPayBillOnline' ),
			'account_reference'       => $this->get_option( 'account_reference' ),
			'transaction_description' => $this->get_option( 'transaction_description', 'WooCommerce' ),
		);
	}

	/**
	 * Build (or return) the STK Push handler, lazily constructing the API stack.
	 *
	 * @return WC_MPesa_STK_Push
	 */
	private function get_stk_push_handler(): WC_MPesa_STK_Push {
		if ( null === $this->stk_push_handler ) {
			$request = new WC_MPesa_Request();
			$auth    = new WC_MPesa_Auth(
				$request,
				$this->get_option( 'consumer_key' ),
				$this->get_option( 'consumer_secret' ),
				$this->get_option( 'environment', 'sandbox' )
			);
			$api     = new WC_MPesa_API( $auth, $request );

			$this->stk_push_handler = new WC_MPesa_STK_Push( $api );
		}

		return $this->stk_push_handler;
	}
}
