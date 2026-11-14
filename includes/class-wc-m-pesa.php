<?php
/**
 * Core plugin class — wires together all components via the Loader.
 *
 * @package WC_MPesa
 */

defined( 'ABSPATH' ) || exit;

/**
 * Core plugin orchestrator.
 *
 * Defines the plugin name and version, instantiates the loader, and registers
 * all admin/public/gateway hooks.
 */
class WC_MPesa {

	/** @var WC_MPesa_Loader Hook loader instance. */
	private WC_MPesa_Loader $loader;

	/** @var string The plugin identifier. */
	private string $plugin_name;

	/** @var string Current plugin version. */
	private string $version;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_name Plugin slug.
	 * @param string $version     Plugin version string.
	 */
	public function __construct( string $plugin_name, string $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->loader      = new WC_MPesa_Loader();

		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->define_gateway_hooks();
	}

	/**
	 * Set the locale for internationalisation.
	 */
	private function set_locale(): void {
		$plugin_i18n = new WC_MPesa_i18n();
		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );
	}

	/**
	 * Register all admin-area hooks.
	 */
	private function define_admin_hooks(): void {
		$plugin_admin = new WC_MPesa_Admin( $this->plugin_name, $this->version );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
		$this->loader->add_action( 'admin_notices',         $plugin_admin, 'maybe_show_config_notice' );
		$this->loader->add_filter( 'plugin_action_links_' . WC_MPESA_BASENAME, $plugin_admin, 'add_action_links' );
	}

	/**
	 * Register all public-facing hooks.
	 */
	private function define_public_hooks(): void {
		$plugin_public = new WC_MPesa_Public( $this->plugin_name, $this->version );

		$this->loader->add_action( 'wp_enqueue_scripts',    $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts',    $plugin_public, 'enqueue_scripts' );
		$this->loader->add_action( 'rest_api_init',         $plugin_public, 'register_rest_routes' );
	}

	/**
	 * Register WooCommerce payment gateway hooks.
	 */
	private function define_gateway_hooks(): void {
		$this->loader->add_filter( 'woocommerce_payment_gateways', $this, 'add_payment_gateway', 10, 1 );

		// Register the Daraja callback REST endpoint.
		$callback_handler = new WC_MPesa_Callback();
		$this->loader->add_action( 'rest_api_init', $callback_handler, 'register_rest_route' );

		// Register customer-facing M-Pesa REST endpoints.
		$rest_api = new WC_MPesa_REST_API();
		$this->loader->add_action( 'rest_api_init', $rest_api, 'register_routes' );
	}

	/**
	 * Register the M-Pesa gateway class with WooCommerce.
	 *
	 * @param array<int, string> $gateways Existing gateway class names.
	 * @return array<int, string>
	 */
	public function add_payment_gateway( array $gateways ): array {
		$gateways[] = 'WC_MPesa_Gateway';
		return $gateways;
	}

	/**
	 * Run the loader to register all hooks with WordPress.
	 */
	public function run(): void {
		$this->loader->run();
	}

	/** @return string */
	public function get_plugin_name(): string {
		return $this->plugin_name;
	}

	/** @return string */
	public function get_version(): string {
		return $this->version;
	}
}
