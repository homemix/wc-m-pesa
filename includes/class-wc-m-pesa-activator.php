<?php
/**
 * Fired during plugin activation.
 *
 * @package WC_MPesa
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles all logic run on plugin activation.
 */
class WC_MPesa_Activator {

	/**
	 * Run activation tasks:
	 *  - Generate a callback secret token if one does not exist yet.
	 *  - Flush rewrite rules so the REST callback endpoint is available.
	 */
	public static function activate(): void {
		// Generate a one-time secret used to verify incoming Daraja callbacks.
		if ( ! get_option( 'wc_mpesa_callback_secret' ) ) {
			update_option( 'wc_mpesa_callback_secret', wp_generate_password( 32, false ) );
		}

		flush_rewrite_rules();
	}
}
