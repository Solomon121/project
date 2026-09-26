# 10 — Product Types & Provider Plugin Architecture

Everything that varies by business model sits behind an interface with a registry. The
core never switches on a type code. Built-ins register through the same mechanism that
third parties use.

## 1. Registration

```php
// In a third-party WHMCS addon or hooks file:
add_hook('MarketplaceRegisterProviders', 1, function (\WhmcsMarketplace\Extension\Registrar $r) {
    $r->productType(new Acme\Marketplace\FigmaAssetType());
    $r->payoutProvider(new Acme\Marketplace\WiseProvider());
    $r->searchProvider('meilisearch', fn ($c) => new Acme\MeiliSearchProvider($c->get(HttpClient::class)));
});
```

The marketplace fires this during kernel boot (`run_hook('MarketplaceRegisterProviders', $registrar)`).
A plugin declares `apiVersion()`. The registry refuses incompatible major versions and
logs the reason to the health page.

## 2. Product types (§77)

```php
interface ProductTypeInterface
{
    public function code(): string;                              // 'wordpress_theme'
    public function label(): string;                             // translated
    public function category(): ProductTypeFamily;              // DIGITAL|WEBSITE|DOMAIN|SERVICE|SUBSCRIPTION
    public function attributesSchema(): AttributeSchema;         // extra fields (e.g. WP version, Gutenberg-ready)
    public function validateListing(ProductDraft $draft): ValidationResult;
    public function allowedFileRules(): FileRules;               // extensions, max size, archive inspection
    public function fulfilmentStrategy(): FulfilmentStrategyInterface;
    public function supportsLicensing(): bool;
    public function supportedBillingCycles(): array;             // subset of WHMCS cycles
    public function refundPolicyDefaults(): RefundPolicy;
    public function storefrontPresenter(): ?PresenterInterface;  // type-specific display blocks
    public function searchDocument(Product $p): array;          // extra keywords/facets
}

interface FulfilmentStrategyInterface
{
    /** Called from marketplace_CreateAccount; must be idempotent per order item. */
    public function fulfil(OrderItem $item, FulfilmentContext $ctx): FulfilmentResult;
    public function suspend(OrderItem $item, string $reason): void;
    public function unsuspend(OrderItem $item): void;
    public function terminate(OrderItem $item, TerminationReason $reason): void;
    public function renew(OrderItem $item, Period $period): void;
}
```

Built-in implementations and the strategies they use:

| Class | Types (seeded codes) | Fulfilment |
|-------|---------------------|-----------|
| `DigitalDownloadProduct` | graphics, logos, icons, fonts, illustrations, 3d, video, audio, music, stock, ebook, pdf, document, ui_kit, html_template, website_template, whmcs_template | Entitlement + download tokens |
| `SoftwareProduct` | software, desktop_app, mobile_app, saas_selfhosted, node_app, python_app, laravel_app | Entitlement + licence |
| `ScriptProduct` | php_script, js_script, code_snippet, source_code, api | Entitlement + licence (optional) |
| `WordPressProduct` | wordpress_theme, wordpress_plugin | Entitlement + licence + WP updater metadata |
| `WhmcsProduct` | whmcs_module, whmcs_addon | Entitlement + licence |
| `WebsiteProduct` | complete/WordPress/WHMCS/e-commerce/… websites | Service-delivery workflow (handover checklist, credential exchange through encrypted message, escrowed funds until buyer accepts) |
| `DomainProduct` | domain_register, domain_transfer, domain_renew, premium_domain | Native WHMCS domain order (no marketplace fulfilment) |
| `DomainListingProduct` | domain_listing, domain_auction | Escrow + transfer workflow (01 §5) |
| `ServiceProduct` | web_dev, logo_design, seo, hosting_setup, server_admin, wp_dev, whmcs_dev, graphic_design, marketing, consulting, custom_dev | `service_deliveries` workflow |
| `SubscriptionProduct` (decorator) | any type with a recurring tier | Wraps the base strategy and adds renew and suspend semantics |

Admins can create **additional types in the UI** that reuse a built-in handler with their
own code, label, attribute schema and file rules. No PHP is needed for that. New
*behaviour* requires a PHP class.

## 3. Provider interfaces

### 3.1 Payment (§78)

WHMCS gateways are the payment layer (01 §4), so the marketplace does not process card
payments itself. `PaymentProviderInterface` abstracts **how a marketplace checkout is
turned into a payable WHMCS obligation**, which leaves room for future flows without
rewriting checkout:

