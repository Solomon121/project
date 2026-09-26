# 11 — Implementation Plan

## 1. Principles

* **Vertical slices inside phases.** Each phase ends with working, tested code, not scaffolding.
* **Tests before UI for money.** Phase 4's financial core is test-driven, and no admin or vendor finance screens are built before its test suite passes.
* **Definition of done** (every phase):
  * Unit tests green.
  * Static analysis at PHPStan level 8 and the deptrac layer rules clean.
  * Template escape lint clean.
  * Migrations re-runnable.
  * Security checklist items for the phase ticked.
  * Documentation updated.
  * The phase report produced in the required 8-part format (what was implemented, files, DB changes, API changes, security, testing performed, remaining issues, next phase).
* **Honest status.** A feature is reported "complete" only when it is implemented *and* tested. Anything tested only against WHMCS stubs is reported as such until verified on a live WHMCS install.

## 2. Test strategy

| Layer | Tooling | Runs where |
|-------|---------|-----------|
| Unit (Domain, Application with fakes) | PHPUnit 11, property-based tests via `eris`-style generators for money, commission and coupons | Anywhere (CI, this environment) |
| Persistence | PHPUnit + real MySQL 8 / MariaDB 10.6 in Docker, running migrations and repositories. Capsule is bootstrapped standalone using `illuminate/database`, the same library WHMCS uses. | CI |
| WHMCS integration | Real WHMCS 9 dev install (Docker: PHP-FPM + MySQL + ionCube), with scripted `localAPI` scenarios: AddOrder → invoice → mark paid → CreateAccount → licence → download → refund reconciliation | **Requires a licensed WHMCS development install.** A WHMCS licence cannot be provided from this environment. See decision D6. |
| API contract | OpenAPI schema validation of responses and a Schemathesis-style fuzz run | CI (against the integration stack) |
| WordPress | WP PHPUnit test suite (`wp-env`), Plugin Check, PHPCS WPCS | CI |
| Security | Automated suites from 08 §5, plus ZAP baseline scan against the integration stack | CI and pre-release |
| Performance | Seeders generating 10k vendors, 50k products, 100k customers and 1M order items. k6 scenarios for catalogue, search, vendor dashboard, API pagination, concurrent downloads and WP cached vs cold. `EXPLAIN` checks on the top 30 queries. | Pre-release (Phase 12) |
| E2E / UI | Playwright (Chromium) across desktop, tablet and mobile viewports, with axe-core accessibility checks (WCAG 2.2 AA) | CI (integration stack) |

## 3. Phases

| Phase | Scope | Key deliverables | Exit criteria |
|-------|-------|------------------|---------------|
| **1 Architecture** ✅ (this) | Design | `docs/architecture/*` | Reviewed and decisions D1–D8 answered |
| **2 Database** | Migrations 0001–0016, seeders, Capsule bootstrap, migration runner, CLI `migrate`, module `_activate`/`_upgrade`/`_deactivate`, schema integrity tests | `whmcs/modules/addons/marketplace/{marketplace.php,migrations,database/seeds,src/Infrastructure/Persistence,bin}` | Fresh install, upgrade (re-run) and rollback proven on MySQL 8 and MariaDB 10.6 in CI. FK and index assertions in tests. |
| **3 Marketplace core** | Kernel, router, middleware, DI, settings, i18n. Categories, product types, products, tiers, media, cart, checkout (AddOrder carrier flow), orders, wishlist, follows, reviews. Server module skeleton. | Client-area storefront (list, detail, category, search), cart and checkout | Checkout → invoice → paid → order completed on the WHMCS integration stack. Price-tamper tests pass. |
| **4 Financial** | Money, commission engine, ledger, wallets, coupons, payouts (manual, PayPal, Stripe Connect), refunds + WHMCS reconciliation, tax readback | `src/Domain/Finance`, `src/Application/Finance`, provider adapters | Test matrix in 04 §9 green. `ledger:verify` clean after 10k randomised scenario runs. |
| **5 Licensing & downloads** | Licence engine and API (signed responses), entitlements, download tokens, storage (local + S3), scanning (ClamAV), versions, update notifications, vendor SDK | `download.php`, `/v1/licenses/*`, `sdk/php`, `sdk/wordpress-updater` | Concurrency tests on activation limits. Download authorisation matrix passes. S3 presign tested against MinIO. |
| **6 Vendor system** | Application and approval, profile and storefront, team members, product submission wizard with chunked uploads, earnings, payouts UI, analytics, coupons, customer messages, service deliveries | Vendor dashboard (client area) | Cross-vendor isolation suite passes. |
| **7 Admin** | Dashboard (KPIs and charts with date ranges), moderation queues, vendors, products (bulk actions with confirmations), finance screens, refunds and disputes, reviews, licences, reports + CSV/XLSX/PDF export, settings (schema-driven), RBAC UI, logs, health checks, setup wizard, IP complaints, backups, import/export | `addonmodules.php?module=marketplace` | RBAC matrix tests. Health check passes on the reference stack. |
| **8 API** | Full `/v1` surface, auth schemes, rate limits, idempotency, webhooks with retries, OpenAPI spec, developer docs (hooks, events, filters, providers) | `api.php`, `openapi/marketplace.v1.yaml`, `docs/developers/*` | Contract and fuzz tests pass. Replay and signature tests pass. |
| **9 WordPress plugin** | API client, cache and generations, webhook receiver, account bridge, shortcodes, blocks, Elementor widgets, virtual pages, SEO/JSON-LD/sitemaps, Multisite, WP-CLI | `wordpress/whmcs-marketplace` | WP test suite and Plugin Check clean. Cache invalidation end-to-end via webhook. |
| **10 UI/UX pass** | Design-system audit across all five surfaces, dark mode, RTL (Arabic), accessibility fixes | Updated tokens and components | axe: zero serious or critical violations. Visual review on 3 viewports. |
| **11 Security audit** | Execute 08 §5 plus a manual code review of every endpoint, query, upload and auth flow | `docs/security/audit-report.md` with each finding and its fix | No open high or critical findings. |
| **12 Performance audit** | Large-data seeders, k6, EXPLAIN review, N+1 detection (query-count assertions in tests) | `docs/performance/report.md` | Catalogue p95 < 300 ms server time at 50k products. Vendor dashboard p95 < 500 ms with 1M order items. API list endpoints constant-time pagination. |
| **13 Final QA** | Test matrix: Admin, Vendor, Customer, Guest, API, WordPress, Payments, Orders, Refunds, Licences, Downloads, Payouts, Reviews, Security, Cron, Email, Mobile | `docs/qa/test-matrix.md` with evidence | All critical rows pass on WHMCS 9 (and 8.13 if D2 = yes). |

