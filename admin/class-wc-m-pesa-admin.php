<?php
/**
 * Admin-specific functionality.
 *
 * @package WC_MPesa\Admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles enqueuing of admin assets, adding settings links, and admin notices.
 */
class WC_MPesa_Admin {

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
	 * Register the admin stylesheet.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_styles( string $hook ): void {
		if ( ! $this->is_mpesa_settings_page( $hook ) ) {
			return;
		}

		wp_enqueue_style(
			$this->plugin_name,
			WC_MPESA_PLUGIN_URL . 'admin/css/wc-m-pesa-admin.css',
			array(),
			$this->version
		);
	}

	public function enqueue_scripts( string $hook ): void {
		if ( ! $this->is_mpesa_settings_page( $hook ) ) {
			return;
		}

		wp_enqueue_script(
			$this->plugin_name,
			WC_MPESA_PLUGIN_URL . 'admin/js/wc-m-pesa-admin.js',
			array( 'jquery' ),
			$this->version,
			true
		);
	}

	/**
	 * Show an admin notice if the gateway is enabled but required settings are missing.
	 */
	public function maybe_show_config_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings = get_option( 'woocommerce_wc_mpesa_settings', array() );
		if ( ( $settings['enabled'] ?? 'no' ) !== 'yes' ) {
			return;
		}

		$required = array( 'consumer_key', 'consumer_secret', 'shortcode', 'passkey' );
		foreach ( $required as $key ) {
			if ( empty( $settings[ $key ] ) ) {
				echo '<div class="notice notice-warning"><p>'
					. sprintf(
						/* translators: %s: settings URL */
						esc_html__( 'WooCommerce M-Pesa is enabled but not fully configured. Please complete the %s.', 'm-pesa-payment-gateway' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_mpesa' ) ) . '">'
						. esc_html__( 'gateway settings', 'm-pesa-payment-gateway' )
						. '</a>'
					)
					. '</p></div>';
				return;
			}
		}
	}

	/**
	 * Check whether the current admin page is the M-Pesa gateway settings tab.
	 *
	 * @param string $hook Current admin page hook.
	 * @return bool
	 */
	private function is_mpesa_settings_page( string $hook ): bool {
		return 'woocommerce_page_wc-settings' === $hook
			&& isset( $_GET['tab'], $_GET['section'] ) // phpcs:ignore WordPress.Security.NonceVerification
			&& 'checkout' === sanitize_key( $_GET['tab'] )
			&& 'wc_mpesa' === sanitize_key( $_GET['section'] );
	}

	/**
	 * Add a "Settings" shortcut link on the Plugins list page.
	 *
	 * @param array<int, string> $links Existing plugin action links.
	 * @return array<int, string>
	 */
	public function add_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_mpesa' ) ),
			esc_html__( 'Settings', 'm-pesa-payment-gateway' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}
}
