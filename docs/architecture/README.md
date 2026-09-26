# WHMCS Multi-Vendor Digital Marketplace — Architecture (Phase 1)

Status: **Phase 1 — Architecture. Awaiting review.**
No production code has been written. Per the development methodology, implementation
(Phase 2 onward) starts only after this architecture has been reviewed and the open
decisions in [11-implementation-plan.md](11-implementation-plan.md#decisions-needing-review)
are confirmed.

## Deliverables in this phase

| # | Document | Covers (master-prompt sections) |
|---|----------|--------------------------------|
| 01 | [WHMCS integration analysis](01-whmcs-integration.md) | §3, §12–15, §22, §41–42, §53, §59, §75, §92–93. Verified WHMCS extension points and the order/billing integration strategy |
| 02 | [Module architecture](02-module-architecture.md) | §2, §60, §76, §97. Layout, layers, request lifecycle, DI, events, queue, logging |
| 03 | [Database schema](03-database-schema.md) | §57, §58, §59, §38. Every table, column, index and FK, plus the migration plan |
| 04 | [Financial architecture](04-financial-architecture.md) | §9–12, §15, §21, §41–42. Money, commission, ledger, wallet, payouts, refunds, coupons, tax |
| 05 | [Licensing & downloads](05-licensing-and-downloads.md) | §6, §7, §23, §24, §54, §82–83 |
| 06 | [API & webhooks](06-api-architecture.md) | §36, §62, §63, §67, §96 |
| 07 | [WordPress plugin](07-wordpress-plugin.md) | §31–35, §37, §61, §94–95 |
| 08 | [Security architecture](08-security-architecture.md) | §27, §28, §54, §68, §73, §85 |
| 09 | [Roles & permissions](09-permissions.md) | §29, vendor team access, customer scoping |
| 10 | [Product types & provider plugins](10-extensibility.md) | §4, §65, §77–82. Interfaces for product types, payment, payout, license, search, storage, scanner |
| 11 | [Implementation plan](11-implementation-plan.md) | Phases 2–13, test strategy, decisions needing review, known limitations |

## How the WHMCS facts were verified

`developers.whmcs.com` is not reachable from the build environment. Every WHMCS API
action, hook and addon-module convention cited in these documents was checked against
the official WHMCS developer documentation source repository
(`github.com/WHMCS/developer-docs`, snapshot of 2026-01-05). Version requirements
(WHMCS 9.0 requires PHP 8.2+, released 2026-01-20) come from WHMCS release notes.
Anything that could not be verified is marked **UNVERIFIED** and appears in the list
of items to confirm on a real WHMCS 9 installation before the phase that depends on it.

## Architecture at a glance

```text
                ┌─────────────────────────── WHMCS installation ───────────────────────────┐
                │                                                                          │
 Browser ──────►│  index.php?m=marketplace  (client area: storefront, cart, checkout,       │
 (customer /    │                            customer + vendor dashboards)                 │
  vendor)       │  admin/addonmodules.php?module=marketplace  (admin console)              │
                │                                                                          │
                │  modules/addons/marketplace/                                             │
                │   ├─ Http (controllers)  ─► Services (domain logic) ─► Repositories ─► DB│
                │   ├─ hooks.php  ◄── WHMCS events (InvoicePaid, AfterModuleCreate, …)     │
                │   ├─ api.php    ◄── REST /v1/*  (HMAC site keys, user tokens, license)   │
                │   ├─ download.php ◄── signed, expiring download tokens                   │
                │   └─ bin/marketplace (CLI: migrate, worker, health, reindex …)           │
                │  modules/servers/marketplace/  (provisioning module on carrier products: │
                │                                 fulfil / suspend / terminate / renew)    │
                │                                                                          │
                │  WHMCS core (untouched): clients, users, orders, invoices, gateways,     │
                │  tax, currencies, tickets, email templates, cron, registrars             │
                └───────────────┬──────────────────────────────────────▲───────────────────┘
                                │ signed webhooks                      │ signed REST
                                ▼                                      │
                ┌──────────────────────── WordPress site ─────────────┴────────────────────┐
                │ wp-content/plugins/whmcs-marketplace/                                    │
                │  API client + cache (transients/object cache, generation keys)           │
                │  shortcodes · Gutenberg blocks · Elementor widgets · virtual SEO pages   │
                │  webhook receiver → cache invalidation · account bridge (tokens, no pwds)│
                └──────────────────────────────────────────────────────────────────────────┘
```

## Key architecture decisions (ADR summary)

| ADR | Decision | Rationale |
|-----|----------|-----------|
| 001 | One WHMCS addon module + a companion WHMCS provisioning (server) module. No core edits. | Addon modules are the supported extension point for admin/client-area UI. A server module is the supported way to receive per-service lifecycle calls (create, suspend, terminate, renew) from WHMCS. |
| 002 | Marketplace orders become **native WHMCS orders** through `AddOrder` against hidden *carrier products*, with server-computed `priceoverride`. | WHMCS then does invoicing, gateways, fraud checks, tax, currency, recurring billing, dunning and suspension natively, with no parallel billing system. See 01 §4. |
| 003 | Money is DECIMAL(19,4) in the database and an integer fixed-point `Money` value object in PHP. Floats are never used for money. | Exact arithmetic without requiring `bcmath`, and consistent with WHMCS decimal columns. |
| 004 | The vendor ledger is append-only and hash-chained. Wallet balances are a cache derived from it and updated in the same DB transaction under row locks. | §10/§11 immutability, auditability and concurrency safety. |
| 005 | No FKs to WHMCS `tbl*` tables. FKs are used only between `mod_marketplace_*` tables, and referential integrity with WHMCS is kept by hooks (`PreDeleteClient`, `ClientDelete`, `AfterClientMerge`). | FKs into core tables could block WHMCS's own delete and merge operations and break WHMCS upgrades. |
| 006 | The REST API is served by the module's own front controller (`api.php`), because WHMCS does not let addons register custom API actions. | This is the supported "custom page including `init.php`" pattern. |
| 007 | Composer dependencies are namespace-prefixed (php-scoper) into `vendor-scoped/`. | WHMCS ships its own Illuminate, Guzzle etc. Unprefixed copies would conflict. |
| 008 | Work is queued in a DB-backed job queue drained by the WHMCS cron hook and an optional long-running CLI worker. | Only MySQL is guaranteed on WHMCS hosts. Redis is optional. |
| 009 | Transactional outbox for domain events, which drives webhooks, notifications, cache invalidation and the search index. | Events are never lost or emitted for rolled-back transactions. |
| 010 | WordPress never touches the WHMCS DB. It uses HMAC-signed site keys, plus short-lived user tokens for the account bridge. It never sees WHMCS passwords. | §31, §94. |
| 011 | Emails use **native WHMCS email templates** created on activation and sent with `SendEmail` + `customvars`. | Admins edit them in the familiar WHMCS editor, with WHMCS branding and per-language templates. |
| 012 | Customers and vendors are identified by WHMCS **client account** (ownership) + **user** (actor). | WHMCS 8+ users can manage several client accounts. Purchases belong to the account, and actions are attributed to the user. |
