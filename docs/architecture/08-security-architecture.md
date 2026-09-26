# 08 — Security Architecture

Security is designed in rather than audited in. This document is the design baseline that
the Phase 11 audit checks the implementation against.

## 1. Trust boundaries & threat model

| Actor | Trust | Primary threats |
|-------|-------|-----------------|
| Guest | none | enumeration, scraping, coupon brute force, XSS injection through search params |
| Customer | own account | accessing other customers' purchases/downloads, price or coupon manipulation, download sharing, review abuse, refund abuse |
| Vendor member | own vendor scope | cross-vendor data access, privilege escalation to admin, malicious uploads (web shells, malware, zip bombs), XSS through product content, SSRF through URLs (demo, webhook, avatar fetch), commission/payout manipulation, fake reviews |
| Marketplace staff (admin roles) | per-permission | over-privilege, insider fraud (payout redirection), which is mitigated by segregation of duties and audit |
| WordPress site | its site key scopes | stolen secret, replay, over-broad scopes |
| Vendor software in the wild | licence key only | licence brute force, response spoofing, activation-limit bypass |
| Network | untrusted | MITM (HTTPS everywhere), webhook forgery, replay |

## 2. Controls (§27)

| Control | Implementation |
|---------|----------------|
| **Input validation** | Every controller input is bound to a Request DTO with a declarative rule set (type, length, enum, regex, range, exists-and-owned). Unknown fields are rejected. `$_GET`/`$_POST` are never read outside the Request layer. |
| **SQL injection** | Capsule query builder with bound parameters only. Raw expressions are limited to a reviewed allowlist (FULLTEXT `MATCH … AGAINST (?)` with a bound param). Sort and filter column names come from allowlist maps, never user strings. A static-analysis rule flags `DB::raw`, `whereRaw` and `selectRaw` for review. |
| **XSS** | Output escaping by context (02 §7). Vendor rich text goes through an allowlist HTML sanitiser (no scripts, event handlers, `javascript:`/`data:` URLs, style or iframes, except embeds from an allowlisted video provider rendered by us). CSP on marketplace pages: `default-src 'self'; script-src 'self' 'nonce-…'; object-src 'none'; frame-ancestors 'self'; base-uri 'self'`, applied where WHMCS templates allow and at minimum on `download.php`, `api.php` and our standalone pages. JSON responses are served with `nosniff`. |
| **CSRF** | Every state-changing client-area and admin request requires a per-session CSRF token (synchronizer token, `hash_equals`) plus `SameSite=Lax` cookies. WHMCS's own admin token is also checked on admin forms. JSON endpoints require the token in the `X-CSRF-Token` header. GET never changes state. |
| **AuthN** | WHMCS sessions only (no second login system, §92–93). API authentication as in 06 §3. Sensitive vendor actions (payout request, payout method change, API key creation, team member invite) require re-authentication within 15 min, and 2FA if the WHMCS user has it enabled. |
| **AuthZ** | Central `Gate` (09) evaluated by middleware *and* re-checked inside application services (defence in depth: services take an `Actor` argument). Object-level checks use scoped repositories. `VendorProductRepository::forVendor($vendorId)` makes cross-vendor access structurally impossible, and customer repositories are always constrained by `client_id` from the session, never from input. IDs in URLs are ULIDs, and a scoped lookup miss returns 404 rather than 403 to avoid confirming existence. |
| **Mass assignment** | DTO → explicit setters. Fields like `status`, `vendor_id`, `price` after approval, `commission_*` and `is_featured` are never bindable from vendor or customer input. |
| **Price & financial integrity** | Rules 4–6: all prices re-derived server-side at checkout. `priceoverride` values come from the pricing service only. Commission, fees and ledger are computed server-side in transactions (04). Filters that touch money are re-validated. |
| **File uploads** | See 05 §4. Uploads land in private storage outside the webroot, with random keys, no execution possible (plus `.htaccess`/nginx deny as a second layer), and scanned before publication. |
| **Downloads** | See 05 §3. Signed, expiring, use-limited tokens, re-authorised at redemption, logged. |
| **Rate limiting & abuse** | 06 §5. Also covers coupon lookup, login-adjacent actions (vendor application, link consent), review posting, refund requests, licence endpoints and download issuance. |
| **SSRF** | Vendor-submitted URLs (demo, docs, video, website) are validated as `https://` or `http://` with a public hostname. They are **never fetched server-side**, except the optional link-health checker, which uses the SSRF-guarded client (06 §6.2). Video embeds are rebuilt from an extracted ID for allowlisted providers. |
| **Open redirect** | All redirects go through `SafeRedirect` (relative paths or allowlisted hosts). OAuth `redirect_uri` must match exactly. |
| **Clickjacking** | `X-Frame-Options: SAMEORIGIN` on marketplace pages, via CSP `frame-ancestors`. |
| **Session** | We rely on WHMCS session hardening. Vendor "act as vendor" context is stored server-side in the session and re-validated against `vendor_members` on every request. |
| **Headers** | `Strict-Transport-Security` recommended through the web server (health check). `Referrer-Policy: strict-origin-when-cross-origin`. `Permissions-Policy` minimal. |
| **Dependency security** | Composer and npm lockfiles, `composer audit`/`npm audit` in CI, and scoped vendor dependencies (ADR-007). |
| **Errors** | A global handler converts exceptions into generic messages plus a request id, and full detail goes only to logs (§67). `display_errors` is never relied upon. The kernel sets its own handler. |

