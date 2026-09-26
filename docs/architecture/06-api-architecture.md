# 06 — REST API & Webhooks

## 1. Endpoint base

`https://<whmcs>/modules/addons/marketplace/api.php/v1/…`
(PATH_INFO). If the host strips PATH_INFO, use `api.php?route=/v1/…`. The optional
rewrite `https://<whmcs>/marketplace-api/v1/…` is provided as snippets. The base URL is
shown in the admin API page and verified by the health check.

## 2. Conventions

* JSON only (`Content-Type: application/json; charset=utf-8`). Request bodies are limited to 1 MB, except uploads, which use a dedicated chunk endpoint.
* **Envelope:**

```json
{ "success": true,  "data": { … } | [ … ], "meta": { "request_id": "01J…", "pagination": { … } } }
{ "success": false, "error": { "code": "PRODUCT_NOT_FOUND", "message": "The requested product could not be found.",
                                "details": { "field_errors": { "price": ["must be ≥ 0"] } } },
  "meta": { "request_id": "01J…" } }
```

* Messages are translated using `Accept-Language` when a translation exists. `code` is stable and documented, and clients must branch on `code`, never on `message`.
* HTTP status codes follow the error class: 400 validation, 401 auth, 403 authz, 404, 409 conflict/idempotency mismatch, 422 business rule, 429 rate limit, 5xx. 5xx bodies contain only `INTERNAL_ERROR` and the request id. Details go to `api.log`.
* **Pagination:** `?page[size]=24` (max 100). Catalogue endpoints use `page[number]` (offset). Large collections (orders, ledger, downloads) use `page[after]=<opaque cursor>`. `meta.pagination` contains `{size, number|next_cursor, total?}`, and `total` is omitted where counting is expensive.
* **Filtering / sorting:** `?filter[category]=wordpress&filter[price_min]=0&filter[price_max]=50&filter[rating_min]=4&filter[type]=wordpress_theme&filter[license]=commercial&filter[free]=1&filter[featured]=1&filter[tag]=woocommerce&filter[vendor]=acme&sort=-rating` against an allowlist per endpoint. Unknown filters give 400 (no silent ignore).
* **Sparse fields / includes:** `?include=vendor,tiers,media&fields[product]=title,slug,price`, allowlisted.
* **Idempotency:** `Idempotency-Key` header required on POST endpoints that create orders, refunds or payouts. The stored response is replayed for 24 h, and a different body with the same key gives 409.
* **Versioning:** `/v1` in the path. Breaking changes mean `/v2`. Additive changes are allowed in v1. Deprecations are announced through the `Deprecation` and `Sunset` headers.
* **Caching:** public catalogue GETs send `ETag` and `Cache-Control: public, max-age=60`. Conditional GET is supported, and the WP plugin uses it.

## 3. Authentication

| Scheme | Who | How |
|--------|-----|-----|
| **Site key (HMAC)** | WordPress sites, server-to-server integrations | Headers `X-MKP-Key: <key_id>`, `X-MKP-Timestamp: <unix>`, `X-MKP-Nonce: <16B b64>`, `X-MKP-Signature: v1=<hex HMAC-SHA256(secret, method \n path \n sorted_query \n timestamp \n nonce \n sha256(body))>`. Timestamp window ±300 s, and the nonce is unique per key within the window (`api_nonces`). The secret never travels. |
| **User token (Bearer)** | A WordPress (or other) site acting *for* a linked customer or vendor | Issued through the account-link flow (07 §6). `Authorization: Bearer <access>` **plus** a valid site signature from the issuing site. Access TTL 15 min, refresh 30 days rotating (reuse of an old refresh token revokes the family). Scopes: `account:read`, `orders:read`, `downloads:issue`, `licenses:read`, `licenses:manage`, `reviews:write`, `cart:write`, `wishlist:write`, `vendor:read`, `vendor:write`. |
| **Licence** | Vendor software in the field | `license_key` in the body. No site key. Heavily rate-limited, with signed responses (05 §1.4). |
| **Admin integration key** | Admin-created keys for ERP/BI | HMAC like site keys, with admin-granted scopes such as `admin:orders:read` and `admin:reports:read`. Never granted payout or settings write scopes through the API in v1. |

Keys can be restricted to an IP allowlist, can expire, and are revocable. The UI shows a
key's secret **once** at creation. Rotation creates a new secret with a configurable
overlap window.

## 4. Endpoint catalogue (v1)

