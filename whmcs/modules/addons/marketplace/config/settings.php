<?php

declare(strict_types=1);

/**
 * Settings schema (master prompt §69, docs/architecture/02 §9).
 *
 * Each entry: type (bool|int|decimal|string|enum|list|secret), default, and for
 * enums the allowed values. Secrets are stored encrypted and have no default.
 * The seeder writes defaults for settings that do not exist yet; it never
 * overwrites an administrator's value.
 *
 * Durations are in the unit named by the key suffix (_days, _minutes, _seconds, _mb).
 */
return [
    // General
    'general.marketplace_enabled' => ['type' => 'bool', 'default' => false],
    'general.maintenance_message' => ['type' => 'string', 'default' => ''],
    'general.setup_wizard_completed' => ['type' => 'bool', 'default' => false],

    // Vendors
    'vendors.registration_enabled' => ['type' => 'bool', 'default' => true],
    'vendors.require_approval' => ['type' => 'bool', 'default' => true],
    'vendors.require_documents' => ['type' => 'bool', 'default' => false],
    'vendors.share_buyer_email' => ['type' => 'bool', 'default' => false],

    // Products
    'products.require_approval' => ['type' => 'enum', 'default' => 'always', 'values' => ['always', 'new_vendors', 'never']],
    'products.version_review' => ['type' => 'enum', 'default' => 'always', 'values' => ['always', 'untrusted_vendors', 'never']],
    'products.global_max_file_mb' => ['type' => 'int', 'default' => 2048],

    // Commission (default global rule is seeded into commission_rules as well)
    'commission.default_percentage' => ['type' => 'decimal', 'default' => '20.0000'],
    'commission.base' => ['type' => 'enum', 'default' => 'net', 'values' => ['net', 'gross_minus_vendor_discount']],
    'commission.min_amount' => ['type' => 'decimal', 'default' => null],
    'commission.max_amount' => ['type' => 'decimal', 'default' => null],
    'commission.processing_fee_bearer' => ['type' => 'enum', 'default' => 'vendor', 'values' => ['vendor', 'platform', 'split']],
    'commission.rounding_mode' => ['type' => 'enum', 'default' => 'half_up', 'values' => ['half_up', 'half_even']],

    // Wallet & payouts
    'payouts.clearance_days' => ['type' => 'int', 'default' => 14],
    'payouts.minimum_amount' => ['type' => 'decimal', 'default' => '50.0000'],
    'payouts.schedule' => ['type' => 'enum', 'default' => 'manual', 'values' => ['manual', 'weekly', 'biweekly', 'monthly']],
    'payouts.schedule_day' => ['type' => 'int', 'default' => 1],
    'payouts.require_admin_approval' => ['type' => 'bool', 'default' => true],
    'payouts.method_cooling_off_hours' => ['type' => 'int', 'default' => 72],
    'payouts.enabled_providers' => ['type' => 'list', 'default' => ['manual']],

    // Refunds
    'refunds.enabled' => ['type' => 'bool', 'default' => true],
    'refunds.period_days' => ['type' => 'int', 'default' => 14],
    'refunds.execution_default' => ['type' => 'enum', 'default' => 'gateway_manual', 'values' => ['gateway_manual', 'account_credit']],
    'refunds.auto_credit_threshold' => ['type' => 'decimal', 'default' => null],
    'refunds.vendor_response_days' => ['type' => 'int', 'default' => 3],
    'refunds.commission_policy' => ['type' => 'enum', 'default' => 'reverse_proportional', 'values' => ['reverse_proportional', 'platform_keeps']],
    'refunds.on_full_refund' => ['type' => 'enum', 'default' => 'revoke_license', 'values' => ['revoke_license', 'suspend_license']],

    // Reviews
    'reviews.require_moderation' => ['type' => 'bool', 'default' => true],
    'reviews.verified_purchase_only' => ['type' => 'bool', 'default' => true],

    // Downloads
    'downloads.token_ttl_minutes' => ['type' => 'int', 'default' => 10],
    'downloads.token_max_uses' => ['type' => 'int', 'default' => 3],
    'downloads.bind_ip' => ['type' => 'bool', 'default' => false],
    'downloads.max_per_hour' => ['type' => 'int', 'default' => 20],
    'downloads.delivery' => ['type' => 'enum', 'default' => 'php_stream', 'values' => ['php_stream', 'x_sendfile', 'x_accel_redirect']],

    // Licensing
    'licenses.expiry_notice_days' => ['type' => 'list', 'default' => [30, 7, 1]],
    'licenses.offline_grace_days' => ['type' => 'int', 'default' => 7],
    'licenses.deactivation_cooldown_hours' => ['type' => 'int', 'default' => 24],
    'licenses.dev_domain_patterns' => ['type' => 'list', 'default' => ['localhost', '*.test', '*.local', '*.localhost', 'staging.*', 'dev.*']],

    // API & WordPress
    'api.enabled' => ['type' => 'bool', 'default' => true],
    'api.anonymous_catalog_read' => ['type' => 'bool', 'default' => false],
    'api.timestamp_window_seconds' => ['type' => 'int', 'default' => 300],
    'api.default_rate_limit_per_minute' => ['type' => 'int', 'default' => 600],
    'wordpress.canonical_base_url' => ['type' => 'string', 'default' => ''],

    // Email & notifications
    'email.enabled' => ['type' => 'bool', 'default' => true],
    'notifications.retention_days' => ['type' => 'int', 'default' => 180],

    // Security
    'security.reauth_minutes' => ['type' => 'int', 'default' => 15],
    'security.two_person_payout_threshold' => ['type' => 'decimal', 'default' => null],
    'security.malware_scanner' => ['type' => 'enum', 'default' => 'none', 'values' => ['none', 'clamav']],
    'security.clamav_socket' => ['type' => 'string', 'default' => '/var/run/clamav/clamd.ctl'],

    // Storage
    'storage.private_disk' => ['type' => 'enum', 'default' => 'local', 'values' => ['local', 's3']],
    'storage.public_disk' => ['type' => 'enum', 'default' => 'local', 'values' => ['local', 's3']],
    'storage.local_private_path' => ['type' => 'string', 'default' => ''],
    'storage.s3_endpoint' => ['type' => 'string', 'default' => ''],
    'storage.s3_region' => ['type' => 'string', 'default' => ''],
    'storage.s3_bucket' => ['type' => 'string', 'default' => ''],
    'storage.s3_access_key' => ['type' => 'secret', 'default' => null],
    'storage.s3_secret_key' => ['type' => 'secret', 'default' => null],
    'storage.cdn_base_url' => ['type' => 'string', 'default' => ''],

    // Cache & cron
    'cache.driver' => ['type' => 'enum', 'default' => 'auto', 'values' => ['auto', 'apcu', 'redis', 'database', 'none']],
    'cache.catalog_ttl_seconds' => ['type' => 'int', 'default' => 600],
    'cron.worker_enabled' => ['type' => 'bool', 'default' => false],

    // Tax & currency
    'tax.withholding_enabled' => ['type' => 'bool', 'default' => false],
    'currency.default_listing_currency_id' => ['type' => 'int', 'default' => null],

    // Retention (days; 0 = keep forever)
    'retention.downloads_days' => ['type' => 'int', 'default' => 730],
    'retention.security_events_days' => ['type' => 'int', 'default' => 365],
    'retention.webhook_deliveries_days' => ['type' => 'int', 'default' => 90],
    'retention.abandoned_carts_days' => ['type' => 'int', 'default' => 90],
    'retention.ip_addresses_days' => ['type' => 'int', 'default' => 365],

    // Optional modules
    'affiliates.enabled' => ['type' => 'bool', 'default' => false],
    'referrals.enabled' => ['type' => 'bool', 'default' => false],
    'abandoned_carts.enabled' => ['type' => 'bool', 'default' => false],
    'domains.aftermarket_enabled' => ['type' => 'bool', 'default' => false],
];
