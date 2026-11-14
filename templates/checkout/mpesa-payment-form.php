<?php
/**
 * Template: M-Pesa phone number field displayed at WooCommerce checkout.
 *
 * Override by copying to: yourtheme/wc-m-pesa/checkout/mpesa-payment-form.php
 *
 * @package WC_MPesa
 */

defined( 'ABSPATH' ) || exit;

$billing_phone = '';
if ( is_a( WC()->checkout(), 'WC_Checkout' ) ) {
	$billing_phone = (string) ( WC()->checkout()->get_value( 'billing_phone' ) ?? '' );
}
?>
<div class="wc-mpesa-phone-field">
	<label for="mpesa_phone">
		<?php esc_html_e( 'M-Pesa Phone Number', 'wc-m-pesa' ); ?>
		<abbr class="required" title="<?php esc_attr_e( 'required', 'wc-m-pesa' ); ?>">*</abbr>
	</label>
	<input
		id="mpesa_phone"
		name="mpesa_phone"
		type="tel"
		placeholder="<?php esc_attr_e( '0712 345 678', 'wc-m-pesa' ); ?>"
		autocomplete="tel"
		required
		maxlength="15"
		pattern="[0-9\s\+\-]{9,15}"
		aria-describedby="mpesa-phone-hint"
		value="<?php echo esc_attr( $billing_phone ); ?>"
	/>
	<small id="mpesa-phone-hint">
		<?php esc_html_e( 'Enter your Safaricom M-Pesa number (e.g. 0712 345 678). Only Safaricom numbers can receive STK Push prompts.', 'wc-m-pesa' ); ?>
	</small>
</div>
