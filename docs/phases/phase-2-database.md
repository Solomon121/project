# Phase 2 — Database: Report

Status: **complete and tested on MariaDB 10.11 with PHP 8.4.** Not yet run on MySQL 8.0 or
PHP 8.2/8.3 (CI is configured for them but has not run yet), and not yet run inside a real
WHMCS installation (see §7).

Working assumption: after Phase 1 the instruction was "continue", so the Phase 1
recommendations for decisions D1–D8 are being used as defaults
(11-implementation-plan.md §4). Nothing in Phase 2 depends on D1–D8 except the carrier
product approach (D1), and Phase 2 creates no WHMCS products yet.

## 1. What was implemented

* **Persistence layer:** `DatabaseContext`, with a WHMCS implementation using the documented `WHMCS\Database\Capsule` and a standalone implementation for the CLI outside WHMCS and for tests.
* **Migration framework:**
  * `Migration` base class: conventions, length-safe identifier names, money, hash and IP column helpers, cross-migration FK helpers.
  * `Migrator`: ordered discovery, batches, rollback by batch, full reset, and a named DB lock.
  * Failure cleanup: a failed migration is removed via its own `down()`, then the error is re-thrown.
  * Strict creation: a migration refuses to start if any table it owns already exists unrecorded.
  * Financial guard: rollback is refused while financial tables contain rows, unless forced.
* **16 migrations** creating **99 tables** plus the migrations table: 127 foreign keys, 10 named CHECK constraints, 1 FULLTEXT index and 18 append-only triggers.
* **Append-only enforcement** (`AppendOnlyPolicy`, migration 0016):
  * Financial, evidence and audit tables block UPDATE and DELETE. High-volume logs block UPDATE only, so retention jobs can still delete old rows.
  * If the DB user lacks the TRIGGER privilege, installation still succeeds. It records the degraded state in `settings` and writes a WARNING health event, never a silent pass.
* **Default data** (config files plus insert-only, idempotent, transactional seeders):
  * 59 staff permissions, 6 staff roles and their grants, and 5 vendor member roles.
  * 62 product types and 9 licence types.
  * The default category tree: 10 top-level categories and 22 subcategories, with materialised paths.
  * 76 settings: 75 schema defaults, plus the trigger-state flag written by migration 0016.
  * The default 20 % global commission rule, 22 notification-event → email-template mappings, and 7 legal page placeholders, explicitly marked "not legal advice" and unpublished.
* **Upgrade-safe seeding:** administrator edits are never overwritten. Removed grants are not re-granted and deleted categories are not recreated. A permission added in a later release is granted to its default roles once.
* **`SchemaVerifier`:** runtime integrity check producing PASS / WARNING / ERROR, with remediation text for each item. It checks pending migrations, missing or unexpected tables, InnoDB, utf8mb4, FKs that point outside the marketplace, and missing triggers.
* **WHMCS addon entry points** (`marketplace.php`):
  * `_config`, `_activate` (PHP version check, install, errors returned to WHMCS and logged with `logActivity`), and `_upgrade`.
  * `_deactivate` keeps data by default. Removal needs the "Remove data" option, and removing financial data additionally needs the typed confirmation `DELETE MARKETPLACE DATA`.
  * `_output` is a read-only admin status page (schema health and migrations, all output escaped), plus `_sidebar`.
* **CLI** `bin/marketplace`: `install`, `migrate`, `migrate:status`, `migrate:rollback [--steps=N] [--force-destroy-financial-data]`, `db:seed`, `schema:verify` (non-zero exit on ERROR). Inside WHMCS it boots `init.php`; outside, it uses `MKP_STANDALONE=1` + `MKP_DB_*`.
* **CI workflow:** PHPUnit on PHP 8.2/8.3/8.4 × MySQL 8.0 / MariaDB 10.6 / MariaDB 10.11, plus the restricted-privilege scenario.

## 2. Files created/modified

```text
composer.json, composer.lock, phpunit.xml.dist, .gitignore, .github/workflows/ci.yml
README.md (status + development instructions)
docs/architecture/03-database-schema.md (implementation notes, §13 differences, §14 invariants)
docs/architecture/11-implementation-plan.md (phase status, decision defaults)
docs/phases/phase-2-database.md (this report)
whmcs/modules/addons/marketplace/
  marketplace.php, bootstrap.php, bin/marketplace
  config/{permissions,product_types,license_types,categories,settings,email_events,legal_pages}.php
  migrations/0001 … 0016
  src/Application/Installer.php
  src/Kernel/{ModuleInfo,Html}.php
  src/Infrastructure/Persistence/{DatabaseContext,WhmcsDatabaseContext,StandaloneDatabaseContext,Table}.php
  src/Infrastructure/Persistence/Schema/{Migration,Migrator,MigrationException,IdentifierName,AppendOnlyPolicy,SchemaCheck,SchemaVerifier}.php
  src/Infrastructure/Persistence/Seeding/{Seeder,DatabaseSeeder,RolesAndPermissionsSeeder,CatalogSeeder,ConfigurationSeeder}.php
  storage/.htaccess, logs/.htaccess (deny-all, defence in depth)
tests/bootstrap.php, tests/Support/{WhmcsRuntimeStubs,DatabaseTestCase}.php
tests/Unit/{IdentifierNameTest,SeedConfigTest}.php
tests/Integration/ModuleEntryPointsTest.php
tests/Integration/Database/{MigratorTest,SchemaIntegrityTest,SeederTest,RestrictedPrivilegesTest}.php
```

