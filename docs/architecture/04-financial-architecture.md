# 04 — Financial Architecture

Financial correctness is the highest-risk area. Everything here is server-side, runs in DB
transactions, is idempotent, and gets automated tests before any UI is built on top of it
(Phase 4 exit criteria).

## 1. Money (ADR-003)

* `Money` is an immutable value object holding `int $minor4` (amount × 10⁴) and `Currency`. The range is ±9.2×10¹⁴ currency units, far above any realistic order.
* It is constructed only from decimal **strings** (`Money::of('19.99', $usd)`) or from DB DECIMAL strings. `float` input throws.
* Arithmetic: `plus`, `minus`, `multipliedBy(Rate)`, `allocate(ratios)`. `Rate` is a fixed-point decimal (percentages up to 4 dp).
* Rounding happens only at **settlement points** (commission, fee, tax readback). Settlement precision is the currency's minor unit, 2 dp by default and configurable per WHMCS currency. Rounding mode is `HALF_UP` by default and configurable to `HALF_EVEN`.
* **Remainder rule:** when an amount is split, one side is computed and rounded, and the other side is `total − rounded side`. So `commission + fee + vendor_net + tax_withheld == net_amount` holds exactly, always. This invariant is asserted in code and in property-based tests.
* Mixed-currency arithmetic throws. Conversion is explicit (`$fx->convert($money, $to, $rate)`), and the rate used is recorded.

## 2. Commission engine (§9)

### 2.1 Rule resolution

For an order item, rules are resolved at **`InvoicePaid` time**. The rule snapshot is
stored on the item, and later rule edits never change historical commissions.

Precedence, highest first (first active match wins, ties broken by `priority`):

```text
promotion (active window, scoped to vendor/product/category)
  → product
  → vendor
  → category (deepest category on the product's primary path wins)
  → global
```

The admin can set an optional "floor/ceiling" guard globally, which is applied after the
rule: `min_commission`, `max_commission`.

### 2.2 Methods

| Method | Commission |
|--------|-----------|
| percentage | `round(net × pct)` |
| fixed | `fixed` (converted to order currency at order FX rate) × quantity |
| mixed | `round(net × pct) + fixed` |
| tiered | pct/fixed from the tier matching the vendor's basis metric (`lifetime_sales` or `trailing_30d_sales`, in default currency, computed from `stats_daily` *before* this sale) |

Then clamp `commission` to `[min_commission, min(max_commission, net)]`.

### 2.3 Full item calculation (pure function, `CommissionCalculator::calculate`)

```text
gross            = unit_price × qty                     (server price, never client)
discount         = coupon discount allocated to this line (largest-remainder allocation)
  discount_vendor   = discount × vendor_share            (vendor-funded part)
  discount_platform = discount − discount_vendor
net              = gross − discount                     (tax-exclusive; tax is WHMCS's)
commission_base  = setting: net  |  gross − discount_vendor  (default: net; platform-funded
                   discounts reduce the platform's take when base = gross − discount_vendor)
commission       = rule(commission_base), clamped
processing_fee   = fee_policy: {bearer: vendor|platform|split, pct, fixed} applied to the
                   item's share of the invoice total (incl. tax) — the vendor's share only
tax_withheld     = optional withholding (setting, e.g. marketplace-facilitator regimes),
                   default 0
vendor_net       = net − commission − vendor_fee_share − tax_withheld   (remainder rule)
invariant:  commission + vendor_fee_share + tax_withheld + vendor_net == net
            vendor_net ≥ 0 (else: commission is reduced and a health warning is raised)
```

`breakdown` JSON on `commissions` records every input and intermediate, so any figure can be
explained to a vendor or an auditor.

Filter `marketplace_filter_commission` can adjust the result. The adjusted result is
re-checked against the invariant and clamped, and the filter name is recorded in `breakdown`.

## 3. Ledger & wallets (§10, ADR-004)

### 3.1 Buckets

Each vendor has one wallet **per currency** with four buckets:

* **pending**: earned, still inside the clearance period (default = refund window + N days, configurable).
* **available**: withdrawable.
* **reserved**: locked by a payout request.
* **paid**: cumulative, informational.

### 3.2 Entry types

