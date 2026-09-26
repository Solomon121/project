# 01 — WHMCS Integration Analysis

## 1. Target platform

| Item | Target | Source / status |
|------|--------|-----------------|
| WHMCS | **8.13 LTS and 9.x** | WHMCS 9.0 was released 2026-01-20. Behaviour on 8.13 is **UNVERIFIED** and will be confirmed on a real install. If 8.13 needs workarounds, drop it and support 9.x only. |
| PHP | **8.2 minimum, 8.3 recommended** | WHMCS 9 requires PHP 8.2+. No PHP 8.1-only or deprecated features, and code will be checked on 8.2, 8.3 and 8.4. |
| DB | MySQL 8.0+ / MariaDB 10.6+ (InnoDB, utf8mb4) | Needs InnoDB FULLTEXT, `SELECT … FOR UPDATE`, JSON columns stored as `LONGTEXT` with `JSON_VALID` checks where MariaDB differs. |
| Required PHP ext | `pdo_mysql`, `json`, `mbstring`, `openssl`, `sodium`, `fileinfo`, `curl`, `zip`, `intl` (optional: `gd`/`imagick`, `apcu`, `redis`) | Checked by the health-check page. **`bcmath` is not required** (ADR-003). |

## 2. Verified WHMCS extension points we use

All of the following were checked against the WHMCS developer documentation source.

### 2.1 Addon module (`modules/addons/marketplace/`)

| Function | Use |
|----------|-----|
| `marketplace_config()` | Name, version, author, and a minimal field set (licence/debug toggle only). Field types are limited to text, password, yesno, textarea, dropdown and radio, so real settings live in our own settings UI and table. |
| `marketplace_activate()` | Runs migrations, seeds defaults, creates carrier products, email templates and the first API key, and redirects to the setup wizard. |
| `marketplace_deactivate()` | By default **keeps data**. Dropping tables requires an explicit "Remove all marketplace data" action with typed confirmation, and a financial-data export is offered first. |
| `marketplace_upgrade($vars)` | Runs pending migrations on first access after files are updated. |
| `marketplace_output($vars)` | Admin console. `$_SESSION['adminid']` identifies the admin, and access is further filtered by marketplace RBAC (09). |
| `marketplace_sidebar($vars)` | Admin section navigation. |
| `marketplace_clientarea($vars)` | Storefront, cart, checkout, customer and vendor dashboards (`index.php?m=marketplace&r=/…`). Returns `templatefile`, `vars`, `requirelogin` and `breadcrumb`. |
| `hooks.php` | Registered hooks (§2.3). |
| `lang/*.php` | Addon language files (English, French, Spanish, German, Portuguese, Arabic). |

### 2.2 Companion provisioning module (`modules/servers/marketplace/`)

Carrier products (§4) use this module. WHMCS calls its lifecycle functions, which is how
fulfilment rides on WHMCS's own automation:

| Function | Marketplace action |
|----------|--------------------|
| `marketplace_MetaData` | Declares the module as having no server requirement (`RequiresServer => false`). |
| `marketplace_CreateAccount` | Fulfils the order item: issues the licence, grants download entitlement, starts support/update periods and, for services, creates a delivery workflow. Idempotent: keyed by `tblhosting.id`. |
| `marketplace_SuspendAccount` / `UnsuspendAccount` | Suspends or reinstates the licence and entitlement (overdue subscription, fraud or admin action). |
| `marketplace_TerminateAccount` | Ends the subscription, expires the licence at period end, and revokes it if terminated for refund or chargeback. |
| `marketplace_Renew` | Extends licence expiry and the support/update window for the paid period. |
| `marketplace_ClientArea` | Renders the licence key, downloads and version list on the WHMCS service details page. |
| `marketplace_AdminServicesTabFields` | Shows the marketplace order item, vendor, licence and download log to admins on the service page. |
| `marketplace_AdminCustomButtonArray` | Admin actions: "Regenerate licence", "Reset activations", "Resend download email". |

### 2.3 Hooks (all verified to exist)

