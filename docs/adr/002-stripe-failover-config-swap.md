# ADR 002 — Stripe failover via config swap (no code change)

**Status**: Accepted
**Date**: 2026-04-16

## Context

High-risk merchant accounts can be terminated with as little as 24-hour notice. When a processor drops a merchant, the merchant needs to continue accepting payments immediately — any downtime during termination directly costs revenue.

A common failure mode in custom payment integrations: the processor gateway class is tightly coupled to the code, so switching processors requires a code deploy. For a WooCommerce store, a code deploy during a crisis (account termination) adds risk and delay.

## Decision

The `WC_Gateway_Failover_Manager` class acts as a thin routing layer between WooCommerce and the actual processor. The active processor is controlled by a WordPress option (`active_gateway`), not by code.

Switching from NMI to Stripe requires exactly **one action**: changing `active_gateway` from `'nmi'` to `'stripe'` in WooCommerce settings. This can be done by any admin without a code deploy.

```
WooCommerce → Payment Gateway
    └── WC_Gateway_Failover_Manager  ← this is what WC sees (ID: highrisk_gateway)
            │
            ├── active_gateway = 'nmi'    → WC_Gateway_NMI
            └── active_gateway = 'stripe' → WC_Gateway_Stripe_HighRisk
```

The `gateway_id` stored on WooCommerce orders (`highrisk_gateway`) never changes, so historical order records and refund flows continue to work correctly regardless of which backend is active.

## Consequences

**Positive:**
- Failover in < 60 seconds: change one setting, clear page cache, done
- No code deploy during account termination crisis
- Historical orders retain correct gateway reference
- Both processors are always configured and ready — no setup required during failover

**Negative:**
- Requires maintaining valid credentials for both NMI and Stripe simultaneously
- Stripe may not support all the same MCC categories as NMI — verify before needing failover

**Implementation note:** The `get_active_processor()` method returns the current setting, used in the test suite to verify the config-swap invariant without invoking real API calls.
