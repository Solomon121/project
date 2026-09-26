# WHMCS Multi-Vendor Digital Marketplace

A multi-vendor digital marketplace for WHMCS (addon + provisioning module) with a companion
WordPress plugin that renders the public storefront through a secure, versioned REST API.

**Current status: Phase 1 (architecture), awaiting review.** No production code yet.

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
