# 07 — WordPress Plugin Architecture (`whmcs-marketplace`)

Target: WordPress 6.4+ (block API v3, `block.json` `render`), PHP 8.1+ (WordPress sites
often lag WHMCS hosts, so this is one version lower than WHMCS 9's minimum). Single-site
and Multisite. Elementor 3.x is optional. **The plugin never connects to the WHMCS
database** (ADR-010).

## 1. Layout

```text
whmcs-marketplace/
├── whmcs-marketplace.php         header, requirements check, bootstrap
├── uninstall.php                 removes options/transients/user meta (not WHMCS data)
├── src/  (namespace WhmcsMarketplace\WP\)
│   ├── Plugin.php                service wiring, hooks registration
│   ├── Admin/                    Settings pages (site + network), Connection test, Cache tools,
│   │                             Appearance (design tokens), Health panel
│   ├── Api/
│   │   ├── Client.php            wp_remote_request + HMAC signing, retries, timeouts, ETag
│   │   ├── Signer.php            same canonical string as 06 §3
│   │   ├── Resources/            Products, Categories, Vendors, Search, Reviews, Cart, Me…
│   │   └── Errors.php            maps API error codes → WP_Error with translated messages
│   ├── Cache/
│   │   ├── Cache.php             object cache (wp_cache_*) if persistent, else transients
│   │   └── Generations.php       per-resource generation counters for O(1) invalidation
│   ├── Rest/                     /wp-json/whmcs-marketplace/v1/…  (browser-facing proxy)
│   │   ├── WebhookController.php receives + verifies webhooks → invalidation
│   │   ├── CartController.php    cart ops (nonce-protected) → API
│   │   └── AccountController.php link/unlink, token refresh, downloads proxy
│   ├── Auth/AccountBridge.php    OAuth-style link flow + encrypted token storage
│   ├── Frontend/
│   │   ├── Router.php            rewrite rules for virtual pages
│   │   ├── Templates.php         theme-overridable template loader
│   │   ├── Seo.php               titles, meta, canonical, OG/Twitter, JSON-LD, sitemap provider
│   │   └── Assets.php            conditional enqueue, CSS variables from settings
│   ├── Shortcodes/               one class per shortcode
│   ├── Blocks/                   block registration (block.json per block)
│   ├── Elementor/                widgets (loaded only if Elementor active)
│   └── Widgets/                  classic WP_Widget wrappers
├── blocks/<name>/block.json, render.php, edit.js (built), style.css
├── templates/                    product/single.php, product/card.php, vendor/profile.php,
│                                 vendor/card.php, archive.php, search.php, cart.php, account/*.php
├── assets/ (build output)        css/marketplace.css (tokens + components), js/*.js
├── languages/whmcs-marketplace.pot
└── src-js/                       block editor sources (@wordpress/scripts build)
```

## 2. Configuration

| Setting | Notes |
|---------|-------|
| WHMCS API base URL, Key ID, Secret | The secret is stored encrypted with sodium using a key derived from `AUTH_KEY`+`SECURE_AUTH_SALT` (HKDF). It is never output to the page, and the field shows "set / replace". A `WHMCS_MARKETPLACE_SECRET` constant in `wp-config.php` overrides the DB value, which is recommended. |
| Webhook secret | Same handling. The webhook URL to paste into WHMCS is displayed. |
| Cache TTLs | products 600 s, categories 1800 s, vendors 900 s, search 120 s, reviews 600 s, stats 900 s (§35). All configurable. |
| Base slug | `/marketplace/` by default. Product `/marketplace/product/{slug}/`, vendor `/marketplace/vendor/{slug}/`, category `/marketplace/category/{slug}/`. Rewrites are flushed on change. |
| Appearance | Colours, radius, font family, button style, card style, grid columns, dark-mode behaviour (auto, light, dark), card template choice. Output as CSS custom properties, so no plugin files need editing (§61). |
| Checkout mode | "Redirect to WHMCS checkout (SSO)" is the only mode in v1 (payment always in WHMCS). |
| Multisite | Network settings (API connection shared or per-site) and a per-site override toggle. Network admin can clear caches across sites. |

## 3. API client & caching (§35)

```text
get(resource, params):
  key = "mkp:" . resource . ":" . gen(resource_group) . ":" . sha1(params + locale + currency)
  if cached = cache.get(key): return cached
  if stale = cache.get(key.":stale") within stale-while-revalidate window:
       schedule async refresh (wp_schedule_single_event) ; return stale
  resp = client.get(…, If-None-Match: stored etag)
  on 304 → refresh TTL ; on 200 → cache.set(key, data, ttl) + stale copy (ttl×6)
  on error → return stale copy if any (graceful degradation) + admin notice counter
```

* **Invalidation:** each resource group (`products`, `product:{uuid}`, `vendor:{slug}`, `categories`, `collections`, `search`) has a generation counter. Bumping the counter invalidates every derived key in O(1), with no transient scans. Webhooks map to bumps: `product.*` → `product:{uuid}`, `products`, `search`, `collections`. `vendor.*` → `vendor:{slug}`. `review.created` → `product:{uuid}` reviews. `cache.purge` → everything.
* Manual clearing is available from the admin toolbar and settings, plus `wp whmcs-marketplace cache clear` (WP-CLI).
* A **stampede guard** (a short lock via `wp_cache_add`) allows only one PHP worker to refresh a key at a time.
* Personalised responses (cart, account) are **never cached** in shared caches, and `nocache_headers()` is sent on those pages.

## 4. Frontend rendering

* **Shortcodes (§32):** `[marketplace]` (full app shell: home, archive, search), `[marketplace_products category="" type="" tag="" vendor="" limit="12" columns="4" sort="" template=""]`, `[marketplace_product id|slug]`, `[marketplace_categories]`, `[marketplace_vendors]`, `[marketplace_vendor id|slug]`, `[marketplace_search]`, `[marketplace_featured]`, `[marketplace_latest]`, `[marketplace_popular]`, `[marketplace_reviews product=""]`, `[marketplace_cart]`, `[marketplace_account]`. Attributes are sanitised against an allowlist.
* **Gutenberg blocks (§33):** dynamic, server-rendered blocks (`render.php`) sharing the renderers used by shortcodes. The editor uses `ServerSideRender` previews plus InspectorControls. Blocks are Marketplace Products (grid and carousel variations), Featured, Latest, Popular, Product Categories, Vendor Directory, Vendor Profile, Search, Filters, Cart, Customer Dashboard, Product Reviews and Product Details.
* **Elementor (§34):** widgets wrapping the same renderers, with Elementor controls mapped to renderer args. They are registered only when `elementor/widgets/register` fires.
* **Classic widgets:** Search, Categories, Featured Products.
* **Virtual pages:** rewrite rules route to plugin templates. Themes override them by copying to `yourtheme/whmcs-marketplace/…`, the same pattern as WooCommerce. Filters include `whmcs_marketplace_template_path`, `…_product_card_html` and `…_api_response`.
* **Performance:** CSS and JS are enqueued only on pages that use marketplace output. Images use `loading="lazy"` with width and height (CLS), `srcset` from CDN derivatives, and carousels are native CSS scroll-snap with minimal JS.
* **Accessibility:** semantic landmarks, focus-visible styles, ARIA for carousel and filters, live regions for cart updates, labelled form controls, and colour contrast validated for the default palette.

## 5. SEO (§37)

* Product, vendor and category virtual pages set `<title>`, meta description, canonical (the WP URL is canonical, and WHMCS pages point here), Open Graph and Twitter card tags.
* JSON-LD: `Product` (+`Offer`, `AggregateRating`, `Review`), `BreadcrumbList`, `Organization`/`Person` for vendors (the vendor schema is emitted only for verified vendors). Output is suppressed when Yoast or Rank Math is active and our integration filters feed their graph instead.
* Sitemaps: a WP core sitemap provider (`wp_sitemaps_add_provider`) pages through `/v1/seo/sitemap`. Yoast and Rank Math sitemap integrations use their filters.
* No products are indexed in the WP DB, which avoids sync drift. `noindex` is set on search, cart and account.

## 6. Account bridge (§94): no WHMCS passwords in WordPress

```text
WP user clicks "Connect marketplace account"
 → WP creates state + PKCE verifier (user meta, 10 min), redirects browser to
   WHMCS index.php?m=marketplace&r=/connect&site=<key_id>&state=&code_challenge=&redirect_uri=
 → user logs into WHMCS normally (2FA etc. handled by WHMCS), sees consent screen
   ("<Site name> wants to: view orders, download purchases, …") and approves
 → WHMCS stores link_codes row (one-time, 60 s) → redirects to registered redirect_uri?code=&state=
 → WP verifies state, server-to-server POST /v1/oauth/token (site-signed, code + verifier)
 → receives access/refresh tokens bound to (site key, WHMCS user, client account)
 → tokens stored encrypted in user meta; WP user ↔ WHMCS user link recorded
```

* The `redirect_uri` must exactly match the one registered on the site key.
* Unlinking on either side revokes the token family. WHMCS password changes, and the `UserChangePassword` hook, revoke that user's tokens.
* Checkout: `POST /v1/checkout/session` → `CreateSsoToken` (`sso:custom_redirect` → `index.php?m=marketplace&r=/checkout&cart=…`) → the browser is redirected into WHMCS already logged in. A customer without a WHMCS account is sent to WHMCS registration with the cart token carried over. **WordPress never creates WHMCS clients with passwords.**
* Downloads: WP calls `POST /v1/me/downloads/{file}/token` and redirects the browser to the returned WHMCS `download.php` URL. The file never passes through WP.

## 7. Security in the plugin

* Every state-changing browser request to our WP REST routes checks a nonce and a capability or user link. `permission_callback` is never `__return_true` except for the webhook route, which verifies the signature itself.
* All output is escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` for API-provided HTML, which WHMCS has already sanitised: defence in depth).
* The API secret, user tokens and webhook secret are never exposed to JavaScript. The browser only talks to WP REST, which talks to WHMCS.
* The webhook receiver verifies the HMAC with `hash_equals`, enforces a ±300 s timestamp window, and dedupes on event id through a 24 h transient set. It responds 2xx fast and does invalidation inline (cheap).
* WordPress Coding Standards (PHPCS) and Plugin Check run in CI.

## 8. Multisite (§95)

Network activation is supported. Network settings live in `site_option`, and per-site
settings in `option`, with an "inherit network connection" toggle. Caches are keyed per
blog id. Webhooks sent to a network-level URL fan out invalidation to all sites that use
the shared connection.
