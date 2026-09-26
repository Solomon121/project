# 02 — Module Architecture

## 1. Repository layout (source tree)

The Git repository holds both deliverables plus shared tooling. Release packaging copies
each into its install location.

```text
/whmcs/modules/addons/marketplace/         → install to <whmcs>/modules/addons/marketplace/
/whmcs/modules/servers/marketplace/        → install to <whmcs>/modules/servers/marketplace/
/wordpress/whmcs-marketplace/              → install to wp-content/plugins/whmcs-marketplace/
/docs/                                     architecture, admin/vendor/customer/dev guides
/openapi/marketplace.v1.yaml               generated + hand-curated API spec
/tests/                                    PHPUnit (unit, integration, security), WP tests
/tools/                                    php-scoper config, build/release scripts, fixtures
```

## 2. Addon module layout

```text
marketplace/
├── marketplace.php            WHMCS entry: _config/_activate/_deactivate/_upgrade/_output/_sidebar/_clientarea
│                              (≈50 lines: bootstraps the kernel and delegates)
├── hooks.php                  registers WHMCS hooks → delegates to src/Integration/Hooks/*
├── api.php                    REST front controller  (require init.php → Api\Kernel)
├── download.php               download front controller (token → stream/redirect)
├── bin/marketplace            CLI (migrate, worker, cron:run, health, reindex, cache:clear,
│                              license:cleanup, stats:rebuild, webhook:retry, backup, export)
├── bootstrap.php              autoloaders (src/ + vendor-scoped/), container build
├── config/
│   ├── services.php           DI container definitions
│   ├── permissions.php        permission catalogue + default role grants
│   ├── events.php             event → listener map
│   ├── schedule.php           cron task registry + default intervals
│   ├── product_types.php      built-in product-type registrations
│   └── routes/{admin,client,vendor,api}.php
├── src/                       PSR-4: WhmcsMarketplace\
│   ├── Kernel/                Container, Config, Router, Request/Response, ErrorHandler
│   ├── Http/
│   │   ├── Admin/Controllers/     one controller per admin section
│   │   ├── Client/Controllers/    storefront, cart, checkout, customer dashboard
│   │   ├── Vendor/Controllers/    vendor dashboard
│   │   ├── Api/V1/Controllers/    REST
│   │   ├── Middleware/            Auth, Csrf, RateLimit, Permission, VendorScope, Json
│   │   └── Requests/              input DTOs + validation rules (one per form/endpoint)
│   ├── Domain/                business logic, framework-free, unit-testable
│   │   ├── Catalog/  (Product, Category, ProductType, Version, File, Collection, Search)
│   │   ├── Vendor/   (Vendor, Profile, Verification, Membership, Storefront)
│   │   ├── Sales/    (Cart, Checkout, Order, OrderItem, Coupon, Subscription)
│   │   ├── Finance/  (Money, Commission, Ledger, Wallet, Payout, Refund, Fees)
│   │   ├── Licensing/(License, Activation, KeyGenerator, Validator)
│   │   ├── Delivery/ (Entitlement, DownloadToken, DownloadPolicy, Watermark)
│   │   ├── Social/   (Review, Vote, Wishlist, Follow)
│   │   ├── Trust/    (Dispute, IpComplaint, Report, Moderation, Fraud)
│   │   ├── Engagement/(Notification, Preference, Affiliate, Referral, AbandonedCart)
│   │   └── Shared/   (Ids, Clock, Slug, Semver, Pagination, Result types)
│   ├── Application/           use-case services orchestrating Domain + Infra
│   │   (e.g. SubmitProductService, CheckoutService, ApproveRefundService, RequestPayoutService)
│   ├── Infrastructure/
│   │   ├── Persistence/       Repositories (Capsule), Migrations runner, TransactionManager
│   │   ├── Whmcs/             WhmcsGateway (localAPI adapter), CurrentUser adapter, Tax, Currency
│   │   ├── Storage/           LocalDisk, S3CompatibleDisk (S3/R2/Spaces/B2/MinIO)
│   │   ├── Scanning/          ClamAvScanner, NullScanner
│   │   ├── Search/            DatabaseSearchProvider (+ adapters later)
│   │   ├── Queue/             DatabaseQueue, Worker
│   │   ├── Cache/             ApcuCache, RedisCache, DatabaseCache, NullCache
│   │   ├── Crypto/            KeyRing, Encrypter (sodium secretbox), Signer (Ed25519), Hmac
│   │   ├── Http/              outbound HTTP client (webhooks, payout providers) w/ SSRF guard
│   │   └── Logging/           channelled PSR-3 logger, redaction
│   ├── Integration/
│   │   ├── Hooks/             one invokable class per WHMCS hook
│   │   ├── Provisioning/      handlers behind modules/servers/marketplace
│   │   └── Email/             template installer + NotificationMailer (SendEmail)
│   ├── Providers/             built-in provider plugins (PayoutProvider, LicenseProvider, …)
│   ├── Events/                domain event classes + Outbox + Dispatcher
│   └── Console/               CLI commands
├── migrations/                0001_create_core_tables.php … (ordered, reversible)
├── database/seeds/            default roles, permissions, product types, license types,
│                              categories, settings, email templates
├── templates/
│   ├── admin/                 Smarty-free PHP views (admin output is echoed HTML)
│   ├── client/                *.tpl Smarty templates for _clientarea (overridable, see §7)
│   └── partials/
├── assets/                    compiled CSS/JS (design tokens, components); source in /tools
├── lang/                      english.php, french.php, spanish.php, german.php,
│                              portuguese-pt.php, portuguese-br.php, arabic.php
├── storage/                   default *private* storage root (moved outside webroot in
│   └── .htaccess (deny all)   production; health check warns if web-reachable)
├── logs/                      default log dir (same protection)
└── vendor-scoped/             prefixed composer deps (WhmcsMarketplace\Vendor\…)
```

