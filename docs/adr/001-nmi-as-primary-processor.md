# ADR 001 — NMI as primary payment processor

**Status**: Accepted
**Date**: 2026-04-16

## Context

The target merchant categories (subscription supplements, CBD, adult novelty accessories) are classified as high-risk by Visa/Mastercard's Merchant Category Code (MCC) system. Most standard payment facilitators (Stripe, Square, PayPal) either decline these merchant categories entirely or terminate accounts with limited notice when chargeback ratios breach 1%.

High-risk merchants need a processor that:
1. Explicitly supports their MCC category
2. Has established relationships with acquiring banks that accept high-risk portfolios
3. Provides direct API access (not payment facilitator model) for maximum control

## Decision

Use **NMI (Network Merchants Inc)** as the primary processor.

NMI is an ISO (Independent Sales Organization) gateway that:
- Connects to multiple acquiring banks simultaneously — if one bank declines a MCC, NMI routes to another
- Explicitly supports high-risk categories via dedicated acquiring relationships
- Provides Collect.js for client-side tokenization (SAQ-A-EP compliant)
- Has direct integration path for subscription billing and recurring charges
- Offers Customer Vault for stored payment methods without PCI scope expansion

## Consequences

**Positive:**
- Direct processor relationship reduces termination risk vs. payment facilitators
- Multiple acquiring bank routing increases authorization rates
- Collect.js keeps PAN off our servers (SAQ-A-EP scope)

**Negative:**
- NMI requires merchant account setup (vs. Stripe's instant activation)
- Higher per-transaction fees than Stripe in most categories
- Integration is more complex (no official PHP SDK — we use the Direct Post API)

**Mitigation**: See ADR 002 for the Stripe failover mechanism that neutralizes the risk of any single processor relationship ending.
