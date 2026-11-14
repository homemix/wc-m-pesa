<?php
/**
 * Loads the plugin text domain for translations.
 *
 * @package WC_MPesa
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles internationalisation / localisation.
 */
class WC_MPesa_i18n {

	/**
	 * Load the plugin text domain for translation.
	 */
	public function load_plugin_textdomain(): void {
		load_plugin_textdomain(
			'wc-m-pesa',
			false,
			WC_MPESA_PLUGIN_DIR . 'languages/'
		);
	}
}
