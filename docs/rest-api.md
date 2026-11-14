# WC M-Pesa REST API

Base URL:

```text
https://your-site.com/wp-json/wc-mpesa/v1
```

This plugin exposes the payment endpoints used for testing an already-authenticated user, plus the public polling and Daraja callback endpoints used by the checkout flow.

## Authentication

Protected endpoints use a bearer JWT issued by the WC UAC plugin.

```http
Authorization: Bearer <access_token>
```

Use the bearer token from `POST /wp-json/wc-uac/v1/auth/login` or `POST /wp-json/wc-uac/v1/auth/token/refresh`.

All authenticated requests must use HTTPS. The plugin rejects protected requests over HTTP and advertises HTTPS URLs in the mobile endpoint map. If your site terminates TLS at a reverse proxy, configure WordPress to recognize the original HTTPS connection so `is_ssl()` returns `true`.

## Endpoints

### POST `/payments/initiate` *(protected)*

Initiates an STK Push for an existing WooCommerce order.

Edge case handling:

- Missing Daraja request IDs are treated as a failed initiation.
- The order is only moved to `on-hold` after a valid STK response is received.

Request:

```json
{
  "order_id": 456,
  "phone": "0712345678"
}
```

Response includes the order payload and Daraja initiation fields:

- `CheckoutRequestID`
- `MerchantRequestID`
- `CustomerMessage`
- `ResponseCode`
- `ResponseDescription`

### GET `/payments/{order_id}/status` *(protected)*

Returns mapped order status plus stored M-Pesa metadata:

- `success` for `processing` / `completed`
- `failed` for `failed`
- `pending` otherwise

### GET `/payments/{order_id}/query` *(protected)*

Queries Daraja `stkpushquery` using the stored `CheckoutRequestID`.

### GET `/mobile/endpoints`

Returns the full payment URLs, HTTP methods, and WC UAC auth URLs for mobile clients.

Example response keys:

- `auth.access_token_url`
- `auth.refresh_token_url`
- `endpoints.payment_initiate.url`
- `endpoints.payment_status.url`
- `endpoints.payment_query.url`

### GET `/status/{order_id}?key=<order_key>`

Public polling endpoint used by the thank-you page.

Returns:

```json
{
  "status": "pending",
  "order_status": "on-hold",
  "redirect": null
}
```

### POST `/callback?secret=<callback_secret>`

Daraja callback endpoint for STK Push confirmations.

Edge case handling:

- Unknown checkout IDs are ignored safely.
- A successful callback without `MpesaReceiptNumber` is left `on-hold` for manual review.

Returns:

```json
{
  "ResultCode": 0,
  "ResultDesc": "Accepted"
}
```

## Error format

```json
{
  "success": false,
  "code": "order_not_found",
  "message": "Order not found."
}
```

## Files

- `docs/postman-collection.json` — Postman collection for payment testing.