| Type | Movement | Trigger |
|------|----------|---------|
| `sale` | +pending (vendor_net) | InvoicePaid (per item) |
| `release` | pending → available | cron, when `release_at ≤ now` and no open refund/dispute on the item |
| `refund_debit` | −pending or −available (−available may go negative → debt) | refund completed |
| `commission_reversal` | informational pair with refund_debit (records commission returned to vendor) | refund completed |
| `hold` / `unhold` | available ↔ pending (with reason) | dispute opened/closed, fraud flag |
| `payout_reserve` | available → reserved | payout requested |
| `payout_cancel` | reserved → available | payout rejected/failed/cancelled |
| `payout_paid` | reserved → paid | payout completed |
| `adjustment_credit` / `adjustment_debit` | ±available | admin, with mandatory reason + `finance.adjust` permission + audit |
| `chargeback` | −available (may go negative) | admin marks invoice charged back |

### 3.3 Posting algorithm (`LedgerService::post`)

```text
BEGIN
  SELECT … FROM wallets WHERE vendor_id=? AND currency_id=? FOR UPDATE   (create if missing)
  if exists wallet_transactions.idempotency_key = key → COMMIT, return existing (idempotent)
  compute new bucket values; enforce rules (e.g. reserve ≤ available; no negative reserved)
  entry_hash = SHA-256(prev_hash ‖ canonical_json(entry fields))
  INSERT wallet_transactions (… prev_hash = wallet.last_entry_hash, entry_hash …)
  UPDATE wallets SET buckets…, last_entry_id, last_entry_hash, version = version+1
  INSERT outbox (wallet.updated / payout.* events)
COMMIT
```

* Idempotency keys are deterministic, for example `sale:{order_item_id}`, `release:{order_item_id}`, `refund:{refund_id}:{order_item_id}`, `payout_reserve:{payout_id}`. A replayed hook can never double-post.
* No code path issues `UPDATE`/`DELETE` on `wallet_transactions`. The repository class exposes only `append()`. Where privileges allow, DB triggers enforce this too (03 migration 0016).
* `ledger:verify` (nightly and on demand) recomputes each wallet from its entries, validates the hash chain, and raises an ERROR health event on any mismatch.
* Negative available balance (vendor debt after a refund of already-paid funds) is allowed and shown clearly. Future sales net it off before payouts are allowed.

## 4. Payouts (§11)

### 4.1 Flow

```text
Vendor requests (or scheduler creates) ──► validation:
   vendor approved & not suspended · payout method active & verified · amount ≥ threshold
   · amount ≤ available · no negative balance in any wallet · 2FA/recent-auth for vendor
   member with finance role · rate limit
 → LedgerService: payout_reserve  → payout status requested
Admin approves (payouts.approve) or auto-approve rule (trusted vendors, ≤ limit)
 → status approved → job ProcessPayout
ProcessPayout: provider = PayoutProviderRegistry.get(method.provider_code)
   provider.send(PayoutRequest{id, idempotency_key, amount, currency, destination}) 
   ├─ synchronous success → completed → ledger payout_paid
   ├─ pending (async)     → processing; provider webhook/poll → completed|failed
   └─ failure             → failed → ledger payout_cancel (funds return to available)
```

* The **manual provider** (bank transfer, or anything handled outside the system) marks the payout processing. The admin enters a reference and confirms completion. Built-in providers in the first release are *Manual/Bank*, *PayPal Payouts* and *Stripe Connect transfers*. *Wise*, *Payoneer* and *Crypto* ship as documented adapters behind the same interface, in a later phase (11 §3).
* The provider idempotency key is `payout.uuid`, so a retry after a timeout cannot double-pay.
* Schedules (weekly, bi-weekly, monthly on day N) create a `payout_batch` of eligible vendors. The batch is admin-reviewed unless auto-approve is on.
* A "Payout to WHMCS account credit" option is available through `AddCredit`, useful for vendors who also buy hosting.

### 4.2 Guards against payout manipulation

Amounts come from the wallet, never from the request body except the requested amount,
which is bounded by `available`. The vendor cannot choose another vendor's wallet (scope
comes from the session vendor). Payout method changes trigger an email notification and a
configurable cooling-off period (default 72 h) before the new method can receive funds.
Every state change goes to `payout_events` and the audit log.

## 5. Coupons (§21)

`CouponService::evaluate(cart, client)` is a pure function over server data:

1. Normalise code (trim, upper-case). Look it up, and **rate-limit failed lookups** per client and IP (anti-enumeration).
2. Check active window, `usage_limit` (with `SELECT … FOR UPDATE` on the coupon at checkout), `per_customer_limit` (counting `coupon_usage` + open orders), `first_order_only` (no paid marketplace orders for the client), `min_purchase` (on eligible lines), and scope (vendor/product/category eligibility per line).
3. Compute the discount over eligible lines: percentage, or fixed (converted from coupon currency at order FX). Cap at `max_discount` and at the eligible subtotal. Allocate across lines by largest remainder so the parts sum exactly.
4. The result is attached to lines with `funded_by` / `vendor_share_pct`. The commission engine uses it (§2.3).

