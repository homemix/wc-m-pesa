# WooCommerce M-Pesa Payment Gateway

Accept M-Pesa payments in your WooCommerce store using Safaricom Daraja STK Push (Lipa Na M-Pesa Online).

## Features

- STK Push prompts customers to approve payment on their phones.
- Sandbox and production environments.
- Supports Paybill (`CustomerPayBillOnline`) and Till (`CustomerBuyGoodsOnline`).
- Secure callback endpoint with secret-token verification.
- Automatic order status updates and payment-status polling on the order confirmation page.
- Customer-facing REST API for initiating payments and checking payment status.
- WooCommerce High-Performance Order Storage (HPOS) compatibility.
- Checkout phone-number template overrides.

## Requirements

- WordPress 5.8 or later.
- WooCommerce 6.0 or later.
- PHP 7.4 or later.
- A Safaricom Daraja developer account and API credentials.
- HTTPS enabled on your store for production callbacks.

## Installation

1. Zip the `wc-m-pesa` folder so the archive contains `wc-m-pesa/` as its top-level folder. In WordPress, go to **Plugins > Add New Plugin > Upload Plugin**, select the ZIP file, and click **Install Now**. Alternatively, upload the extracted `wc-m-pesa` folder to `/wp-content/plugins/`.
2. Activate **WooCommerce M-Pesa Payment Gateway** under **Plugins > Installed Plugins**.
3. Go to **WooCommerce > Settings > Payments** and open **M-Pesa (STK Push)**.
4. Enter your Consumer Key, Consumer Secret, Shortcode, and Lipa Na M-Pesa Passkey.
5. Select **Sandbox** to test. Switch to **Production** when ready to accept live payments.

## Configuration

Create an application in the [Safaricom Daraja developer portal](https://developer.safaricom.co.ke) to obtain your Consumer Key and Consumer Secret. Configure your Paybill or Till shortcode and the corresponding Lipa Na M-Pesa Passkey in the gateway settings.

Production callback URLs and authenticated REST API requests must use HTTPS. Keep your Daraja credentials private and only configure them in your site's WooCommerce payment settings.

## REST API

The plugin registers endpoints under `/wp-json/wc-mpesa/v1`:

- `POST /payments/initiate`
- `GET /payments/{order_id}/status`
- `GET /payments/{order_id}/query`
- `GET /mobile/endpoints`

See the [REST API documentation](docs/rest-api.md) for request formats, parameters, and responses.

## Customizing the checkout phone field

Copy `templates/checkout/mpesa-payment-form.php` into your theme at:

```text
your-theme/wc-m-pesa/checkout/mpesa-payment-form.php
```

Then edit the copied template. Keep customizations in the theme rather than modifying the plugin files, so they are retained when the plugin is updated.

## Frequently asked questions

### What phone number formats are accepted?

The plugin accepts `07XXXXXXXX`, `7XXXXXXXX`, and `254XXXXXXXXX` formats and normalizes them automatically.

### Can I test without real money?

Yes. Select **Sandbox** and use the test credentials provided through the Daraja developer portal.

### Does production require HTTPS?

Yes. Safaricom requires HTTPS callback URLs.

## Screenshots

1. M-Pesa payment option at WooCommerce checkout.
2. Gateway settings under **WooCommerce > Settings > Payments**.

## License

GPL-2.0-or-later. See the [GNU General Public License](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html).
