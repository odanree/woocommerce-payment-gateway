# woocommerce-payment-gateway

Custom WooCommerce payment gateway plugin integrating NMI (Network Merchants Inc) as the primary processor and Stripe as a drop-in failover — targeting high-risk merchant categories where processor relationships can be terminated with little notice.

Demonstrates: tokenization, webhook signature verification, idempotency controls, processor failover via config swap, multi-step checkout UX, and PCI DSS v4.0 SAQ-A-EP alignment documentation.

---

## Why NMI + Stripe failover

High-risk merchants (supplements, CBD, adult content, firearms accessories, continuity subscriptions) are routinely dropped by payment processors. The failover design here treats processor portability as a first-class requirement:

```
Normal flow:   Order → NMI API → Capture → WooCommerce order status
Failover flow: Config change (ACTIVE_GATEWAY=stripe) → No code rewrite → Order → Stripe API
```

The same `WC_Gateway_Failover_Manager` class routes to either processor based on a single WordPress option — verified in tests to swap without any code change.

---

## Architecture

```
Customer checkout
        │
        ▼
WC_Gateway_Multistep_Checkout
(3-step: Shipping → Payment → Review)
        │
        ▼
WC_Gateway_Failover_Manager
        │
        ├── active_gateway = 'nmi'  ──► WC_Gateway_NMI
        │                                   └── NMI Collect.js tokenization
        │                                   └── POST /api/transact.php
        │
        └── active_gateway = 'stripe' ──► WC_Gateway_Stripe_HighRisk
                                              └── Stripe.js Elements
                                              └── PaymentIntent API
        │
        ▼
WC_Idempotency
(deduplicates retry/double-submit via order hash)
        │
        ▼
WC_Tokenization_Manager
(stores processor tokens in wp_woocommerce_payment_tokens)
        │
        ▼
WC_Webhook_Handler
(NMI and Stripe webhooks → order status updates)
```

---

## Stack

| Layer | Choice |
|-------|--------|
| Platform | WordPress 6.4+ / WooCommerce 8.5+ |
| Language | PHP 8.1+ |
| Primary processor | NMI (Network Merchants Inc) Collect.js + REST API |
| Failover processor | Stripe PaymentIntents API |
| Testing | PHPUnit 10 + WooCommerce Unit Test Framework |
| Code style | PHP_CodeSniffer (WordPress-Extra + WooCommerce) |
| CI | GitHub Actions (lint + test) |

---

## Local development

### Prerequisites
- PHP 8.1+, Composer 2
- WordPress + WooCommerce installed (or use the provided test bootstrap)
- NMI sandbox account + Stripe test account

```bash
git clone https://github.com/odanree/woocommerce-payment-gateway.git
cd woocommerce-payment-gateway
composer install
cp .env.example .env
# Fill in NMI_SECURITY_KEY, STRIPE_SECRET_KEY, etc.
```

### Run tests
```bash
composer test
# or directly:
./vendor/bin/phpunit --testdox
```

### Install as a WordPress plugin
```bash
# Symlink or copy to wp-content/plugins/
ln -s /path/to/woocommerce-payment-gateway /path/to/wp-content/plugins/woocommerce-payment-gateway

# Or create a zip and upload via WP Admin
zip -r woocommerce-payment-gateway.zip . --exclude '.git/*' 'vendor/*' 'tests/*'
```

---

## Configuration

After plugin activation, configure under **WooCommerce → Settings → Payments**:

| Setting | Description |
|---------|-------------|
| Active Gateway | `nmi` or `stripe` — switch to failover without any code change |
| NMI Security Key | From NMI merchant portal (sandbox: use test key) |
| NMI Collect.js Key | Tokenization public key |
| Stripe Secret Key | `sk_test_...` for sandbox |
| Stripe Publishable Key | `pk_test_...` |
| NMI Webhook URL | `https://your-store.com/wc-api/nmi_webhook` |
| Stripe Webhook Secret | From Stripe dashboard (used for signature verification) |
| Enable Test Mode | Routes to processor sandbox environments |

---

## Processor failover

Switching processors requires one config change — no code rewrite:

```php
// In wp-admin: WooCommerce → Settings → Payments → Active Gateway
// Change 'nmi' to 'stripe'

// Or programmatically:
update_option('wc_gateway_active', 'stripe');
```

The `WC_Gateway_Failover_Manager` handles the routing. See [`docs/adr/002-stripe-failover-config-swap.md`](docs/adr/002-stripe-failover-config-swap.md) for the decision rationale.

---

## PCI DSS v4.0 SAQ-A-EP alignment

This plugin is scoped to **SAQ-A-EP** (not full PCI DSS QSA audit) because:
- Cardholder data flows through the browser via tokenization (Collect.js / Stripe.js) — the server never sees raw card numbers
- The server receives and processes tokens only
- Payment page is hosted on the merchant's domain (EP = "e-commerce, partially outsourced")

Key controls implemented:
- Tokenization via Collect.js (NMI) and Stripe Elements — no PAN on server
- Webhook signature verification (HMAC-SHA256 for NMI, Stripe-Signature header for Stripe)
- Idempotency keys prevent double-charges on network retries
- TLS enforced at Nginx layer (see [vps-woocommerce-stack](https://github.com/odanree/vps-woocommerce-stack))

See [`docs/pci-dss/`](docs/pci-dss/) for full SAQ-A-EP alignment checklist, data flow diagram, and cardholder data environment scoping.

---

## CI

| Check | Trigger |
|-------|---------|
| PHP_CodeSniffer (WP-Extra) | push / PR |
| PHPUnit tests | push / PR |
| Secret scan (gitleaks) | push / PR |
