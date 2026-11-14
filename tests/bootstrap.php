<?php
/**
 * PHPUnit bootstrap — sets up Brain Monkey and stubs for WP/WC globals.
 *
 * Functions are NOT defined here — Brain Monkey creates them lazily via
 * when()/expect() inside tests, which allows Patchwork to intercept them.
 *
 * @package WC_MPesa\Tests
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// ── WP_Error class stub ───────────────────────────────────────────────────────
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private $data;

		public function __construct( string $code = '', string $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string   { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data( string $code = '' ) { return $this->data; }
	}
}

// ── WP REST stubs ─────────────────────────────────────────────────────────────
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array  $params  = array();
		private array  $headers = array();
		private ?array $json    = null;

		public function get_param( string $key ) { return $this->params[ $key ] ?? null; }
		public function set_param( string $key, $value ): void { $this->params[ $key ] = $value; }
		public function get_json_params(): ?array { return $this->json; }
		public function set_json_params( ?array $json ): void { $this->json = $json; }
		public function get_header( string $key ): ?string { return $this->headers[ strtolower( $key ) ] ?? null; }
		public function set_header( string $key, string $value ): void { $this->headers[ strtolower( $key ) ] = $value; }
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public array $data;
		public int   $status;
		public function __construct( array $data = array(), int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
	}
}
if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		const CREATABLE = 'POST';
		const READABLE  = 'GET';
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public int $ID;
		public string $user_email = 'user@example.com';
		public string $display_name = 'Test User';
		public string $user_pass = '';

		public function __construct( int $id = 1 ) {
			$this->ID = $id;
		}
	}
}

// ── WC_Order stub ─────────────────────────────────────────────────────────────
if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		private int    $id;
		private string $status    = 'pending';
		private array  $meta      = array();
		private string $order_key = 'wc_order_test';
		private float  $total     = 100.0;

		public function __construct( int $id = 1 ) { $this->id = $id; }

		public function get_id(): int           { return $this->id; }
		public function get_status(): string    { return $this->status; }
		public function get_total(): string     { return (string) $this->total; }
		public function set_total( float $t ): void { $this->total = $t; }
		public function get_order_key(): string { return $this->order_key; }
		public function set_order_key( string $k ): void { $this->order_key = $k; }
		public function get_meta( string $key, bool $single = true ) { return $this->meta[ $key ] ?? ''; }
		public function update_meta_data( string $key, $value ): void { $this->meta[ $key ] = $value; }
		public function update_status( string $status, string $note = '' ): void { $this->status = $status; }
		public function payment_complete( string $txn_id = '' ): void { $this->status = 'processing'; }
		public function add_order_note( string $note ): int { return 1; }
		public function save(): int { return $this->id; }
		public function get_checkout_order_received_url(): string {
			return 'https://example.com/checkout/order-received/' . $this->id . '/?key=' . $this->order_key;
		}
	}
}

// ── Plugin constants ──────────────────────────────────────────────────────────
defined( 'ABSPATH' )             || define( 'ABSPATH',             dirname( __DIR__ ) . '/' );
defined( 'WC_MPESA_PLUGIN_DIR' ) || define( 'WC_MPESA_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
defined( 'WC_MPESA_PLUGIN_URL' ) || define( 'WC_MPESA_PLUGIN_URL', 'https://example.com/wp-content/plugins/wc-m-pesa/' );
defined( 'WC_MPESA_VERSION' )    || define( 'WC_MPESA_VERSION',    '1.0.0' );

// ── Load plugin source files ──────────────────────────────────────────────────
require_once WC_MPESA_PLUGIN_DIR . 'includes/api/class-wc-m-pesa-request.php';
require_once WC_MPESA_PLUGIN_DIR . 'includes/api/class-wc-m-pesa-auth.php';
require_once WC_MPESA_PLUGIN_DIR . 'includes/api/class-wc-m-pesa-api.php';
require_once WC_MPESA_PLUGIN_DIR . 'includes/api/class-wc-m-pesa-rest-api.php';
require_once WC_MPESA_PLUGIN_DIR . 'includes/gateway/class-wc-m-pesa-stk-push.php';
require_once WC_MPESA_PLUGIN_DIR . 'includes/gateway/class-wc-m-pesa-callback.php';