## 3. Database changes

All tables use the `mod_marketplace_` prefix: InnoDB, utf8mb4, DATETIME(6) UTC and DECIMAL(19,4) money.
Full definitions are in the migrations. Deliberate differences from the Phase 1 draft are in
03-database-schema.md §13. The main ones:

* Hashes are CHAR(64) hex and IPs are VARCHAR(45), for portability across the schema builders WHMCS may bundle.
* The `settings.key` column is renamed `settings.name` (`KEY` is reserved in MySQL).
* Domain bids are immutable, and the winning bid is recorded on the listing.

## 4. API changes

None. The REST API is Phase 8.

## 5. Security considerations

* **Immutability:** the ledger (`wallet_transactions`), commissions, their adjustments, payout and refund event trails, dispute messages, domain bids and the audit log reject UPDATE and DELETE at the database level. This was verified by tests and manually (`ERROR 1644 … append-only`). Duplicate ledger postings are rejected by UNIQUE(`idempotency_key`).
* **Financial invariants in the database:** CHECK constraints (03 §14) mean even a bug or a manual SQL edit cannot write a commission split that doesn't sum to net, a negative price, a negative pending/reserved balance, or a payout whose net ≠ amount − fee.
* **No FKs into WHMCS core tables** (verified by test), so WHMCS client deletes and merges are never blocked or cascaded by marketplace tables. Financial FKs never cascade deletes (verified by test).
* **Uninstall cannot destroy financial records by accident:** rollback, reset and deactivate refuse while financial tables hold rows, unless forced. Deactivation needs an explicit option plus a typed confirmation, and keeps data by default.
* **Installs never destroy unknown data:** a pre-existing unrecorded table blocks the migration and is reported, never dropped (a test covers this). This fixed a real defect found during testing: the first version of the failure cleanup would have dropped such a table.
* **Secrets:** secret settings are created unset, flagged `is_encrypted`, and have no defaults (checked by test). Encryption itself (KeyRing) is Phase 3.
* **Admin output:** everything is escaped (`Html::e`). The status page is read-only, so it needs no CSRF handling in this phase.
* **Storage and logs:** directories ship with deny-all `.htaccess`. Moving them outside the webroot is checked by the Phase 7 health check.
* **Errors:** activation and upgrade failures go to the WHMCS activity log with the exception message only (no traces). The CLI prints class + message and exits non-zero. No exception is swallowed. The only handled database error is a missing TRIGGER privilege (MySQL/MariaDB errors 1142, 1227, 1419), which is recorded and reported.

## 6. Testing performed

| Suite | Result |
|-------|--------|
| PHPUnit (unit + integration), MariaDB 10.11, PHP 8.4.19 | **51 tests, 1,902 assertions, all passing**, including the opt-in restricted-privilege test run against a DB user without TRIGGER privilege |
| Schema-builder compatibility | Full install + reset with **illuminate/database 7.30.6, 8.83.27 and 11.x**. The resulting schemas (1,705 column/index/FK/CHECK/trigger facts) are **byte-identical** across all three versions. |
| Manual CLI | `install` twice (second run: 0 changes), `schema:verify` all PASS, rollback refused with financial data present, forced rollback and reinstall |
| `php -l` | All PHP files clean |

What the tests cover:

* Batches and rollback by batch, including a cross-migration FK being removed.
* Idempotency, the financial rollback guard, failure cleanup, strict creation and lock contention.
* Every CHECK constraint and append-only trigger.
* Uniqueness rules, key query-path indexes, the FULLTEXT index and utf8mb4 round-trips.
* The verifier detecting drift.
* Seeder counts, category paths, idempotency, preservation of admin changes, new-permission upgrades and atomicity.
* The WHMCS entry points: activate, failure reporting, upgrade, deactivate (keep / refuse / remove) and the admin page.

## 7. Remaining issues

1. **Not run inside a real WHMCS yet.** The entry points are tested against stubs of `WHMCS\Database\Capsule` and `logActivity()`. Two WHMCS behaviours are **UNVERIFIED**:
   * whether WHMCS keeps the module active when `_deactivate` returns `status => error`;
   * whether `tbladdonmodules` rows still exist when `_deactivate` runs.

   The typed-confirmation removal depends on the second point. If WHMCS deletes settings first, the removal option will move to the marketplace admin UI (Phase 7). A licensed WHMCS dev install (decision D6) is still needed.
2. **MySQL 8.0 and PHP 8.2/8.3 are only covered by the CI workflow**, which has not run yet. MySQL 8 with binary logging needs `log_bin_trust_function_creators` or SUPER for triggers. The degraded path handles it (error 1419), but on MySQL it has only been exercised by the MariaDB privilege test.
3. `bin/marketplace` inside WHMCS relies on `init.php` working in CLI mode (WHMCS's own cron does this). **UNVERIFIED** on WHMCS 9.
4. Carrier products and WHMCS email templates are **not** created yet. They write to WHMCS tables through `localAPI`, so they belong in Phase 3 with the `WhmcsGateway`.
5. Language files, the setup wizard and the full admin console are later phases (3 and 7).

## 8. Next phase

**Phase 3 — Marketplace core:**

* Kernel, router, middleware (CSRF, auth, vendor scope, permissions), DI container, typed settings service and KeyRing encryption.
* `WhmcsGateway`, carrier products and the server-module skeleton.
* Categories, product types, products, tiers and media.
* Cart, checkout via `AddOrder` with server-side pricing, and orders.
* Wishlist, follows and reviews.
* The client-area storefront.