Auth column: **S** site key, **U** user token (+site), **L** licence, **P** public (still needs a site key unless the admin enables anonymous catalogue read).

### 4.1 Catalogue

| Method & path | Auth | Notes |
|---------------|------|-------|
| GET `/v1/products` | S | Filters, sorts and pagination per §2. Returns published products only. |
| GET `/v1/products/{slug-or-uuid}` | S | Includes tiers, media, current version, changelog excerpt, rating summary and vendor summary |
| GET `/v1/products/{id}/versions` | S | Published versions and changelogs |
| GET `/v1/products/{id}/reviews` | S | Approved reviews, paginated |
| GET `/v1/products/{id}/related` | S | |
| GET `/v1/categories` · `/v1/categories/{slug}` | S | Tree (`?tree=1`) or flat, with counts |
| GET `/v1/collections` · `/v1/collections/{slug}` | S | |
| GET `/v1/vendors` · `/v1/vendors/{slug}` · `/v1/vendors/{slug}/products` · `/v1/vendors/{slug}/reviews` | S | Public profile fields only |
| GET `/v1/search?q=` | S | Same filters as products, plus a relevance score. `/v1/search/suggest?q=` for autocomplete |
| GET `/v1/tags?type=technology` | S | |
| GET `/v1/stats/public` | S | Homepage statistics (cached) |
| GET `/v1/seo/sitemap?page=` | S | Slugs + lastmod, for the WP sitemap provider |
| GET `/v1/config/public` | S | Currencies, default currency, legal page URLs, feature flags |

### 4.2 Customer (acting for a linked user)

| Method & path | Auth |
|---------------|------|
| GET/PUT/DELETE `/v1/cart`, POST `/v1/cart/items`, PATCH/DELETE `/v1/cart/items/{id}`, POST `/v1/cart/coupon` | S (anonymous cart token) or U |
| POST `/v1/checkout/session` returns a WHMCS SSO URL that lands on marketplace checkout with the cart attached (payment happens in WHMCS) | U `cart:write` |
| GET `/v1/me` · `/v1/me/orders` · `/v1/me/orders/{uuid}` | U |
| GET `/v1/me/downloads` · POST `/v1/me/downloads/{file}/token` (returns a short-lived download URL) | U `downloads:issue` |
| GET `/v1/me/licenses` · POST `/v1/me/licenses/{uuid}/activations/{id}/deactivate` | U |
| GET/POST/DELETE `/v1/me/wishlist` · `/v1/me/following` | U |
| POST `/v1/products/{id}/reviews` · POST `/v1/reviews/{id}/votes` · POST `/v1/reviews/{id}/reports` | U `reviews:write` |
| GET/POST `/v1/me/refunds` · GET/POST `/v1/me/disputes` · POST `/v1/me/disputes/{id}/messages` | U |
| GET/PATCH `/v1/me/notifications` · GET/PUT `/v1/me/notification-preferences` | U |

### 4.3 Vendor (acting for a vendor member)

GET/POST/PATCH `/v1/vendor/products`, versions, files (chunked `POST /v1/uploads` → `PUT /v1/uploads/{id}/chunks/{n}` → `POST /v1/uploads/{id}/complete`), `/v1/vendor/orders` (customer PII minimised, see 08 §8), `/v1/vendor/earnings`, `/v1/vendor/wallets`, `/v1/vendor/ledger` (cursor), POST `/v1/vendor/payouts`, `/v1/vendor/coupons`, `/v1/vendor/reviews/{id}/response`, `/v1/vendor/analytics?from=&to=&metric=`, `/v1/vendor/refunds/{id}/decision`. **Vendor scope always comes from the token's vendor membership and is never taken from a path or body parameter.**

### 4.4 Licence (public)

`POST /v1/licenses/activate` · `POST /v1/licenses/validate` · `POST /v1/licenses/deactivate` · `GET /v1/licenses/updates?product=&version=` (returns a signed download token URL if entitled).

### 4.5 Account link (07 §6)

`POST /v1/oauth/token` (S): exchanges a one-time link code + PKCE verifier for user tokens (`grant_type=authorization_code`), or rotates a refresh token (`grant_type=refresh_token`). `POST /v1/oauth/revoke` (S): revokes a token family.

### 4.6 Webhook management

`GET/POST/PATCH/DELETE /v1/webhooks` (S, own endpoints only) · `POST /v1/webhooks/{id}/test` · `GET /v1/webhooks/{id}/deliveries`.

### 4.7 Error codes (initial catalogue)