## 3. Secrets management

* No credentials in code or the repository. Configuration is read from the WHMCS DB (encrypted), `configuration.php`-adjacent constants, or environment variables.
* API secrets and webhook secrets are shown once. The secret needed for HMAC verification is stored encrypted, not hashed, because HMAC requires the raw secret server-side, and this is documented.
* Payout provider credentials and S3 keys are encrypted. The UI never re-displays them.
* Rotation: API keys have an overlap window, the KeyRing supports multiple key versions (`kid` prefix on ciphertext), and a re-encryption job migrates data to the newest key.

## 4. Encryption (KeyRing)

* Primitive: libsodium `crypto_secretbox` (XSalsa20-Poly1305) for data at rest. `crypto_sign` (Ed25519) for licence responses. HMAC-SHA256 for lookup hashes and API signatures. BLAKE2b for fingerprints.
* Key source, in preference order:
  1. A key file at a configured path **outside the webroot** (generated at install).
  2. An environment variable.
  3. Last resort: HKDF from WHMCS `$cc_encryption_hash`. The health check shows a WARNING, because losing or rotating `configuration.php` would then lose marketplace secrets.
* Passwords: none are stored. Authentication is WHMCS's. Tokens are stored as SHA-256 hashes (high-entropy random values, so no slow hash is needed).

## 5. Privilege-escalation test matrix (§73)

Each row becomes automated security tests (Phase 11) plus a manual check.

