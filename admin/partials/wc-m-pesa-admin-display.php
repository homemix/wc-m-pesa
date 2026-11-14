<?php
/**
 * Admin display partial for the M-Pesa settings page.
 *
 * This partial is reserved for any custom admin UI rendered outside of the
 * standard WooCommerce Settings API (e.g., a status/debug panel).
 *
 * @package WC_MPesa\Admin
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap wc-mpesa-admin-wrap">
	<h2><?php esc_html_e( 'WooCommerce M-Pesa', 'wc-m-pesa' ); ?></h2>
	<p>
		<?php
		printf(
			/* translators: %s: WooCommerce settings URL */
			esc_html__( 'Configure your M-Pesa gateway settings under %s.', 'wc-m-pesa' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_mpesa' ) ) . '">'
			. esc_html__( 'WooCommerce > Settings > Payments > M-Pesa', 'wc-m-pesa' )
			. '</a>'
		);
		?>
	</p>
</div>