`VALIDATION_FAILED`, `UNAUTHENTICATED`, `SIGNATURE_INVALID`, `TIMESTAMP_OUT_OF_WINDOW`,
`NONCE_REUSED`, `TOKEN_EXPIRED`, `SCOPE_REQUIRED`, `FORBIDDEN`, `NOT_FOUND`,
`PRODUCT_NOT_FOUND`, `VENDOR_NOT_FOUND`, `PRODUCT_NOT_PURCHASABLE`, `CART_EMPTY`,
`CART_ITEM_UNAVAILABLE`, `COUPON_INVALID`, `COUPON_EXPIRED`, `COUPON_LIMIT_REACHED`,
`CURRENCY_MISMATCH`, `LICENSE_INVALID`, `LICENSE_EXPIRED`, `LICENSE_SUSPENDED`,
`LICENSE_REVOKED`, `LICENSE_BLOCKED`, `ACTIVATION_LIMIT_REACHED`, `DOMAIN_LIMIT_REACHED`,
`DOWNLOAD_NOT_ENTITLED`, `DOWNLOAD_LIMIT_REACHED`, `REFUND_NOT_ELIGIBLE`,
`REFUND_AMOUNT_EXCEEDS`, `PAYOUT_BELOW_THRESHOLD`, `PAYOUT_INSUFFICIENT_FUNDS`,
`PAYOUT_METHOD_COOLING_OFF`, `IDEMPOTENCY_CONFLICT`, `RATE_LIMITED`, `UPLOAD_REJECTED`,
`FILE_TYPE_NOT_ALLOWED`, `MARKETPLACE_DISABLED`, `INTERNAL_ERROR`.

## 5. Rate limiting

A token bucket keyed by `(scheme, key_id | token | license_hash | ip, route_group)`. The
backend is APCu or Redis when available, with a DB fallback. Defaults: site key 600/min,
user token 120/min, licence validate 30/min per key and 60/min per IP, login-like actions
10/min. Responses carry `RateLimit-Limit`, `RateLimit-Remaining` and `RateLimit-Reset`,
and a 429 includes `Retry-After`. Breaches are logged to `security_events`, and repeated
breaches can escalate to a temporary key block.

## 6. Webhooks (§62, §63)

### 6.1 Events

`product.created`, `product.updated`, `product.approved`, `product.deleted`,
`vendor.created`, `vendor.approved`, `order.created`, `order.paid`, `order.completed`,
`order.refunded`, `license.created`, `license.activated`, `license.expired`,
`payout.created`, `payout.completed`, `review.created`. Additional events:
`category.updated`, `collection.updated`, `cache.purge`.

### 6.2 Delivery

```http
POST <endpoint url>
Content-Type: application/json
User-Agent: WHMCS-Marketplace-Webhooks/1.0
X-MKP-Event: product.updated
X-MKP-Delivery: 01J…            (delivery id)
X-MKP-Event-Id: 01J…            (stable across retries → receiver dedupe)
X-MKP-Timestamp: 1790000000
X-MKP-Signature: v1=<hex HMAC-SHA256(endpoint_secret, timestamp + "." + raw_body)>

{ "id": "01J…", "event": "product.updated", "created_at": "2026-09-26T12:00:00Z",
  "data": { "product": { "uuid": "…", "slug": "…", "status": "published" } } }
```

* Payloads are *thin*: identifiers, status and changed fields, with no PII. Receivers fetch details through the API when needed. This minimises data leakage if an endpoint is compromised.
* Receivers must verify the signature, reject timestamps older than 5 min and dedupe on `X-MKP-Event-Id` (replay protection). The WP plugin does all three.
* Retries: exponential backoff (1 m, 5 m, 30 m, 2 h, 6 h, 12 h, 24 h), then `dead`. Endpoints with 20 consecutive failures are auto-disabled and the admin is notified. Manual retry is available in the UI and CLI.
* Outbound HTTP uses the SSRF guard: HTTPS only (unless the admin allows HTTP for dev), no redirects to private or link-local ranges, DNS resolved and pinned per request, and 5 s connect / 10 s total timeouts.
* Every attempt is logged in `webhook_deliveries` (status, duration, response code, 1 KB excerpt) and in `webhook.log`.

## 7. OpenAPI (§96)

`/openapi/marketplace.v1.yaml` (OpenAPI 3.1) is maintained alongside the controllers. A CI
check validates it and runs contract tests that make every documented example parse
against the live response schemas in the integration suite. The admin "API" page renders
it with a vendored Redoc/Swagger bundle (no CDN).