| Hook | Purpose |
|------|---------|
| `InvoicePaid` | Marks the marketplace order paid, posts vendor *pending* ledger entries and commission records, and records coupon usage. Idempotent on `invoiceid`. |
| `InvoiceCancelled`, `InvoiceUnpaid` | Moves the order to Cancelled or Awaiting Payment. Reverses ledger entries if the invoice was previously paid. |
| `InvoiceRefunded`, `ManualRefund`, `AddTransaction` (with `amountout`) | Reconcile refunds executed in WHMCS against marketplace refund records (04 §7). |
| `AfterModuleCreate` / `…Suspend` / `…Terminate` / `PreModuleRenew` | Belt-and-braces audit of lifecycle events on carrier services. The real work happens in the server module functions. |
| `AcceptOrder`, `CancelOrder`, `FraudOrder`, `PendingOrder`, `AfterFraudCheck` | Mirror WHMCS order status. `FraudOrder` suspends entitlements and freezes the vendor's pending funds for that order. |
| `ShoppingCartValidateCheckout` | Blocks carrier products from being bought through the standard WHMCS cart, so they are only purchasable through marketplace checkout. |
| `ClientAreaPrimaryNavbar`, `ClientAreaSecondarySidebar`, `ClientAreaPage` | Adds Marketplace, Vendor Dashboard and Notifications entries, and exposes SEO meta and canonical data to templates. |
| `ClientAreaHeadOutput` | Injects the marketplace CSS/JS only on marketplace pages, plus structured data (JSON-LD). |
| `AdminAreaPage` + `\WHMCS\Module\AbstractWidget` subclass | Admin dashboard widget (pending approvals, disputes, payouts) built on WHMCS's documented widget class. |
| `ClientAdd`, `UserAdd` | Referral and affiliate attribution, and linking a pending vendor application. |
| `PreDeleteClient`, `ClientDelete`, `ClientClose` | Blocks deletion of clients with unsettled vendor balances or open disputes, or anonymises marketplace data per the privacy policy (§85). |
| `AfterClientMerge` | Re-points marketplace ownership (orders, licences, wishlists, vendor record) from the merged user/client. |
| `TicketOpen`, `TicketUserReply` | Links tickets opened from marketplace context to product/order/licence metadata. |
| `DailyCronJob`, `AfterCronJob` | Drain the job queue and run scheduled marketplace tasks (§39). |
| `EmailPreSend` | Enforces marketplace notification preferences on marketplace templates. |

### 2.4 Local API actions (verified) used through `localAPI()`

`AddOrder`, `AcceptOrder`, `OrderFraudCheck`, `CancelOrder`, `FraudOrder`, `GetOrders`,
`GetInvoice`, `UpdateInvoice`, `CreateInvoice` (vendor fees only, optional),
`AddCredit` (refund-to-credit), `AddTransaction` / `UpdateTransaction` (`refundid`),
`GetClientsDetails`, `AddClient`, `AddUser`, `CreateSsoToken`, `ValidateLogin`,
`SendEmail` (`messagename` + `customvars`), `SendAdminEmail`, `OpenTicket` (`serviceid`),
`GetCurrencies`, `GetPaymentMethods`, `GetTLDPricing`, `DomainWhois`,
`UpdateClientProduct`, `ModuleSuspend`, `ModuleUnsuspend`, `ModuleTerminate`, `LogActivity`.

All calls go through a single `WhmcsGateway` adapter (02 §5). It centralises error
handling, turns `result=error` into typed exceptions, and is the seam that tests mock.

### 2.5 Other supported mechanisms

* **Capsule** (`WHMCS\Database\Capsule`) for all DB access and schema migrations. The deprecated `select_query()` family is never used.
* **`WHMCS\Authentication\CurrentUser`** for client-area identity: `user()` gives the acting user and `client()` the selected account.
* **Custom page pattern** (`require init.php`) for `api.php` and `download.php` entry points inside the module directory (ADR-006).
* **`logModuleCall()`** for the provisioning module and **`logActivity()`** for admin-visible events. Our own channelled logs are covered in 02 §8.

## 3. Data ownership (§59)

| Owned by WHMCS (never duplicated, read through API/Capsule) | Owned by marketplace (`mod_marketplace_*`) |
|---|---|
| Clients, users, contacts, addresses, tax-exempt flag, credit balance | Vendors, vendor profiles, business/tax info, team members |
| Orders (`tblorders`), invoices, invoice items, transactions, refunds | Marketplace order + order item *overlay* (vendor, commission, licence tier, snapshots) linked by `whmcs_order_id`, `whmcs_invoice_id`, `whmcs_service_id` |
| Services (`tblhosting`) for carrier products: billing cycle, next due date, status | Subscriptions table *mirrors* status for fast queries. WHMCS is the source of truth. |
| Domains, registrars, TLD pricing | Domain aftermarket listings, offers, bids, escrow state |
| Currencies & exchange rates, tax rules, payment gateways | Commission rules, ledger, wallets, payouts, refunds (business records) |
| Tickets, departments | Ticket ↔ product/order/licence association |
| Email templates (we create ours as native templates) | Notification preferences, in-app notifications |

