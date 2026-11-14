<?php
/**
 * WooCommerce M-Pesa Payment Gateway
 *
 * Integrates Safaricom Daraja API (STK Push) as a WooCommerce payment gateway.
 *
 * @package           WC_MPesa
 * @author            Kennedy Wambua
 * @copyright         2024 Kennedy Wambua
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       WooCommerce M-Pesa Payment Gateway
 * Plugin URI:        https://github.com/homemix/wc-m-pesa
 * Description:       Accept M-Pesa payments in your WooCommerce store via Safaricom Daraja STK Push (Lipa Na M-Pesa Online).
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Kennedy Wambua
 * Author URI:        https://github.com/homemix
 * Text Domain:       wc-m-pesa
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 6.0
 * WC tested up to:   8.9
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'WC_MPESA_VERSION',   '1.0.0' );
define( 'WC_MPESA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_MPESA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_MPESA_BASENAME',   plugin_basename( __FILE__ ) );

/*
 * Activator and deactivator must be available before plugins_loaded fires,
 * because WordPress calls activation/deactivation hooks during the same
 * request that triggers them (before plugins_loaded).
 */
require_once WC_MPESA_PLUGIN_DIR . 'includes/class-wc-m-pesa-activator.php';
require_once WC_MPESA_PLUGIN_DIR . 'includes/class-wc-m-pesa-deactivator.php';

/**
 * Check that WooCommerce is active before doing anything.
 *
 * @return bool
 */
function wc_mpesa_woocommerce_is_active(): bool {
	return class_exists( 'WooCommerce' );
}

/**
 * Show an admin notice when WooCommerce is missing.
 */
function wc_mpesa_missing_wc_notice(): void {
	echo '<div class="error"><p>'
		. sprintf(
			/* translators: %s: WooCommerce plugin URL */
			esc_html__( 'WooCommerce M-Pesa requires WooCommerce to be installed and active. You can download %s here.', 'wc-m-pesa' ),
			'<a href="https://wordpress.org/plugins/woocommerce/" target="_blank">WooCommerce</a>'
		)
		. '</p></div>';
}

/**
 * Main plugin initialisation.
 */
function run_wc_mpesa(): void {
	if ( ! wc_mpesa_woocommerce_is_active() ) {
		add_action( 'admin_notices', 'wc_mpesa_missing_wc_notice' );
		return;
	}

	require_once WC_MPESA_PLUGIN_DIR . 'includes/class-wc-m-pesa-loader.php';
	require_once WC_MPESA_PLUGIN_DIR . 'includes/class-wc-m-pesa-i18n.php';
	require_once WC_MPESA_PLUGIN_DIR . 'includes/api/class-wc-m-pesa-request.php';
	require_once WC_MPESA_PLUGIN_DIR . 'includes/api/class-wc-m-pesa-auth.php';
	require_once WC_MPESA_PLUGIN_DIR . 'includes/api/class-wc-m-pesa-api.php';
	require_once WC_MPESA_PLUGIN_DIR . 'includes/api/class-wc-m-pesa-rest-api.php';
	require_once WC_MPESA_PLUGIN_DIR . 'includes/gateway/class-wc-m-pesa-stk-push.php';
	require_once WC_MPESA_PLUGIN_DIR . 'includes/gateway/class-wc-m-pesa-callback.php';
	require_once WC_MPESA_PLUGIN_DIR . 'includes/gateway/class-wc-m-pesa-gateway.php';
	require_once WC_MPESA_PLUGIN_DIR . 'includes/class-wc-m-pesa.php';
	require_once WC_MPESA_PLUGIN_DIR . 'admin/class-wc-m-pesa-admin.php';
	require_once WC_MPESA_PLUGIN_DIR . 'public/class-wc-m-pesa-public.php';

	$plugin = new WC_MPesa( 'wc-m-pesa', WC_MPESA_VERSION );
	$plugin->run();
}

/**
 * Declare WooCommerce feature compatibility (HPOS).
 * Must run on before_woocommerce_init.
 */
function wc_mpesa_declare_compatibility(): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
}

// Activation / deactivation hooks must be registered before plugins_loaded.
register_activation_hook( __FILE__,   array( 'WC_MPesa_Activator',   'activate'   ) );
register_deactivation_hook( __FILE__, array( 'WC_MPesa_Deactivator', 'deactivate' ) );

add_action( 'before_woocommerce_init', 'wc_mpesa_declare_compatibility' );
add_action( 'plugins_loaded', 'run_wc_mpesa' );
