# 05 — Licensing, Downloads, Versions & Storage

## 1. Licence engine (§6)

### 1.1 Key generation

* Format: `MKP-XXXXX-XXXXX-XXXXX-XXXXX` (Crockford base32, 100 bits from `random_bytes`, plus a 5-bit checksum group that catches typos before any DB lookup). The format is overridable per `LicenseProvider`.
* Storage: `key_hash = HMAC-SHA256(licence_pepper, normalised_key)` for lookup, and `key_enc` (sodium secretbox) so the owner can view the key. The pepper lives in the KeyRing, not the DB. A DB dump alone therefore neither reveals keys nor allows offline guessing.
* Provider architecture (10 §3.3): the built-in `InternalLicenseProvider`, with optional adapters for external licensing systems.

### 1.2 State machine

```text
pending ──(fulfilment)──► active ◄──► inactive (owner-deactivated / never activated)
  active ──(expires_at passed, cron)──► expired ──(renewal)──► active
  active ──(overdue subscription / admin / dispute)──► suspended ──► active
  any ──(refund, chargeback, admin, IP complaint upheld)──► revoked   (terminal)
  any ──(abuse detected / blacklist)──► blocked                     (admin-only reversal)
```

Every transition writes `license_events` and the audit log, and emits `license.*` events.

### 1.3 Activation rules

* An activation is identified by `instance_hash = SHA-256(product_id ‖ normalised_domain ‖ install_id)`.
* Domain normalisation: lower-case, IDN→punycode, strip `www.`, strip port and path. `localhost`, `*.test`, `*.local`, `*.localhost`, private IPs and configured staging patterns (e.g. `staging.*`, `dev.*`) do **not** count toward limits when the product allows dev activations. They are flagged `environment=staging|local`.
* Limits: `max_activations` (instances) and `max_domains` (distinct registrable domains, via the Public Suffix List) are checked **under a row lock on the licence**, so concurrent activations cannot exceed the limit.
* IP restrictions: optional CIDR allowlist per licence.
* Blacklist: key, domain, IP or fingerprint. A hit gives `blocked` plus a security event.
* Self-service deactivation by the owner from the dashboard frees a slot. A deactivation cooldown (configurable) prevents slot-cycling abuse.
* Transfers: the owner requests, an admin approves (optional transfer fee order), a new licence row is created with `parent_license_id`, the old one is revoked with reason `transferred`, and activations are reset.

### 1.4 Licence API (for vendors' software)

Public endpoints (06 §4.4):

* `POST /v1/licenses/activate`
* `POST /v1/licenses/validate`
* `POST /v1/licenses/deactivate`
* `GET /v1/licenses/{key}/updates` (update check)

Every request carries `license_key`, `product_id` (public uuid), `instance` (domain, install id, software version) and an optional client nonce.

* **Response signing:** every licence response includes `signature` = Ed25519 over the canonical JSON `{license_status, product, instance_hash, expires_at, issued_at, nonce}`. Vendors embed the marketplace's **public key** (downloadable per product in the vendor dashboard) and verify the signature. This stops trivial response spoofing by a fake local server. We document that client-side checks in distributed code can be patched out by a determined user, and that this is deterrence, not DRM (§54).
* **Offline grace:** the response includes `valid_until` (e.g. 7 days) so vendors can cache a signed response.
* **Rate limits:** per licence key and per IP, with lockout on repeated invalid keys (enumeration defence).
* **SDKs:** a reference PHP client (≈150 lines, no dependencies) and a WordPress plugin/theme updater drop-in are shipped in `/sdk` for vendors.

### 1.5 Expiry & renewals

A cron task `licenses:expire` (hourly) moves due licences to `expired`, handles
subscription-linked licences through the server-module lifecycle, and queues
`license.expiring` notifications at 30, 7 and 1 days (configurable).

## 2. Entitlements (§24)

An entitlement answers two questions: "may this client download files of this product?"
and "which versions?".

```text
may_download(file) :=
    entitlement.status = active
  ∧ licence (if any) ∉ {revoked, blocked}        (expired licence: see policy below)
  ∧ file.version.status = published ∧ file.scan_status ∈ {clean, skipped-by-admin}
  ∧ ( file.version.released_at ≤ entitlement.updates_expires_at        ← update entitlement
      ∨ file.version_id = order_item.version_id_at_purchase            ← always keep what you bought
      ∨ policy.previous_versions_allowed ∧ version ≤ last entitled version )
  ∧ downloads_used < max_downloads (if set)
  ∧ per-client rate limit ok
```

When update entitlement lapses, the customer keeps access to every version released
before the lapse date and is offered a "Renew updates & support" purchase. That purchase
is a `support_extensions` order through a carrier product.

## 3. Secure download system (§7, §54)

### 3.1 Token issuance

1. The customer clicks "Download" in the dashboard, or a WordPress user does so through the API (the WP proxy never sees file paths).
2. `DownloadService::issue(client, user, file)` checks `may_download`, then creates a `download_tokens` row:
   * `token = random_bytes(32)`, stored as a SHA-256 hash.
   * TTL is configurable (default 10 min).
   * `max_uses` is configurable (default 3, allowing interrupted-download resume).
   * The token is optionally bound to the requester IP.
