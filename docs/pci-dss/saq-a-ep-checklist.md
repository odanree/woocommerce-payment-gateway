# PCI DSS v4.0 SAQ-A-EP Alignment Checklist

**Merchant type**: Card-not-present e-commerce, payment page hosted on merchant domain, all payment processing outsourced to NMI/Stripe.

**Attestation level**: SAQ-A-EP (Self-Assessment Questionnaire A — E-commerce, Partially Outsourced)

**Last reviewed**: 2024-01-15

---

## Scope

The cardholder data environment (CDE) for this merchant includes:
- The web server hosting the WooCommerce store (handles tokenization JS loading, receives tokens)
- The Nginx reverse proxy
- DNS (Cloudflare)

**Out of scope** (handled by NMI/Stripe as PCI-compliant service providers):
- Storage of PANs, CVVs, expiry dates
- Card data transmission to acquiring banks
- Card authorization and settlement

---

## Requirement 2 — Apply secure configurations

| Control | Status | Implementation |
|---------|--------|----------------|
| 2.2.1 — All system components use vendor-supported software | Pass | Ubuntu 24.04 LTS, PHP 8.3, MySQL 8.0 (all in active support) |
| 2.2.6 — System security parameters prevent misuse | Pass | SSH key-only auth, fail2ban, UFW deny-default |
| 2.2.7 — Console/non-console admin access encrypted | Pass | SSH only (port 22, key auth); no Telnet/FTP |
| 2.3.1 — Wireless not used in CDE | Pass | VPS environment; no wireless interfaces |

---

## Requirement 4 — Protect cardholder data in transit

| Control | Status | Implementation |
|---------|--------|----------------|
| 4.2.1 — Strong cryptography for cardholder data in transit | Pass | TLS 1.2/1.3 only via Nginx; enforced by ssl-params.conf |
| 4.2.1a — TLS trusted certificates | Pass | Let's Encrypt certificates, auto-renewed via Certbot |
| 4.2.2 — No PANs sent via unprotected messaging | Pass | Collect.js/Stripe.js tokens only; PAN never on our server |

---

## Requirement 6 — Develop and maintain secure systems

| Control | Status | Implementation |
|---------|--------|----------------|
| 6.2.4 — Software development attacks addressed | Pass | Input sanitization via WordPress/WooCommerce functions; parameterized queries |
| 6.3.3 — All software components protected from known vulnerabilities | Pass | Unattended-upgrades for OS; WordPress core auto-update |
| 6.4.1 — Web-facing apps protected against attacks | Pass | fail2ban WordPress filter; Nginx rate limiting; CSP headers |
| 6.4.3 — All payment page scripts managed and authorized | Pass | Only Collect.js (NMI-hosted) and Stripe.js (Stripe-hosted) loaded; CSP allowlist enforced |
| 6.4.3a — Script inventory maintained | Pass | See `docs/pci-dss/payment-page-scripts.md` |

---

## Requirement 8 — Identify users and authenticate access

| Control | Status | Implementation |
|---------|--------|----------------|
| 8.2.1 — All users have unique IDs | Pass | Separate WP admin accounts; no shared credentials |
| 8.3.6 — Passwords meet minimum complexity | Pass | WordPress enforces; SSH key-only for server access |
| 8.6.1 — Interactive logins for system/app accounts | Pass | `www-data` and `mysql` accounts have no interactive shell |

---

## Requirement 10 — Log and monitor access

| Control | Status | Implementation |
|---------|--------|----------------|
| 10.2.1 — Audit logs capture required events | Pass | Nginx JSON access logs; MySQL general/slow query log |
| 10.3.3 — Audit log files protected from destruction | Pass | Log rotation; Netdata alerts on disk full |
| 10.7.1 — Security control failures detected and reported | Pass | Netdata health alerts; fail2ban email on ban |

---

## Requirement 11 — Test security

| Control | Status | Implementation |
|---------|--------|----------------|
| 11.3.1 — Internal vulnerability scans quarterly | Pending | Scheduled with Nessus Essentials |
| 11.3.2 — External vulnerability scans by ASV | Pending | Scheduled with Qualys FreeScan (ASV-approved) |
| 11.4.1 — Penetration test at least annually | Pending | Annual pen test scheduled |

---

## Requirement 12 — Organizational policies

| Control | Status | Implementation |
|---------|--------|----------------|
| 12.3.2 — Targeted risk analysis for each requirement | Pass | This document |
| 12.8 — Third-party service provider management | Pass | NMI and Stripe are listed PCI DSS service providers; AOCs on file |

---

## Service provider PCI compliance

| Provider | Compliance | Verification |
|----------|-----------|-------------|
| NMI | PCI DSS Level 1 Service Provider | AOC available from NMI merchant portal |
| Stripe | PCI DSS Level 1 Service Provider | AOC at stripe.com/docs/security |
| Hetzner | ISO 27001 certified | Physical/infrastructure scope only |
| Cloudflare | PCI DSS Level 1 Service Provider | AOC available from Cloudflare dashboard |

---

## Exclusions and compensating controls

None required. All applicable SAQ-A-EP controls are met directly or are out of scope due to tokenization.