Customer name, email and address are **never copied** into marketplace tables. Snapshots
kept for financial integrity (product title, price and licence tier at time of purchase)
contain no personal data.

## 4. Order & billing integration strategy (ADR-002)

### 4.1 Options considered

| Option | Pros | Cons |
|--------|------|------|
| **A. `AddOrder` against carrier products, with `priceoverride`** (chosen) | Native orders list, native fraud modules (`OrderFraudCheck`), native invoice/tax/currency/gateway handling, **native recurring billing and dunning for subscriptions**, services visible under "My Services", tickets attach to `serviceid`, provisioning lifecycle drives fulfilment | One `tblhosting` row per purchased item, which WHMCS handles at scale. Needs a hidden product group and hooks to stop direct ordering. `priceoverride` does not apply to domains (we use `domainpriceoverride`). |
| B. `CreateInvoice` with free-text line items | Simple and no services created | No fraud checks (needs an order), no recurring billing, no native orders, and invoice items are untyped so WHMCS can't relate them to anything. Subscriptions would need a parallel billing engine, which §3 explicitly rejects. |
| C. One WHMCS product per marketplace product | Maximum nativeness | 10k+ products polluting WHMCS product admin, sync complexity, and vendor pricing edits would write WHMCS products |

### 4.2 Carrier products

Activation creates a hidden product group **"Marketplace (system)"** containing:

| Carrier product | Billing | Used for |
|-----------------|---------|----------|
| `Marketplace Item` | One-time (`onetime`), all currencies priced at 0 (overridden per order) | Digital goods, one-off services, website sales |
| `Marketplace Subscription` | monthly / quarterly / semiannually / annually / biennially (priced 0, overridden) | Subscription licences, support/update renewals, SaaS |
| `Marketplace Free Item` | Free | Free products, so free downloads still have an order and entitlement trail |

All three use the `marketplace` server module and are tax-flagged according to the
marketplace tax setting. Custom billing intervals beyond WHMCS cycles are **not
supported natively**. A product with a custom interval is modelled as the nearest WHMCS
cycle, or rejected at listing time. This is a documented limitation (§22).

### 4.3 Checkout sequence

```text
Customer (logged in to WHMCS)          Marketplace                           WHMCS
───────────────────────────            ───────────                           ─────
POST checkout (CSRF, cart token) ──►  Re-price every line server-side
                                      (tier price, sale window, coupon,
                                       currency) — client values ignored
                                      Validate eligibility (vendor active,
                                       product published, file scanned,
                                       compat warnings acknowledged)
                                      BEGIN TX: create mkp order (Pending,
                                       idempotency key = cart token + hash)
                                      localAPI AddOrder(pid[], billingcycle[],
                                       priceoverride[], domain…, clientip,
                                       paymentmethod, noemail per settings)  ──►  tblorders, tblhosting (Pending),
                                                                            ◄──  invoice created (tax applied natively)
                                      Store whmcs_order_id / invoice_id /
                                       service ids on order items; COMMIT
                                      localAPI OrderFraudCheck (if enabled) ──►  fraud module
                                   ◄── redirect to viewinvoice.php?id=…
Pays invoice via gateway ────────────────────────────────────────────────►  InvoicePaid hook
                                      Order → Paid; ledger: pending credit
                                      per vendor item; commission records ◄──
                                      AcceptOrder (autosetup=true)          ──►  CreateAccount on each service
                                      marketplace_CreateAccount: licence,   ◄──
                                       entitlement → item Fulfilled
                                      Order → Completed when all items
                                       fulfilled; outbox: order.paid,
                                       order.completed, license.created
```

Idempotency: `InvoicePaid` may fire more than once (manual re-marks, gateway retries),
so every handler keys on `(invoice_id, event)` in `mod_marketplace_processed_events`.
`CreateAccount` keys on `tblhosting.id`.

Guest browsing: the cart persists by an anonymous signed cookie token. On login or
registration (native WHMCS login/register pages, returning to `m=marketplace&r=/checkout`)
the cart is merged into the client's cart.

### 4.4 Coupons vs WHMCS promotions

Marketplace coupons (vendor-, product- and category-scoped, vendor-funded) cannot be
expressed as WHMCS promotions, so they are applied **before** `AddOrder` and baked into
`priceoverride`. WHMCS `promocode` is never passed. The invoice line description shows
the coupon code for transparency. This avoids double discounts and keeps discount
funding (platform vs vendor) explicit in the commission calculation (04 §5).