Rules enforced in review and by a static-analysis check (deptrac):

* `Domain/` depends on nothing outside `Domain/` and `Shared/`. It has no Capsule, no `$_GET` and no WHMCS classes.
* `Application/` depends on Domain plus *interfaces* for infrastructure (repositories, gateways, clock).
* Only `Infrastructure/Whmcs` calls `localAPI()` or WHMCS classes.
* Only `Infrastructure/Persistence` touches Capsule.
* Controllers are thin. They validate input through Request DTOs, call one application service and render.

## 3. Request lifecycles

### 3.1 Client area (`index.php?m=marketplace&r=/vendor/products`)

```text
WHMCS → marketplace_clientarea($vars)
  → Kernel::handleClientArea($vars)
     → Router matches r= against config/routes/{client,vendor}.php (whitelisted patterns)
     → Middleware: SessionIdentity (CurrentUser → client_id,user_id) → RequireLogin?
                   → Csrf (state-changing) → RateLimit → VendorScope (resolves vendor
                   the user may act for) → Permission (e.g. vendor.products.edit)
     → Controller → Application service → Domain → Repos
     → returns ['templatefile'=>…, 'vars'=>…, 'breadcrumb'=>…, 'pagetitle'=>…]
  JSON sub-requests (AJAX) use the same router with &format=json and return via the
  JSON responder (exits after output) — still inside WHMCS session & CSRF protection.
```

SEO-friendly URLs: WHMCS addon URLs are `index.php?m=marketplace&r=…`. We ship optional
Apache/Nginx rewrite snippets (`/marketplace/product/{slug}` → `index.php?m=marketplace&r=/product/{slug}`).
A URL generator emits pretty URLs only when the admin has enabled them and the health
check confirms they work. The **public, SEO-canonical catalogue is expected to be
WordPress** (07). WHMCS pages emit `<link rel=canonical>` to the WordPress URL when WP
integration is enabled, to avoid duplicate content.

### 3.2 Admin (`addonmodules.php?module=marketplace&r=/products`)

`marketplace_output` → `Kernel::handleAdmin` → Router (`routes/admin.php`) → middleware
(AdminIdentity from `$_SESSION['adminid']`, Csrf, Permission) → controller → HTML view.
Access is gated twice: WHMCS's addon access control (admin role groups) is the outer gate,
and the marketplace RBAC is the granular inner gate (09).

### 3.3 REST API (`/modules/addons/marketplace/api.php/v1/...` or rewritten `/marketplace-api/v1/...`)

`api.php` → `require init.php` (no client-area bootstrap) → `Api\Kernel` → middleware
(JSON only, RequestId, SignatureAuth | BearerAuth | LicenseAuth, RateLimit, Scope) →
controller → consistent envelope (06). A top-level exception handler guarantees that no
PHP error text ever reaches the response.

### 3.4 Downloads

`download.php?t=<token>` → validate token (hash lookup, expiry, uses, owner session or
bound client) → policy check (entitlement still valid, licence not revoked, rate limit)
→ log → stream through `X-Sendfile` / `X-Accel-Redirect` when configured, PHP chunked
stream otherwise, or a 60-second presigned URL for S3 disks. See 05.

## 4. Dependency injection

A small PSR-11 container (our own, ~200 lines, or `league/container` scoped) is built
once per request from `config/services.php`. Services are constructor-injected, and no
service locator is used inside Domain or Application. Third-party extensions register
providers through a documented hook (`marketplace_register_providers`, 10 §2).

## 5. WHMCS gateway adapter

```php
interface WhmcsGateway {
    public function call(string $action, array $params, ?string $asAdmin = null): array; // throws WhmcsApiException
    public function addOrder(AddOrderCommand $cmd): AddOrderResult;
    public function acceptOrder(int $orderId): void;
    public function fraudCheck(int $orderId, string $ip): FraudResult;
    public function addCredit(int $clientId, Money $amount, string $note): void;
    public function sendEmail(string $template, int $relId, array $vars): void;
    public function createSsoToken(int $userId, int $clientId, string $redirectPath): string;
    public function openTicket(TicketCommand $cmd): TicketRef;
    // …
}
```

Every call is logged to `api.log` with parameters redacted. On failure it throws. Nothing
is silently swallowed (rule 12).

## 6. Events, outbox and queue