```php
interface PaymentProviderInterface {
    public function code(): string;                       // 'whmcs_order' (default), …
    public function createPayable(CheckoutSnapshot $s): PayableRef;   // AddOrder → invoice
    public function paymentUrl(PayableRef $ref): string;               // viewinvoice.php?id=
    public function handlePaid(PaidEvent $e): void;                    // from InvoicePaid
    public function refundExecutor(): ?RefundExecutorInterface;        // gateway API refunds
}
```

### 3.2 Payout (§79)

```php
interface PayoutProviderInterface {
    public function code(): string;                       // 'manual','paypal','stripe_connect','wise','payoneer','crypto'
    public function label(): string;
    public function destinationSchema(): FieldSchema;     // fields vendor must provide (validated, encrypted)
    public function validateDestination(array $details): ValidationResult;
    public function supportsCurrency(Currency $c): bool;
    public function estimateFee(Money $amount): Money;
    public function send(PayoutRequest $r): PayoutResult; // idempotent on $r->idempotencyKey
    public function fetchStatus(string $providerRef): PayoutResult;
    public function handleWebhook(ServerRequest $req): ?PayoutStatusUpdate; // verified by provider
    public function configSchema(): FieldSchema;          // admin credentials (encrypted)
}
```

### 3.3 Licence (§80)

```php
interface LicenseProviderInterface {
    public function code(): string;                                      // 'internal'
    public function issue(LicenseIssueRequest $r): IssuedLicense;        // key + limits
    public function validate(LicenseCheck $c): LicenseVerdict;           // status + reason
    public function activate(LicenseCheck $c): ActivationResult;
    public function deactivate(LicenseCheck $c): void;
    public function revoke(License $l, string $reason): void;
    public function keyFormat(): KeyFormatInterface;
}
```

### 3.4 Search (§81)

```php
interface SearchProviderInterface {
    public function index(ProductSearchDocument $doc): void;
    public function remove(string $productUuid): void;
    public function search(SearchQuery $q): SearchResult;    // ids + facets + total
    public function suggest(string $prefix, int $limit): array;
    public function rebuild(iterable $docs): void;
}
```

The default `DatabaseSearchProvider` uses InnoDB FULLTEXT (natural language mode, with
boolean mode for quoted phrases) over `search_index`, plus indexed filters. Adapters for
Meilisearch, OpenSearch/Elasticsearch and Algolia implement the same contract. Results
are always re-hydrated from the DB, and status is re-checked so an unpublished product
never leaks from a stale index.

### 3.5 Storage, Scanner, Cache, Queue

`StorageDisk` (05 §6), `ScannerInterface::scan(stream): ScanResult`, `CacheInterface`
(PSR-16), `QueueInterface` (push, reserve, release, fail). Each has built-ins and a
registry key selected in settings.

## 4. Events & filters (§65)

| Event (`run_hook` name) | Payload |
|-------------------------|---------|
| `marketplace_product_created` / `_updated` / `_approved` / `_rejected` / `_suspended` / `_deleted` | product id, vendor id, changed fields |
| `marketplace_version_published` | product id, version |
| `marketplace_vendor_created` / `_approved` / `_suspended` | vendor id |
| `marketplace_order_created` / `_paid` / `_completed` / `_refunded` / `_cancelled` | order id, item ids |
| `marketplace_license_created` / `_activated` / `_deactivated` / `_expired` / `_revoked` | licence id |
| `marketplace_payout_created` / `_completed` / `_failed` | payout id |
| `marketplace_review_created` / `_approved` | review id |
| `marketplace_refund_requested` / `_completed` · `marketplace_dispute_opened` / `_resolved` | ids |

| Filter | Signature (value, context) → value | Guard |
|--------|------------------------------------|-------|
| `marketplace_filter_product_price` | (Money, Product, Tier, Client?) | must be ≥ 0 and same currency |
| `marketplace_filter_product_display` | (array view model, Product) | escaped at render |
| `marketplace_filter_commission` | (CommissionResult, OrderItem) | invariant re-check (04 §2.3) |
| `marketplace_filter_license_validation` | (LicenseVerdict, LicenseCheck) | cannot turn revoked or blocked into valid |
| `marketplace_filter_vendor_permissions` | (string[] perms, VendorMember) | vendor catalogue only |
| `marketplace_filter_checkout_validate` | (ValidationResult, CheckoutSnapshot) | can add errors, not remove core errors |
| `marketplace_filter_api_response` | (array data, route, Actor) | envelope and error codes enforced after the filter |

Each hook, its payload schema and an example are listed in `docs/developers/hooks.md`, a
Phase 8 deliverable that is generated from attribute annotations on the event classes so
the documentation cannot drift from the code.