| Scenario | Design control | Test |
|----------|----------------|------|
| Unauthenticated access to any non-public route | `RequireLogin` middleware default-deny: routes are private unless declared `public` | Crawl all routes unauthenticated and expect login redirect / 401 |
| Customer → vendor functions | `VendorScope` requires an approved `vendor_members` row | Call every vendor route as a customer and expect 403/404 |
| Vendor → admin | Admin routes only reachable in the WHMCS admin context (`adminid` session) and RBAC | Call admin routes with a client session and expect a WHMCS admin login |
| Vendor A → vendor B data | Scoped repositories, ULIDs, scope from session | Swap every ID param for another vendor's and expect 404 |
| Customer A → customer B downloads, licences, orders | `client_id` scoping, token bound to client, re-authorisation at redemption | Swap IDs and replay B's download token from A's session |
| Expired licence bypass | Server-side validation, signed responses, `valid_until` | Tamper expiry client-side and expect a signature failure |
| Download URL reuse | `max_uses`, TTL, optional IP bind, re-authorisation | Reuse after expiry or uses, from another IP, after refund |
| Coupon manipulation | Server evaluation, locks for limits | Race the last use, cross-vendor coupon, altered discount field ignored |
| Price manipulation | Server re-pricing, `priceoverride` from service only | Post altered price/tier/currency and expect server price |
| Commission manipulation | No bindable commission fields, versioned rules, filter validation | Vendor posts commission fields and they are ignored. Filter returning negative is clamped and logged. |
| Payout manipulation | Wallet-bounded amount, vendor from session, method cooling-off, re-auth | Over-withdraw, concurrent requests, other vendor's method ID, fresh method |
| CSRF | Tokens on every state change | Every POST without or with a wrong token gives 419/403 |
| Stored XSS via product/review/profile | Sanitiser + escaping + CSP | Payload corpus (OWASP XSS cheat sheet) in every text field, rendered in all contexts |
| SQLi | Bound params, allowlisted sort/filter | sqlmap-style payload corpus on every parameter |
| File upload | 05 §4 | Polyglots, double extensions, zip-slip, zip bomb, EICAR test file, oversized, MIME mismatch |
| API abuse | Signatures, nonces, rate limits, scopes | Replay, clock skew, missing scope, bursts |
| Webhook forgery | HMAC + timestamp + event-id dedupe | Unsigned, stale or replayed deliveries to the WP receiver |

## 6. Audit logging (§28)

* `AuditLogger::record(actor, action, subject, before, after)` is called by application services, never by controllers, so API, CLI and UI paths are all covered.
* Actions logged: login-to-marketplace context switches, vendor registration and status changes, product submission, approval, rejection and update, version approval, orders and status changes, refunds, payouts, commission rule changes, wallet adjustments, licence create, activate, revoke and transfer, admin RBAC changes, settings changes, API key lifecycle, webhook endpoint changes, privacy exports and erasures, and bulk actions (one entry per affected subject plus a batch id).
* Rows are append-only and hash-chained (prev_hash → entry_hash). `audit:verify` checks the chain. Before and after values are redacted of secrets and minimised of PII.
* The admin UI filters by actor, action, subject and date. Export requires the `logs.export` permission.

## 7. Fraud controls

* WHMCS fraud module result (`OrderFraudCheck`) plus marketplace rules: velocity (orders, refunds and downloads per client), many accounts per IP or device, first-order high value, and self-purchase (a vendor buying their own product to farm reviews, which blocks reviews and flags commission).
* Actions: `flag` (admin review), `hold` (payout hold on the vendor wallet or delayed fulfilment), `block`.
* Review abuse: verified-purchase requirement (by default), one review per client per product, vote-ring detection (same IP/device voting across reviews) and an abuse score feeding the moderation queue.

## 8. Privacy (§85)

* Vendors see only the customer's **display name, country and order data** needed to support them. Customer email is shown only when the admin enables "share buyer email with vendor" (off by default). Otherwise vendor↔buyer contact goes through marketplace messages or tickets.
* Data export: a customer can request a JSON/ZIP of their marketplace data (orders, licences, reviews, wishlist, downloads log). This complements WHMCS's own client data.
* Erasure: marketplace personal data is anonymised (reviews reassigned to "Former customer", IPs nulled after retention). Financial records are retained per the configured legal retention period, anonymised where the law allows. Clients with open disputes or unsettled vendor balances can't be erased until resolved.
* Retention settings: downloads log, security events, API nonces, webhook deliveries, carts and IP addresses, each purged by cron.

## 9. Health-check security items (§98)

Items checked, with PASS, WARNING or ERROR plus remediation text:

* storage and log directories not web-accessible (active HTTP probe of a canary file)
* key file outside the webroot
* HTTPS on WHMCS system URL
* `display_errors` off
* append-only triggers installed
* API keys older than N days
* webhook endpoints using HTTP
* admin accounts holding `super_admin` without 2FA
* ClamAV reachable if scanning is enabled
* cron running within the expected interval
