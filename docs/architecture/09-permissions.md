# 09 — Roles & Permissions

There are three principal types, each with its own authorisation source:

| Principal | Identified by | Authorisation |
|-----------|---------------|---------------|
| **Staff** | WHMCS admin (`$_SESSION['adminid']`) | WHMCS addon access control (outer gate: which admin role groups may open the module) **and** marketplace RBAC (`admin_roles` → `role_permissions`) |
| **Vendor member** | WHMCS user acting within the vendor's client account | `vendor_members.role` → vendor permission set (+ per-member overrides). The vendor must be `approved` for all write actions. `suspended` gives read-only access plus payouts frozen, and `banned` gives no access. |
| **Customer** | WHMCS user + selected client account | Implicit ownership rules. Everything is scoped by `client_id`. WHMCS's own user↔client permissions are respected: a user without the "orders" permission on a client account cannot see that account's marketplace orders, and one without "invoices" cannot pay. |

## 1. Staff permissions

Grouped catalogue (seeded, extensible by modules through `marketplace_register_permissions`):

```text
marketplace.view  marketplace.manage
dashboard.view    reports.view      reports.export
vendors.view      vendors.approve   vendors.suspend    vendors.ban     vendors.verify   vendors.edit
products.view     products.create   products.edit      products.approve products.suspend products.delete
products.bulk     categories.manage collections.manage product_types.manage
orders.view       orders.manage
refunds.view      refunds.manage    refunds.approve_over_threshold
disputes.view     disputes.manage
reviews.view      reviews.moderate
licenses.view     licenses.manage   licenses.revoke
coupons.view      coupons.manage
commissions.view  commissions.manage
wallets.view      finance.adjust
payouts.view      payouts.manage    payouts.approve    payouts.process
customers.view    customers.privacy
ip_complaints.manage   moderation.reports
api.manage        webhooks.manage   integrations.manage
settings.view     settings.manage   security.manage    roles.manage
logs.view         logs.export       health.view        backups.manage   import_export.manage
```

## 2. Default staff roles

| Role | Grants (summary) |
|------|------------------|
| Super Administrator | all (including `roles.manage`, `security.manage`) |
| Marketplace Administrator | all except `roles.manage`, `security.manage`, `finance.adjust` |
| Marketplace Manager | view all, plus vendors.*, products.*, categories, collections, orders.manage, reviews.moderate, coupons.manage, reports |
| Product Moderator | products.view/approve/suspend, moderation.reports, reviews.moderate, ip_complaints.manage, vendors.view |
| Finance Manager | orders.view, refunds.*, commissions.*, wallets.view, finance.adjust, payouts.*, reports.* |
| Support Manager | customers.view, orders.view, disputes.*, refunds.view/manage (under threshold), licenses.view/manage, reviews.view |

**Segregation of duties** (configurable, on by default):

* A payout above threshold X requires an approver different from the admin who edited the vendor's payout method.
* `finance.adjust` entries above threshold require a second admin's confirmation.
* An admin cannot approve their own vendor account if they are also a vendor.

## 3. Vendor member roles

| Permission | owner | manager | editor | support | finance |
|------------|:-----:|:-------:|:------:|:-------:|:-------:|
| vendor.profile.edit | ✓ | ✓ | | | |
| vendor.products.view | ✓ | ✓ | ✓ | ✓ | ✓ |
| vendor.products.create/edit/submit | ✓ | ✓ | ✓ | | |
| vendor.versions.upload | ✓ | ✓ | ✓ | | |
| vendor.orders.view | ✓ | ✓ | | ✓ | ✓ |
| vendor.customers.contact | ✓ | ✓ | | ✓ | |
| vendor.reviews.respond | ✓ | ✓ | | ✓ | |
| vendor.refunds.decide | ✓ | ✓ | | ✓ | |
| vendor.disputes.respond | ✓ | ✓ | | ✓ | |
| vendor.licenses.manage | ✓ | ✓ | | ✓ | |
| vendor.coupons.manage | ✓ | ✓ | | | |
| vendor.analytics.view | ✓ | ✓ | ✓ | | ✓ |
| vendor.earnings.view | ✓ | ✓ | | | ✓ |
| vendor.payouts.request | ✓ | | | | ✓ |
| vendor.payout_methods.manage | ✓ | | | | |
| vendor.team.manage | ✓ | | | | |
| vendor.api_keys.manage | ✓ | ✓ | | | |

## 4. Evaluation

```php
$gate->authorize($actor, 'products.approve');                 // staff permission
$gate->authorize($actor, 'vendor.payouts.request', $vendor);  // member of $vendor with perm
$gate->authorize($actor, 'order.view', $order);               // policy: owner client or
                                                              // vendor of an item (redacted view)
```

* Policies (object-level) live beside permissions: `OrderPolicy`, `LicensePolicy`, `ProductPolicy`, `ReviewPolicy`, `RefundPolicy`, `DisputePolicy`, `PayoutPolicy`.
* Filter `marketplace_filter_vendor_permissions` may *remove* permissions, and may add them only from the documented catalogue. Adding staff permissions to vendors is refused by the validator.
* Every denial is logged to `security_events` (sampled for noisy routes).
* Permission checks are cached per request only. Changes take effect on the next request, and role changes are audited.
