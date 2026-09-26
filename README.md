# WHMCS Multi-Vendor Digital Marketplace

A multi-vendor digital marketplace for WHMCS (addon + provisioning module) with a companion
WordPress plugin that renders the public storefront through a secure, versioned REST API.

**Current status:** Phase 1 (architecture) ✅ · Phase 2 (database) ✅ · next: Phase 3 (marketplace core).
See [`docs/phases/`](docs/phases/) for per-phase reports.

* Architecture: [`docs/architecture/`](docs/architecture/README.md)
* Implementation plan and open decisions: [`docs/architecture/11-implementation-plan.md`](docs/architecture/11-implementation-plan.md)

Planned source layout:

```text
whmcs/modules/addons/marketplace/    WHMCS addon module (core, admin, client area, API)
whmcs/modules/servers/marketplace/   WHMCS provisioning module for carrier products
wordpress/whmcs-marketplace/         WordPress plugin
openapi/                             OpenAPI 3.1 specification
sdk/                                 Vendor licence SDKs
tests/                               Unit, integration, security, performance suites
docs/                                Architecture, admin/vendor/customer/developer guides
```

`project.zip` is an unrelated pre-existing upload (a Python LSTM stock-prediction project)
and is not part of this marketplace.

## Development

Requirements: PHP 8.2+, Composer, MySQL 8 / MariaDB 10.6+.

```bash
composer install
# Integration tests need a disposable database (every mod_marketplace_* table in it is dropped):
MKP_TEST_DB_DATABASE=mkp_test MKP_TEST_DB_USERNAME=mkp MKP_TEST_DB_PASSWORD=mkp vendor/bin/phpunit

# Module CLI outside WHMCS (inside WHMCS it uses init.php automatically):
MKP_STANDALONE=1 MKP_DB_DATABASE=mkp_dev MKP_DB_USERNAME=... MKP_DB_PASSWORD=... \
  whmcs/modules/addons/marketplace/bin/marketplace install
whmcs/modules/addons/marketplace/bin/marketplace schema:verify
```