```text
Application service (inside DB transaction)
   └─ records DomainEvent → mod_marketplace_outbox (same TX)            ← never lost / never phantom
Cron tick / worker
   └─ OutboxRelay reads undispatched rows (FOR UPDATE SKIP LOCKED where supported,
      else claim by UPDATE … WHERE dispatched_at IS NULL LIMIT n with claim token)
       ├─ synchronous listeners (in-process extension hooks, §65 events)
       └─ enqueue jobs: SendNotification, DeliverWebhook, ReindexProduct, InvalidateStats
Job worker
   └─ mod_marketplace_jobs: attempts, exponential backoff, dead-letter after max attempts,
      unique_key to coalesce duplicates (e.g. reindex product 42 once)
```

Events are named as in §65 (`marketplace_product_created`, …). Webhook event names are the
dotted form (`product.created`, 06 §6). Extension developers subscribe with
`Marketplace::on('marketplace_order_paid', callable)` or with WHMCS `add_hook()`, since we
also fire `run_hook('marketplace_order_paid', $payload)` so the familiar WHMCS mechanism works.

**Filters** (§65) such as `marketplace_filter_product_price`, `…_commission`,
`…_license_validation`, `…_vendor_permissions`, `…_checkout_validate` and
`…_api_response` are run through a `FilterPipeline`. Filter results that affect money are
re-validated (non-negative, currency unchanged, commission ≤ gross) so an extension cannot
corrupt the ledger.

## 7. Presentation layer

* **Client area**: Smarty `.tpl` templates rendered by WHMCS. Theme authors can override them by copying to `templates/<activeTheme>/marketplace/…`, and a resolver checks there first. All markup uses a `.mkp-` namespaced component library so it doesn't clash with Twenty-One/Nexus/Bootstrap styles. Design tokens are CSS custom properties (`--mkp-color-primary`, `--mkp-radius`, `--mkp-space-3`, …) with light and dark schemes.
* **Admin**: server-rendered HTML views plus the same component library, adapted to WHMCS admin chrome (native breadcrumbs, tabs and tables, 02 §3.2).
* **JavaScript**: framework-free ES modules (progressive enhancement: filters, cart, uploads, charts). Charts use a vendored, locally served chart library, with no CDN calls from the admin area.
* **Escaping**: WHMCS does not enable Smarty auto-escaping globally, and we must not change a global WHMCS setting. So every variable in our `.tpl` files carries an explicit `|escape:'html'` (or `:'url'`/`:'javascript'`), and a CI template lint fails the build on an unescaped `{$…}`. The only exception is values pre-sanitised by the HTML purifier, which are passed as `…_safe_html` and marked `nofilter` with a lint allowlist comment. PHP views use `e()` for HTML, `attr()` for attributes, `js()` for JSON-in-script and `url()` for URLs. Rich descriptions are sanitised with an allowlist HTML purifier at write time and again at render.

## 8. Logging (§68)

PSR-3 logger with channels written to separate files under the configured log directory:
`application.log`, `api.log`, `security.log`, `payment.log`, `payout.log`, `license.log`,
`webhook.log` and `cron.log`, rotated daily with configurable retention.
A `Redactor` scrubs keys matching `/pass|secret|token|key|authorization|card|iban|account_number/i`
and known secret values before writing. Operational failures (failed payments, payouts,
webhooks, downloads, cron, licence errors, storage) are also written to
`mod_marketplace_health_events` for the searchable monitoring UI (§99). The audit log is
separate and lives in the DB (08 §6).

## 9. Configuration

* `tbladdonmodules` holds only what WHMCS needs (module activated, access roles).
* `mod_marketplace_settings` (key → JSON value, `is_encrypted`) holds everything in §69. It is typed through a `SettingsSchema` class that declares type, default, validation and whether a value is a secret. The admin settings UI is generated from the schema, grouped into the §69 sections.
* Secrets (payout provider credentials, S3 keys, webhook secrets) are encrypted with the marketplace KeyRing (08 §4) and are never rendered back into forms. Only "•••• set, last changed …" is shown.
* A read-through in-request cache prevents repeated setting queries.

## 10. CLI (§97)

`php modules/addons/marketplace/bin/marketplace <command>` bootstraps WHMCS `init.php` in
CLI mode:

| Command | Purpose |
|---------|---------|
| `migrate [--rollback=N] [--status]` | Database migrations |
| `worker [--queue=default] [--max-time=300]` | Long-running job worker |
| `cron:run [task]` / `cron:list` | Run scheduled tasks now / show schedule & last runs |
| `cache:clear [--tag=]` | Clear marketplace caches |
| `search:reindex [--product=]` | Rebuild search index |
| `license:cleanup` | Expire licences, prune stale activations |
| `stats:rebuild --from= --to=` | Re-aggregate daily statistics |
| `webhook:retry [--delivery=]` | Retry failed/dead deliveries |
| `ledger:verify [--vendor=]` | Verify hash chain + wallet balances match ledger |
| `health` | Run health checks (exit code ≠0 on ERROR) |
| `backup:create` / `export:*` / `import:*` | §55/§56 |
