<?php
/**
 * Uninstall M-Pesa Payment Gateway.
 *
 * @package WC_MPesa
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete all M-Pesa plugin options and transients for a single site.
 */
function _wc_mpesa_delete_site_data(): void {
	delete_option( 'wc_mpesa_callback_secret' );
	delete_option( 'woocommerce_wc_mpesa_settings' );
	delete_transient( 'wc_mpesa_access_token' );

	// Remove all scoped token transients (keyed by env+consumer_key hash).
	global $wpdb;
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wc_mpesa_token_%' OR option_name LIKE '_transient_timeout_wc_mpesa_token_%'"
	);
}

if ( is_multisite() ) {
	$sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $sites as $site_id ) {
		switch_to_blog( $site_id );
		_wc_mpesa_delete_site_data();
		restore_current_blog();
	}
} else {
	_wc_mpesa_delete_site_data();
}
