# Cardholder Data Flow Diagram

Documents how card data flows through the system. Demonstrates that the merchant server is **never** in possession of raw card data (PAN, CVV, expiry date).

---

## Payment flow (NMI Collect.js)

```
Customer Browser                 NMI Servers              Merchant Server (our code)
        │                              │                          │
        │ 1. Load checkout page        │                          │
        │ ◄────────────────────────────────────────────────────── │
        │                              │                          │
        │ 2. Load Collect.js from NMI  │                          │
        │ ─────────────────────────────►                          │
        │                              │                          │
        │ 3. Customer enters PAN/CVV in│                          │
        │    Collect.js iframes        │                          │
        │    [PAN: 4111 1111 1111 1111]│                          │
        │    [CVV: 999]                │                          │
        │                              │                          │
        │ 4. Collect.js POSTs PAN+CVV  │                          │
        │    directly to NMI           │                          │
        │ ─────────────────────────────►                          │
        │                              │                          │
        │ 5. NMI returns payment token │                          │
        │    [token: abc123xyz]        │                          │
        │ ◄─────────────────────────── │                          │
        │                              │                          │
        │ 6. Collect.js injects token  │                          │
        │    into hidden form field    │                          │
        │    [no PAN; token only]      │                          │
        │                              │                          │
        │ 7. Browser POSTs form to checkout
        │ ──────────────────────────────────────────────────────► │
        │    [token: abc123xyz]         │                          │
        │    [amount: 49.99]            │                          │
        │    [order_id: 1001]           │                          │
        │                              │                          │
        │                              │ 8. Server charges token  │
        │                              │ ◄──────────────────────── │
        │                              │    POST /api/transact.php │
        │                              │    security_key + token   │
        │                              │                          │
        │                              │ 9. Approval + TXN ID     │
        │                              │ ─────────────────────────►│
        │                              │                          │
        │ 10. Success page             │                          │
        │ ◄────────────────────────────────────────────────────── │
```

### What our server sees (step 7–9)
- Payment token: `abc123xyz` (opaque reference — not a PAN)
- Amount: `49.99`
- Customer billing address (for AVS verification)
- NMI transaction ID: `TXN001` (stored in order meta)

### What our server NEVER sees
- PAN (card number)
- CVV
- Full magnetic stripe data
- Card expiry (except last4/expiry for display, if passed by NMI after tokenization)

---

## Cardholder Data Environment (CDE) scope

```
┌─────────────────────────────────────────────────────────────┐
│  IN SCOPE (merchant environment)                            │
│                                                             │
│  ┌─────────────────┐    ┌────────────────────────────────┐  │
│  │ Customer Browser│    │ Merchant Web Server (Hetzner)  │  │
│  │                 │    │                                │  │
│  │ Collect.js      │    │ Nginx → PHP-FPM → WooCommerce  │  │
│  │ (NMI-hosted)    │    │                                │  │
│  │                 │    │ Stores: order tokens, TXN IDs  │  │
│  │ Stripe.js       │    │ Never stores: PAN, CVV         │  │
│  │ (Stripe-hosted) │    │                                │  │
│  └────────┬────────┘    └──────────────┬─────────────────┘  │
│           │                            │                    │
└───────────│────────────────────────────│────────────────────┘
            │                            │
            │ PAN (direct)               │ Token only
            ▼                            ▼
┌───────────────────────────────────────────────────────────┐
│  NMI / Stripe (PCI DSS Level 1 Service Providers)         │
│                                                           │
│  Store PANs in encrypted Customer Vault / PaymentMethods  │
│  Handle authorization, settlement, chargeback handling    │
│  AOCs on file; listed on Visa's Global Registry           │
└───────────────────────────────────────────────────────────┘
```

---

## Stored data inventory

| Data element | Stored in WooCommerce DB? | Notes |
|--------------|--------------------------|-------|
| PAN (card number) | **No** | Never received by server |
| CVV | **No** | Never received by server |
| Expiry date | Display only (last4 + exp_month/year from processor) | Non-sensitive after tokenization |
| NMI Customer Vault ID | Yes (wp_postmeta) | Opaque token; useless without NMI security_key |
| Stripe PaymentMethod ID | Yes (wp_woocommerce_payment_tokens) | Opaque; useless without Stripe secret key |
| NMI Transaction ID | Yes (order meta) | Required for refunds |
| Stripe PaymentIntent ID | Yes (order meta) | Required for refunds, disputes |
| Billing address | Yes | Used for AVS verification; not cardholder data per PCI |
