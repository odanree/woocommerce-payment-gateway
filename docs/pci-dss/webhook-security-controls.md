# Webhook security controls

Documents how NMI and Stripe webhook endpoints are secured against spoofed, replayed, and tampered requests. Relevant to PCI DSS v4.0 Requirement 6.4.1 (web-facing apps protected against attacks).

---

## Why webhook security matters for payment systems

An unsecured webhook endpoint is an injection point. An attacker who can forge a `payment_intent.succeeded` event can trigger order fulfillment without paying. A tampered `charge.refunded` event can trigger a refund record without a real refund being issued.

Both NMI and Stripe use HMAC-based signatures on every webhook delivery. This plugin verifies those signatures **before processing any event** — if verification fails, the request is rejected with HTTP 401 and no order state is modified.

---

## NMI webhook verification

**Header**: `X-Webhook-Signature`
**Algorithm**: HMAC-SHA512
**Implementation**: [`includes/class-wc-webhook-handler.php` → `verify_nmi_signature()`](../../includes/class-wc-webhook-handler.php)

```php
$expected = hash_hmac('sha512', $raw_body, $secret);
return hash_equals($expected, strtolower($sig));
```

**Key controls:**
- `hash_equals()` — constant-time comparison prevents timing oracle attacks
- Signature computed over the raw POST body before any parsing (ensures whitespace/ordering changes are detected)
- Empty secret → reject (prevents misconfigured deployments from silently accepting all events)
- Empty signature → reject

**NMI webhook secret** is configured in WP Admin → WooCommerce → Payments → NMI Webhook HMAC Secret. Stored in `wp_options` (not in code or env vars).

---

## Stripe webhook verification

**Header**: `Stripe-Signature`
**Algorithm**: HMAC-SHA256 with timestamp replay protection
**Implementation**: [`includes/class-wc-webhook-handler.php` → `verify_stripe_signature()`](../../includes/class-wc-webhook-handler.php)

The `Stripe-Signature` header format:
```
t=1714000000,v1=abc123...,v0=legacy...
```

Verification steps:
1. Extract timestamp (`t=`) and signature (`v1=`)
2. **Replay check**: reject if `|time() - timestamp| > 300s` (5-minute window)
3. Reconstruct signed payload: `"$timestamp.$raw_body"`
4. Compare `HMAC-SHA256(signed_payload, secret)` with `v1` using `hash_equals()`

**Why the timestamp matters**: Without it, a valid signature from a previously captured webhook could be replayed indefinitely to trigger duplicate fulfillments. Stripe's 5-minute window is the industry standard; we match it exactly.

```php
$signed_payload = $timestamp . '.' . $payload;
$expected = hash_hmac('sha256', $signed_payload, $secret);
return hash_equals($expected, $received_sig);
```

**Stripe webhook secret** (`whsec_...`) is stored in WP options — never in source code. Generated per-endpoint in the Stripe Dashboard.

---

## Endpoint registration

Both webhook URLs are registered via WooCommerce's built-in API routing:

| Processor | URL | WC API hook |
|-----------|-----|-------------|
| NMI | `https://your-store.com/wc-api/nmi_webhook` | `woocommerce_api_nmi_webhook` |
| Stripe | `https://your-store.com/wc-api/stripe_webhook` | `woocommerce_api_stripe_webhook` |

The endpoints are public (no WP auth) — security is provided entirely by HMAC verification.

---

## Event handling and idempotency

After signature verification, events are processed idempotently where possible:

- `payment_intent.succeeded` → calls `payment_complete()` — WooCommerce prevents double-completion internally
- `charge.refunded` → sets status `refunded` — idempotent (already-refunded orders stay refunded)
- `charge.dispute.created` → sets `on-hold` and emails admin — if email fails, the status change has already happened

Unknown event types return HTTP 200 immediately without processing. This prevents Stripe/NMI from retrying indefinitely for events we don't handle (which would eventually trigger their retry backoff and alert the operations team unnecessarily).

---

## Test coverage

Signature verification is the most security-critical code in this plugin. Full test coverage in [`tests/test-webhook-handler.php`](../../tests/test-webhook-handler.php):

| Test | Covers |
|------|--------|
| Valid NMI signature | Happy path |
| Invalid NMI signature | Tampered sig |
| Tampered NMI payload | Sig computed over original, received over modified |
| Empty NMI secret | Misconfigured deployment |
| Valid Stripe signature | Happy path |
| Replayed Stripe event | Timestamp > 300s old |
| Tampered Stripe payload | Sig mismatch |
| Missing Stripe `v1` component | Malformed header |