### 4.5 Tax (§41)

WHMCS computes tax on the invoice using its tax rules, the client's country/state and
the exemption flag. The marketplace **does not calculate tax itself**. After invoice
creation it reads per-line tax back from the invoice and stores it on order items for
reporting. Tax-inclusive pricing follows the WHMCS global setting. Commission is
calculated on the **net, tax-exclusive** amount (04 §4). For display before checkout,
estimated tax comes from WHMCS's tax rules through a read-only `TaxEstimator` that uses
the same rule table. It is shown as an estimate, and the invoice is authoritative.

### 4.6 Currency (§42)

Products are listed in the vendor's chosen WHMCS currency. Prices shown to a customer are
converted using WHMCS exchange rates (`tblcurrencies.rate`) at display and re-computed at
checkout. The order records `currency_id` and the conversion rate used. Vendor wallets
are **per currency**, and cross-currency earnings are converted into the vendor's payout
currency at the rate captured at `InvoicePaid`, stored on the ledger entry. No other
duplicate currency calculations are stored.

### 4.7 Recurring billing (§22)

Subscriptions are WHMCS services on `Marketplace Subscription`. WHMCS generates renewal
invoices, applies dunning, and suspends or terminates through our server module. On each
renewal `InvoicePaid` we post a new sale and commission for the period. Cancelling runs
through WHMCS's native cancellation request (`AddCancelRequest`). Price changes by a
vendor apply to new subscribers. Existing subscriptions keep their `recurringamount`
unless the admin runs a migration job.

## 5. Areas where WHMCS constrains the design (honest limitations)

| Requirement | Constraint | Chosen approach |
|-------------|-----------|-----------------|
| Automatic gateway refunds (§15) | **There is no refund API action.** Gateway refunds are an admin UI operation on an invoice. | The marketplace manages the refund *workflow* (request, review, approval) and ledger. Execution is either (a) **refund to account credit** automatically through `AddCredit`, or (b) the admin performs the gateway refund in WHMCS from a deep link. We reconcile through the `InvoiceRefunded`, `ManualRefund` and `AddTransaction` hooks. A future gateway-specific `RefundExecutor` can call a gateway's own API where the gateway module supports it. |
| Domain aftermarket (§4 domains) | **No API moves a domain between clients.** | Listings require DNS TXT ownership verification. Sales are escrowed: funds sit in pending until transfer completes. Transfer is by EPP/auth-code transfer order (native `domaintype=transfer`, which is automated), or an admin-assisted "move to client" when both parties are on this WHMCS. Registrations, transfers and renewals sold through the marketplace are native WHMCS domain orders. |
| Auctions (§4 domains) | No native support | Implemented in the marketplace: bids table, soft close, reserve price, and a winner invoice through a `Marketplace Item` order. Scheduled for a late phase (11). |
| Custom billing intervals (§22) | WHMCS cycles only | Map to the nearest WHMCS cycle, or reject. Documented. |
| Addon config field types | Only six simple types | Own settings system and UI (02 §9). |
| Custom API actions | Addons cannot register WHMCS API actions | Own REST front controller (06). |
| Real-time jobs | WHMCS cron is typically every 5 min | DB queue plus optional `bin/marketplace worker` under systemd/supervisor for near-real-time webhooks. The cron fallback always works. |
| DRM (§54) | Downloaded files cannot be protected once downloaded | We provide *deterrence and traceability*: signed short-lived URLs, per-customer limits, download logs, optional per-buyer watermarking of supported formats (PDF, images), and licence activation checks for software that integrates our licence API. We will not claim DRM. |

## 6. Items to verify on a live WHMCS 9 installation (before the relevant phase)

1. `priceoverride` applies per `pid[]` index when the same carrier `pid` repeats in one `AddOrder` call (community reports say yes). **Needed in Phase 3.**
2. `ShoppingCartValidateCheckout` fires for all cart flows, including the API `AddOrder` call we make. If it does, our own calls must bypass it, for example by checking a request-scoped flag. **Phase 3.**
3. Hidden product group + `ShoppingCartValidateCheckout` fully prevents direct purchase through `cart.php?a=add&pid=`. **Phase 3.**
4. `AbstractWidget` behaviour is unchanged on WHMCS 9 (the documented sample references 7.1 class docs). **Phase 7.**
5. WHMCS 8.13 compatibility of everything above. **Phase 13 (or drop 8.13).**