3. It returns `download.php?t=<base64url token>`, a signed, expiring URL with no path information.

### 3.2 Redemption (`download.php`)

1. Look up the token by hash and check expiry, `use_count < max_uses` and the IP binding. `use_count` is incremented atomically (`UPDATE … WHERE use_count < max_uses`).
2. **Re-run `may_download`**, because a licence might have been revoked between issue and redemption.
3. Apply suspicious-activity detection: more than N distinct IPs or countries per entitlement per day, or more than M downloads per hour, gives a flag, a security event and a configurable action (warn, throttle, suspend entitlement and alert admin).
4. Log to `downloads` (IP, truncated user agent, bytes, result).
5. Deliver:
   * **local disk:** `X-Sendfile` (Apache), `X-Accel-Redirect` (Nginx internal location) or a PHP chunked stream with `Range` support. `Content-Disposition: attachment` with a sanitised filename, `X-Content-Type-Options: nosniff` and `Cache-Control: private, no-store`.
   * **S3-compatible disk:** 302 to a presigned GET URL with a TTL of 60 s and `response-content-disposition` forced. The bucket is private, and **private files are never on a public CDN** (§83).
6. Increment `product_stats.downloads` (async) and `entitlement.downloads_used`.

### 3.3 Watermarking (optional, per product)

For PDFs and images, a per-buyer watermark (e.g. "Licensed to {client id hash} – {order}")
is applied on the fly and cached per buyer. It is off by default and never applied to
archives, executables or code. The product page states whether watermarking applies.

## 4. Uploads & file validation

| Check | Detail |
|-------|--------|
| Size | Per product type `max_file_mb` and global max. Chunked resumable upload (5 MB chunks, tus-style protocol over our own endpoint) for large files. |
| Extension allowlist | Per product type (e.g. theme: zip; ebook: pdf, epub; software: zip, 7z, exe, msi, dmg, apk, ipa, tar.gz). Double extensions (`x.php.zip` is fine, `x.zip.php` is rejected) are handled by checking the *final* extension and rejecting any server-executable extension anywhere. |
| MIME sniffing | `finfo` magic detection must match the declared extension family. Mismatch means rejection. |
| Archive inspection | ZIP/TAR: entry count limit, total uncompressed size limit (zip-bomb defence), compression-ratio limit, no absolute paths or `..` (zip-slip), flagged if containing `.htaccess` or web shells heuristically (reported to the moderator, not auto-rejected, because themes legitimately contain PHP). RAR/7z: size and magic checks only, unless the admin enables an external inspector. |
| Hash | SHA-256 computed on upload, stored and shown to buyers (integrity), and re-verified on a sampling basis by cron. |
| Malware scan | The `ScannerInterface` implementations are ClamAV (clamd socket) or Null. Files are `pending` until scanned, and non-`clean` files cannot be published or downloaded unless an admin explicitly overrides, which is audited. |
| Images | Re-encoded through GD/Imagick (strips EXIF/GPS and polyglot payloads), max dimensions enforced, and WebP/AVIF derivatives generated for the CDN. |
| Storage key | Random 128-bit path segments. Original name is metadata only and never used as a path. |

## 5. Version management (§23)

* Versions are semver-validated, with sortable columns (`v_major`, `v_minor`, `v_patch`, `v_pre`). A new version must be greater than the current published one.
* Submitting a version creates a `pending_review` version (review is configurable: always, only for new vendors, or never for trusted vendors). Approval publishes it and sets `products.current_version_id`.
* `is_mandatory` / `is_security` flags drive notification priority and a dashboard badge.
* On publish, outbox `product.updated` + `ProductVersionPublished` → notify entitled customers (respecting preferences), wishlist users with `notify_updates`, and vendor followers.
* Previous versions stay downloadable per §2. Withdrawn versions (security issue) are hidden from download but their files are retained for audit.

## 6. Storage abstraction (§82, §83)

```php
interface StorageDisk {
    public function put(string $key, StreamInterface $contents, array $meta = []): void;
    public function readStream(string $key): StreamInterface;
    public function delete(string $key): void;
    public function exists(string $key): bool;
    public function size(string $key): int;
    public function temporaryUrl(string $key, \DateInterval $ttl, array $headers = []): ?string; // null if unsupported
    public function publicUrl(string $key): ?string;   // only for public disks (media)
    public function serveNatively(string $key): ?DeliveryInstruction; // X-Sendfile etc.
}
```

* Disks: `private` (product files, documents, attachments) and `public` (media). Each is configured independently as `local` or `s3` (endpoint, region, bucket and path-style for R2, Spaces, B2 or MinIO).
* The S3 driver is our own SigV4 signer and client (small and scoped) to avoid pulling the full AWS SDK into WHMCS. It supports multipart upload for large files.
* CDN: `public` disk media URLs can be rewritten to a CDN base URL. Private disk objects never get CDN URLs.
* A migration tool (`storage:migrate --from=local --to=s3`) copies, verifies SHA-256 and flips the pointer per file.
