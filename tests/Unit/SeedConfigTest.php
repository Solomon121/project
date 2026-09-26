<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WhmcsMarketplace\Kernel\ModuleInfo;

/**
 * Consistency of the default-data configuration files, independent of a database.
 */
final class SeedConfigTest extends TestCase
{
    /** @return array<mixed> */
    private static function config(string $name): array
    {
        return require ModuleInfo::configDir() . "/{$name}.php";
    }

    public function testPermissionCatalogueHasNoDuplicatesAndFollowsNamingConvention(): void
    {
        $all = array_merge(...array_values(self::config('permissions')['permissions']));

        self::assertSame(count($all), count(array_unique($all)));
        foreach ($all as $permission) {
            self::assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+$/', $permission);
        }
    }

    public function testEveryRoleGrantsOnlyCataloguedPermissions(): void
    {
        $config = self::config('permissions');
        $all = array_merge(...array_values($config['permissions']));

        foreach ($config['roles'] as $code => $role) {
            self::assertSame([], array_values(array_diff($role['grants'], $all)), "Role {$code}");
            self::assertSame(count($role['grants']), count(array_unique($role['grants'])), "Role {$code} duplicates");
        }
    }

    public function testRoleHierarchyMatchesPermissionsDocument(): void
    {
        $config = self::config('permissions');
        $all = array_merge(...array_values($config['permissions']));
        $roles = $config['roles'];

        self::assertEqualsCanonicalizing($all, $roles['super_admin']['grants']);
        self::assertNotContains('roles.manage', $roles['marketplace_admin']['grants']);
        self::assertNotContains('finance.adjust', $roles['marketplace_admin']['grants']);
        // Segregation of duties: only finance-oriented roles may adjust ledgers or process payouts.
        foreach (['marketplace_manager', 'product_moderator', 'support_manager'] as $role) {
            self::assertNotContains('finance.adjust', $roles[$role]['grants'], $role);
            self::assertNotContains('payouts.process', $roles[$role]['grants'], $role);
        }
        self::assertContains('finance.adjust', $roles['finance_manager']['grants']);
    }

    public function testVendorRolesUseVendorNamespaceOnly(): void
    {
        foreach (self::config('permissions')['vendor_roles'] as $role => $permissions) {
            foreach ($permissions as $permission) {
                self::assertStringStartsWith('vendor.', $permission, "Vendor role {$role}");
            }
        }
        $roles = self::config('permissions')['vendor_roles'];
        self::assertContains('vendor.payout_methods.manage', $roles['owner']);
        self::assertNotContains('vendor.payout_methods.manage', $roles['manager']);
        self::assertNotContains('vendor.payouts.request', $roles['editor']);
    }

    public function testProductTypesAreWellFormed(): void
    {
        $types = self::config('product_types');
        $handlers = ['script', 'software', 'wordpress', 'whmcs', 'digital_download', 'website', 'domain', 'domain_listing', 'service'];

        self::assertGreaterThanOrEqual(60, count($types));
        foreach ($types as $code => $type) {
            self::assertMatchesRegularExpression('/^[a-z0-9_]{2,64}$/', (string) $code);
            self::assertContains($type['family'], ['digital', 'website', 'domain', 'service']);
            self::assertContains($type['handler'], $handlers, $code);
            foreach ($type['allowed_extensions'] as $ext) {
                self::assertNotContains($ext, ['php', 'phtml', 'phar', 'cgi', 'pl', 'asp', 'aspx', 'jsp', 'sh', 'htaccess'], $code);
            }
        }
        // Every master-prompt product family is represented.
        foreach (['wordpress_theme', 'wordpress_plugin', 'whmcs_module', 'website_complete', 'domain_registration', 'domain_auction', 'service_seo', 'ebook'] as $required) {
            self::assertArrayHasKey($required, $types);
        }
    }

    public function testLicenseTypesCoverSpecification(): void
    {
        self::assertSame(
            ['single_domain', 'multi_domain', 'unlimited', 'personal', 'commercial', 'extended', 'developer', 'subscription', 'lifetime'],
            array_keys(self::config('license_types'))
        );
    }

    public function testCategorySlugsAreUniqueAndUrlSafe(): void
    {
        $slugs = [];
        foreach (self::config('categories') as $slug => $category) {
            $slugs[] = $slug;
            foreach (array_keys($category['children']) as $child) {
                $slugs[] = $child;
            }
        }
        self::assertSame(count($slugs), count(array_unique($slugs)));
        foreach ($slugs as $slug) {
            self::assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug);
        }
    }

    public function testSettingsDefaultsMatchTheirDeclaredType(): void
    {
        foreach (self::config('settings') as $name => $definition) {
            self::assertMatchesRegularExpression('/^[a-z_]+\.[a-z0-9_]+$/', $name);
            $default = $definition['default'];
            switch ($definition['type']) {
                case 'bool':
                    self::assertIsBool($default, $name);
                    break;
                case 'int':
                    self::assertTrue($default === null || is_int($default), $name);
                    break;
                case 'decimal':
                    self::assertTrue($default === null || (is_string($default) && is_numeric($default)), "{$name} must be a decimal string, never a float");
                    break;
                case 'enum':
                    self::assertContains($default, $definition['values'], $name);
                    break;
                case 'list':
                    self::assertIsList($default, $name);
                    break;
                case 'secret':
                    self::assertNull($default, "{$name}: secrets must not have defaults");
                    break;
                case 'string':
                    self::assertIsString($default, $name);
                    break;
                default:
                    self::fail("Unknown setting type for {$name}");
            }
        }
    }

    public function testMarketplaceStartsDisabledAndConservative(): void
    {
        $settings = self::config('settings');
        self::assertFalse($settings['general.marketplace_enabled']['default']);
        self::assertTrue($settings['vendors.require_approval']['default']);
        self::assertSame('always', $settings['products.require_approval']['default']);
        self::assertTrue($settings['reviews.verified_purchase_only']['default']);
        self::assertFalse($settings['vendors.share_buyer_email']['default']);
    }

    public function testLegalPagesAreClearlyMarkedTemplates(): void
    {
        foreach (self::config('legal_pages') as $slug => $page) {
            self::assertStringContainsString('not legal advice', $page['body'], $slug);
        }
    }
}