Usage is recorded in `coupon_usage` at `InvoicePaid` (UQ(coupon_id, order_id)). A cancelled
unpaid order releases nothing because nothing was consumed. Vendors can create only
`vendor`-scoped coupons for their own products, always 100 % vendor-funded.

## 6. Tax (§41)

Tax is calculated by WHMCS on the invoice (01 §4.5). The marketplace stores per-item
`tax_amount` from the invoice (allocated by line) for reporting and never pays tax
through to vendors. Vendor earnings are based on tax-exclusive net. Optional
`tax_withheld` supports platforms that must withhold, and defaults to off. The platform
does not give tax advice. The settings UI says so and links to the admin's own
configuration.

## 7. Refunds (§15)

### 7.1 States

```text
requested → under_review → approved → processing → completed
         ↘ cancelled (by buyer)     ↘ rejected
```

### 7.2 Eligibility (server-side)

Eligibility requires all of the following:

* The order item is paid and within `eligible_until`, which is `paid_at` + the refund period. The period comes from the product type, else the global setting.
* The item is not already fully refunded.
* The requested amount is ≤ `net + tax − refunded`.
* The configured download policy holds (e.g. "no refund once downloaded" is off by default, and the policy is shown on the product page).

### 7.3 Decision

* The vendor accepts or contests within N days. Silence counts as accept if the setting allows.
* An admin approves when the vendor contests, the amount exceeds a threshold, or the policy requires it.
* Automatic approval rules (e.g. within 24 h, not downloaded, amount ≤ X) are evaluated by a `RefundRuleEngine`. Each auto-approval is audited, including which rule fired.

### 7.4 Execution (WHMCS has no refund API, 01 §5)

* `account_credit`: `AddCredit` to the client, then complete immediately.
* `gateway_manual`: the refund enters `processing` and the admin sees a deep link to the WHMCS invoice's Refund tab. When the admin refunds there, the `InvoiceRefunded`, `ManualRefund` and `AddTransaction` (`amountout`) hooks match the transaction to the open refund (invoice id + amount, unique pending match) and complete it. Unmatched WHMCS refunds on marketplace invoices create a `refund` record in `completed` state automatically, with `admin_id` of the refunding admin. This keeps the ledger consistent even if someone refunds outside the workflow.
* `gateway_api` (future): the `RefundExecutor` interface for gateways whose modules support programmatic refunds.

### 7.5 Financial effect on completion (single transaction)

```text
ratio            = refund_amount_ex_tax / item.net
vendor_debit     = round(item.vendor_net × ratio)
commission_back  = policy: reverse proportionally (default) | platform keeps commission
fee_retained     = policy: processing fee not refunded by gateway → borne per fee_policy
ledger:  refund_debit(vendor_debit) from pending if still pending, else available
commission_adjustments: reversal row
item.refunded_amount += amount; order.payment_status → partially_refunded | refunded
licence/entitlement: revoke on full refund (configurable: suspend instead)
```

## 8. Disputes (financial side)

Opening a dispute posts `hold` for the item's `vendor_net` (bounded by what is still
pending or available), and resolution posts `unhold` plus a refund where applicable.
Dispute messages are append-only. Admin internal notes are never visible to buyer or vendor.

## 9. Required automated tests (Phase 4 exit criteria)

| Area | Tests |
|------|-------|
| Money | parsing/formatting per currency, no floats, rounding modes, allocate sums exactly |
| Commission | each method, precedence resolution, clamps, tiers at boundaries, vendor-funded vs platform-funded discounts, fee bearer variants, invariant property test (10k random cases) |
| Coupons | every eligibility rule, limits under concurrency (two checkouts race for last use), cross-currency fixed coupon, allocation |
| Ledger | idempotent re-post, hash chain, concurrent postings (two processes), negative balance handling, verify detects tampering |
| Payouts | threshold, reserve/cancel/paid transitions, double-submit, provider timeout retry = no double pay |
| Refunds | full, partial, multiple partials up to cap, refund after release, after payout (debt), commission policies, reconciliation of out-of-band WHMCS refund |
| Tax | stored tax equals WHMCS invoice tax per line, inclusive vs exclusive settings |