Later or optional features are scheduled after the core is stable, inside Phases 6–9, and
flagged in progress reports:

* affiliate and referral modules
* abandoned-cart recovery emails
* domain aftermarket (listings, offers, auctions, escrow)
* watermarking
* Wise, Payoneer and crypto payout adapters
* search adapters for Meilisearch, OpenSearch and Algolia

## 4. Decisions needing review

| # | Decision | Recommendation |
|---|----------|----------------|
| **D1** | Order integration: carrier products + `AddOrder` with `priceoverride` (01 §4) | **Approve.** It is the only option that gives native recurring billing, fraud checks and orders without a parallel billing system. |
| **D2** | Supported versions: WHMCS 9.x only, or 9.x + 8.13 LTS? PHP 8.2+ either way | **WHMCS 9.x only**, unless you have customers on 8.13. It halves the QA matrix. |
| **D3** | Canonical public storefront: WordPress (WHMCS pages canonicalise to WP) or WHMCS client area | **WordPress canonical** when the plugin is connected, and the WHMCS client-area storefront otherwise. |
| **D4** | Refund execution default: `account_credit` (fully automatic) or `gateway_manual` (admin completes the refund in WHMCS) | Default `gateway_manual`, with an admin toggle for auto-credit under a threshold. WHMCS offers no refund API. |
| **D5** | First-release payout providers | Manual/Bank, PayPal Payouts, Stripe Connect. Wise, Payoneer and crypto come as later adapters. |
| **D6** | WHMCS integration test environment | You provide a WHMCS **development licence** (WHMCS offers these to module developers) for the CI integration stack. Without one, integration behaviour can only be tested against stubs and will be reported that way. |
| **D7** | Product name, PHP namespace and text domain | `WhmcsMarketplace` / `whmcs-marketplace` as placeholders. Tell me if you have a brand name. |
| **D8** | Licensing of this product itself (commercial). Should the module ship with its own licence check? | Out of scope unless requested. Note that WHMCS modules are often ionCube-encoded for sale, which our build can support later. |

## 5. Known limitations (stated up front, not discovered later)

1. **No automatic gateway refunds** through WHMCS's API (01 §5). There is a workflow plus reconciliation, and a future per-gateway executor.
2. **No API to move domains between WHMCS clients.** Domain aftermarket handover uses EPP transfer orders or admin-assisted moves.
3. **Custom billing intervals** are limited to WHMCS cycles.
4. **DRM is not possible** for downloaded files. We provide deterrence, traceability and licence verification only.
5. **Queue latency** is bounded by the WHMCS cron interval unless the optional worker daemon is run.
6. **XLSX/PDF exports** need bundled libraries (PhpSpreadsheet is heavy). Exports are CSV/JSON first, with XLSX via a lightweight writer and PDF via a minimal HTML-to-PDF library. These are evaluated for size in Phase 7.
7. **Legal pages** ship as clearly-labelled templates, not legal advice. Vendor verification levels reflect only the checks actually performed.
8. **Scale:** the targets (10k vendors, 1M orders) are design goals. They will be demonstrated with synthetic data in Phase 12, which is not a production guarantee.

## 6. Next step

On approval (and answers to D1–D8), start **Phase 2 — Database**:

* migrations 0001–0016
* seeders
* the migration runner and CLI
* the module's `_activate`/`_upgrade`/`_deactivate` functions
* schema tests in CI against MySQL 8 and MariaDB 10.6
