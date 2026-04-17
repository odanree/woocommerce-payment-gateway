# ADR 003 — Client-side tokenization (Collect.js / Stripe.js)

**Status**: Accepted
**Date**: 2026-04-16

## Context

Processing payments on a WooCommerce store requires a decision about where card data is captured:

1. **Server-side capture**: Card fields in HTML form → POST to our server → forward to processor. Puts PAN on our server → full PCI DSS SAQ-D scope (hundreds of controls, annual QSA audit).
2. **Client-side tokenization**: Processor-hosted JavaScript captures card data in browser → exchanges PAN for a token → only token sent to our server. PAN never touches our infrastructure → SAQ-A-EP scope.

## Decision

Use client-side tokenization exclusively:
- **NMI**: Collect.js library hosted by NMI. Card fields are rendered in NMI-controlled iframes. Browser sends PAN directly to NMI → returns a single-use payment token.
- **Stripe**: Stripe.js Elements. Same iframe-based approach → returns a `PaymentMethod` ID (`pm_...`).

Our server receives and stores **only tokens**, never PANs, CVVs, or track data.

## Consequences

**Positive:**
- PCI scope limited to **SAQ-A-EP** (vs. SAQ-D for server-side capture)
- SAQ-A-EP: ~61 controls vs. ~329 for SAQ-D
- No card data in application logs, database, or error traces
- Processor handles PCI-compliant card storage in their Customer Vault / Stripe PaymentMethods

**Negative:**
- Collect.js is a third-party script — Collect.js library version and hosting is NMI-controlled
- CSP headers must explicitly allow `secure.nmi.com` and `js.stripe.com` script sources
- If Collect.js/Stripe.js is unavailable, checkout is blocked (no server-side fallback — by design)

**Attestation note**: Because the payment page is hosted on our domain (not fully outsourced), this qualifies as **EP** (Partially Outsourced E-commerce), not SAQ-A. The merchant must complete SAQ-A-EP annually and maintain a quarterly ASV vulnerability scan.
