<?php
/**
 * Fired during plugin deactivation.
 *
 * @package WC_MPesa
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles all logic run on plugin deactivation.
 */
class WC_MPesa_Deactivator {

	/**
	 * Flush rewrite rules so the REST endpoint is removed cleanly.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
