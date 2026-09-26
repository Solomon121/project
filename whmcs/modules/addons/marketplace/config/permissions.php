<?php

declare(strict_types=1);

/**
 * Staff permission catalogue and default roles (docs/architecture/09-permissions.md).
 *
 * Seeding is insert-only: existing roles and grants are never modified, so an
 * administrator's customisations survive upgrades. A permission introduced by a
 * later release is granted to the default roles listed here at the moment it is
 * first created.
 */

$permissions = [
    'marketplace' => ['marketplace.view', 'marketplace.manage'],
    'dashboard' => ['dashboard.view'],
    'reports' => ['reports.view', 'reports.export'],
    'vendors' => ['vendors.view', 'vendors.approve', 'vendors.suspend', 'vendors.ban', 'vendors.verify', 'vendors.edit'],
    'products' => [
        'products.view', 'products.create', 'products.edit', 'products.approve', 'products.suspend',
        'products.delete', 'products.bulk',
    ],
    'catalog' => ['categories.manage', 'collections.manage', 'product_types.manage'],
    'orders' => ['orders.view', 'orders.manage'],
    'refunds' => ['refunds.view', 'refunds.manage', 'refunds.approve_over_threshold'],
    'disputes' => ['disputes.view', 'disputes.manage'],
    'reviews' => ['reviews.view', 'reviews.moderate'],
    'licenses' => ['licenses.view', 'licenses.manage', 'licenses.revoke'],
    'coupons' => ['coupons.view', 'coupons.manage'],
    'finance' => [
        'commissions.view', 'commissions.manage', 'wallets.view', 'finance.adjust',
        'payouts.view', 'payouts.manage', 'payouts.approve', 'payouts.process',
    ],
    'customers' => ['customers.view', 'customers.privacy'],
    'moderation' => ['ip_complaints.manage', 'moderation.reports'],
    'integrations' => ['api.manage', 'webhooks.manage', 'integrations.manage'],
    'system' => [
        'settings.view', 'settings.manage', 'security.manage', 'roles.manage', 'logs.view', 'logs.export',
        'health.view', 'backups.manage', 'import_export.manage',
    ],
];

$all = array_merge(...array_values($permissions));
$without = static fn (array $exclude): array => array_values(array_diff($all, $exclude));
$viewPermissions = array_values(array_filter($all, static fn (string $p): bool => str_ends_with($p, '.view')));

return [
    'permissions' => $permissions,

    'roles' => [
        'super_admin' => [
            'name' => 'Super Administrator',
            'description' => 'Unrestricted access, including roles and security settings.',
            'grants' => $all,
        ],
        'marketplace_admin' => [
            'name' => 'Marketplace Administrator',
            'description' => 'Full marketplace operation except roles, security settings and manual ledger adjustments.',
            'grants' => $without(['roles.manage', 'security.manage', 'finance.adjust']),
        ],
        'marketplace_manager' => [
            'name' => 'Marketplace Manager',
            'description' => 'Day-to-day management of vendors, products, orders, reviews and coupons.',
            'grants' => array_values(array_unique(array_merge($viewPermissions, [
                'marketplace.view', 'dashboard.view', 'reports.view', 'reports.export',
                'vendors.approve', 'vendors.suspend', 'vendors.verify', 'vendors.edit',
                'products.create', 'products.edit', 'products.approve', 'products.suspend', 'products.delete', 'products.bulk',
                'categories.manage', 'collections.manage', 'orders.manage', 'reviews.moderate', 'coupons.manage',
            ]))),
        ],
        'product_moderator' => [
            'name' => 'Product Moderator',
            'description' => 'Reviews product and version submissions, reports, reviews and IP complaints.',
            'grants' => [
                'marketplace.view', 'dashboard.view', 'vendors.view', 'products.view', 'products.approve',
                'products.suspend', 'moderation.reports', 'reviews.view', 'reviews.moderate', 'ip_complaints.manage',
            ],
        ],
        'finance_manager' => [
            'name' => 'Finance Manager',
            'description' => 'Refunds, commissions, wallets, payouts and financial reports.',
            'grants' => [
                'marketplace.view', 'dashboard.view', 'reports.view', 'reports.export', 'vendors.view', 'orders.view',
                'refunds.view', 'refunds.manage', 'refunds.approve_over_threshold', 'commissions.view',
                'commissions.manage', 'wallets.view', 'finance.adjust', 'payouts.view', 'payouts.manage',
                'payouts.approve', 'payouts.process',
            ],
        ],
        'support_manager' => [
            'name' => 'Support Manager',
            'description' => 'Customer support: orders, disputes, refunds under threshold, licences and reviews.',
            'grants' => [
                'marketplace.view', 'dashboard.view', 'customers.view', 'vendors.view', 'products.view', 'orders.view',
                'disputes.view', 'disputes.manage', 'refunds.view', 'refunds.manage', 'licenses.view',
                'licenses.manage', 'reviews.view',
            ],
        ],
    ],

    /** Vendor member roles (09 §3). Evaluated in code; stored per member in vendor_members.role. */
    'vendor_roles' => [
        'owner' => [
            'vendor.profile.edit', 'vendor.products.view', 'vendor.products.edit', 'vendor.versions.upload',
            'vendor.orders.view', 'vendor.customers.contact', 'vendor.reviews.respond', 'vendor.refunds.decide',
            'vendor.disputes.respond', 'vendor.licenses.manage', 'vendor.coupons.manage', 'vendor.analytics.view',
            'vendor.earnings.view', 'vendor.payouts.request', 'vendor.payout_methods.manage', 'vendor.team.manage',
            'vendor.api_keys.manage',
        ],
        'manager' => [
            'vendor.profile.edit', 'vendor.products.view', 'vendor.products.edit', 'vendor.versions.upload',
            'vendor.orders.view', 'vendor.customers.contact', 'vendor.reviews.respond', 'vendor.refunds.decide',
            'vendor.disputes.respond', 'vendor.licenses.manage', 'vendor.coupons.manage', 'vendor.analytics.view',
            'vendor.earnings.view', 'vendor.api_keys.manage',
        ],
        'editor' => ['vendor.products.view', 'vendor.products.edit', 'vendor.versions.upload', 'vendor.analytics.view'],
        'support' => [
            'vendor.products.view', 'vendor.orders.view', 'vendor.customers.contact', 'vendor.reviews.respond',
            'vendor.refunds.decide', 'vendor.disputes.respond', 'vendor.licenses.manage',
        ],
        'finance' => [
            'vendor.products.view', 'vendor.orders.view', 'vendor.analytics.view', 'vendor.earnings.view',
            'vendor.payouts.request',
        ],
    ],
];
