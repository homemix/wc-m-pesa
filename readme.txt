=== WooCommerce M-Pesa Payment Gateway ===
Contributors:      kennedywambua
Tags:              woocommerce, mpesa, payment, safaricom, kenya, daraja, stk push
Requires at least: 5.8
Tested up to:      6.5
Requires PHP:      7.4
Stable tag:        1.0.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Accept M-Pesa payments in your WooCommerce store via Safaricom Daraja STK Push (Lipa Na M-Pesa Online).

== Description ==

**WooCommerce M-Pesa Payment Gateway** integrates Safaricom's Daraja API into WooCommerce so Kenyan merchants can accept M-Pesa payments directly from their online store.

= How it works =
1. Customer selects "M-Pesa" at checkout and enters their Safaricom phone number.
2. The plugin initiates an STK Push (Lipa Na M-Pesa Online) request via the Daraja API.
3. The customer receives a USSD prompt on their phone and enters their M-Pesa PIN.
4. Safaricom sends a callback to your site confirming payment.
5. The WooCommerce order is automatically updated to "Processing".

= Features =
* STK Push (Lipa Na M-Pesa Online) — customer-initiated USSD prompt
* Sandbox and Production environment support
* OAuth2 token caching via WordPress transients (no redundant token requests)
* Secure callback endpoint with secret-token verification
* Real-time order status polling on the thank-you page
* Customer-facing REST APIs for mobile payment flows, STK initiation, order payment status, and Daraja status query
* Supports Paybill (`CustomerPayBillOnline`) and Till (`CustomerBuyGoodsOnline`)
* Template override support — copy checkout template to your theme
* WooCommerce HPOS (High-Performance Order Storage) compatible

= REST API =
Base namespace:
`/wp-json/wc-mpesa/v1`

Main endpoints:
* `POST /payments/initiate`
* `GET /payments/{order_id}/status`
* `GET /payments/{order_id}/query`
* `GET /mobile/endpoints`

Detailed API docs are included in:
`wp-content/plugins/wc-m-pesa/docs/rest-api.md`

= Requirements =
* WordPress 5.8+
* WooCommerce 6.0+
* PHP 7.4+
* A Safaricom Daraja developer account (https://developer.safaricom.co.ke)
* An SSL certificate (HTTPS) on your store — required by Safaricom for callbacks
* HTTPS for authenticated REST API requests

== Installation ==

1. Upload the `wc-m-pesa` folder to `/wp-content/plugins/` or install via the WordPress plugin installer.
2. Activate the plugin through **Plugins > Installed Plugins**.
3. Go to **WooCommerce > Settings > Payments** and click **M-Pesa (STK Push)**.
4. Enter your Daraja API credentials (Consumer Key, Consumer Secret, Shortcode, Passkey).
5. Set the environment to **Sandbox** for testing, then switch to **Production** when ready to go live.

== Frequently Asked Questions ==

= Where do I get my Daraja API credentials? =
Register at https://developer.safaricom.co.ke, create an app, and copy the Consumer Key and Consumer Secret. You will also need a Lipa Na M-Pesa Online Passkey from Safaricom.

= Do I need an SSL certificate? =
Yes. Safaricom's Daraja API only accepts callback URLs on HTTPS endpoints.

= What phone number formats are accepted? =
The plugin accepts `07XXXXXXXX`, `7XXXXXXXX`, and `254XXXXXXXXX` formats and normalises them automatically.

= Can I test without real money? =
Yes. Set the environment to **Sandbox** and use Safaricom's test credentials from the Daraja developer portal.

= How do I override the checkout phone-number template? =
Copy `wp-content/plugins/wc-m-pesa/templates/checkout/mpesa-payment-form.php` to
`wp-content/themes/your-theme/wc-m-pesa/checkout/mpesa-payment-form.php` and edit it.

== Screenshots ==

1. M-Pesa payment option shown at WooCommerce checkout.
2. Plugin settings page under WooCommerce > Settings > Payments.

== Changelog ==

= 1.0.0 =
* Initial release.
* STK Push (Lipa Na M-Pesa Online) integration.
* Sandbox and Production environment support.
* Real-time order status polling on thank-you page.
* Secure Daraja callback endpoint.

== Upgrade Notice ==

= 1.0.0 =
Initial release — no upgrade steps required.
