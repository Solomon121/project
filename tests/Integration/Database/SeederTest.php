<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Tests\Integration\Database;

use WhmcsMarketplace\Infrastructure\Persistence\Seeding\DatabaseSeeder;
use WhmcsMarketplace\Kernel\ModuleInfo;
use WhmcsMarketplace\Tests\Support\DatabaseTestCase;

final class SeederTest extends DatabaseTestCase
{
    /** @return array<mixed> */
    private static function config(string $name): array
    {
        return require ModuleInfo::configDir() . "/{$name}.php";
    }

    public function testInstallSeedsEveryDefaultDataset(): void
    {
        $this->installer()->install();

        $permissions = self::config('permissions');
        $allPermissions = array_merge(...array_values($permissions['permissions']));
        self::assertSame(count($allPermissions), $this->table('permissions')->count());
        self::assertSame(count($permissions['roles']), $this->table('roles')->count());
        self::assertSame(count($allPermissions), $this->grantsOf('super_admin'));
        self::assertSame(count($permissions['roles']['product_moderator']['grants']), $this->grantsOf('product_moderator'));

        self::assertSame(count(self::config('product_types')), $this->table('product_types')->count());
        self::assertSame(9, $this->table('license_types')->count());
        // Every configured setting, plus the trigger-enforcement state recorded by migration 0016.
        self::assertSame(count(self::config('settings')), $this->table('settings')->where('name', '<>', 'database.append_only_triggers')->count());
        self::assertSame('true', $this->table('settings')->where('name', 'database.append_only_triggers')->value('value'));
        self::assertSame(count(self::config('email_events')), $this->table('email_template_map')->count());

        $rule = $this->table('commission_rules')->where('scope', 'global')->first();
        self::assertNotNull($rule);
        self::assertSame('20.0000', (string) $rule->percentage);
        self::assertSame('percentage', $rule->method);

        $legal = $this->table('legal_pages')->get();
        self::assertCount(7, $legal);
        foreach ($legal as $page) {
            self::assertSame(1, (int) $page->is_template);
            self::assertNull($page->published_at, 'Legal templates must not be published automatically');
        }

        // Settings are stored as JSON; secrets are unset and flagged for encryption.
        self::assertSame('false', $this->table('settings')->where('name', 'general.marketplace_enabled')->value('value'));
        $secret = $this->table('settings')->where('name', 'storage.s3_secret_key')->first();
        self::assertNull($secret->value);
        self::assertSame(1, (int) $secret->is_encrypted);
    }

    public function testCategoryTreeHasConsistentMaterialisedPaths(): void
    {
        $this->installer()->install();

        $expected = 0;
        foreach (self::config('categories') as $category) {
            $expected += 1 + count($category['children']);
        }
        $categories = $this->table('categories')->get()->keyBy('id');
        self::assertCount($expected, $categories);

        foreach ($categories as $id => $category) {
            if ($category->parent_id === null) {
                self::assertSame("/{$id}/", $category->path);
                self::assertSame(0, (int) $category->depth);
            } else {
                $parent = $categories[$category->parent_id];
                self::assertSame($parent->path . $id . '/', $category->path);
                self::assertSame((int) $parent->depth + 1, (int) $category->depth);
            }
        }
        $php = $categories->firstWhere('slug', 'software-php');
        self::assertSame('software', $categories[$php->parent_id]->slug);
    }

    public function testSeedingIsIdempotent(): void
    {
        $this->installer()->install();
        $counts = $this->counts();

        $second = (new DatabaseSeeder($this->db, ModuleInfo::configDir()))->run();

        self::assertSame(['roles_permissions' => 0, 'catalog' => 0, 'configuration' => 0], $second);
        self::assertSame($counts, $this->counts());
    }

    public function testAdministratorChangesSurviveUpgrades(): void
    {
        $this->installer()->install();

        $managerId = (int) $this->table('roles')->where('code', 'marketplace_manager')->value('id');
        $couponsManage = (int) $this->table('permissions')->where('code', 'coupons.manage')->value('id');
        $this->table('role_permissions')->where(['role_id' => $managerId, 'permission_id' => $couponsManage])->delete();
        $this->table('settings')->where('name', 'payouts.minimum_amount')->update(['value' => json_encode('100.0000')]);
        $this->table('commission_rules')->where('scope', 'global')->update(['percentage' => '15.0000']);
        $this->table('categories')->where('slug', 'graphics-icons')->delete();

        $this->installer()->upgrade();

        self::assertFalse(
            $this->table('role_permissions')->where(['role_id' => $managerId, 'permission_id' => $couponsManage])->exists(),
            'A permission the administrator removed must not be re-granted'
        );
        self::assertSame('"100.0000"', $this->table('settings')->where('name', 'payouts.minimum_amount')->value('value'));
        self::assertSame('15.0000', (string) $this->table('commission_rules')->where('scope', 'global')->value('percentage'));
        self::assertSame(1, $this->table('commission_rules')->count());
        self::assertFalse($this->table('categories')->where('slug', 'graphics-icons')->exists());
    }

    public function testNewPermissionInAnUpgradeIsGrantedToItsDefaultRolesOnly(): void
    {
        $this->installer()->install();

        $managerId = (int) $this->table('roles')->where('code', 'marketplace_manager')->value('id');
        $couponsManage = (int) $this->table('permissions')->where('code', 'coupons.manage')->value('id');
        $this->table('role_permissions')->where(['role_id' => $managerId, 'permission_id' => $couponsManage])->delete();

        // Simulate a release that adds one permission granted to the manager role.
        $dir = $this->tempDir();
        foreach (glob(ModuleInfo::configDir() . '/*.php') as $file) {
            copy($file, $dir . '/' . basename($file));
        }
        file_put_contents($dir . '/permissions.php', str_replace(
            "'coupons' => ['coupons.view', 'coupons.manage'],",
            "'coupons' => ['coupons.view', 'coupons.manage', 'coupons.export'],",
            (string) file_get_contents($dir . '/permissions.php')
        ));
        file_put_contents($dir . '/permissions.php', str_replace(
            "'categories.manage', 'collections.manage', 'orders.manage', 'reviews.moderate', 'coupons.manage',",
            "'categories.manage', 'collections.manage', 'orders.manage', 'reviews.moderate', 'coupons.manage', 'coupons.export',",
            (string) file_get_contents($dir . '/permissions.php')
        ));

        (new DatabaseSeeder($this->db, $dir))->run();

        $grants = $this->table('role_permissions')
            ->join('mod_marketplace_permissions as p', 'p.id', '=', 'mod_marketplace_role_permissions.permission_id')
            ->where('role_id', $managerId)->pluck('p.code')->all();
        self::assertContains('coupons.export', $grants);
        self::assertNotContains('coupons.manage', $grants);

        $superAdminId = (int) $this->table('roles')->where('code', 'super_admin')->value('id');
        $superGrants = $this->table('role_permissions')
            ->join('mod_marketplace_permissions as p', 'p.id', '=', 'mod_marketplace_role_permissions.permission_id')
            ->where('role_id', $superAdminId)->pluck('p.code')->all();
        self::assertContains('coupons.export', $superGrants, 'super_admin grants every catalogued permission');
    }

    public function testSeedingIsAtomic(): void
    {
        $this->migrator()->migrate();

        $dir = $this->tempDir();
        foreach (glob(ModuleInfo::configDir() . '/*.php') as $file) {
            copy($file, $dir . '/' . basename($file));
        }
        // An invalid settings file makes the last seeder fail after earlier ones inserted rows.
        file_put_contents($dir . '/legal_pages.php', "<?php return 'not an array';\n");

        try {
            (new DatabaseSeeder($this->db, $dir))->run();
            self::fail('Expected seeding to fail.');
        } catch (\UnexpectedValueException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(0, $this->table('permissions')->count(), 'Earlier seeders must be rolled back');
        self::assertSame(0, $this->table('product_types')->count());
        self::assertSame(0, $this->table('settings')->where('name', '<>', 'database.append_only_triggers')->count());
    }

    private function grantsOf(string $role): int
    {
        $id = (int) $this->table('roles')->where('code', $role)->value('id');

        return $this->table('role_permissions')->where('role_id', $id)->count();
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach (['permissions', 'roles', 'role_permissions', 'product_types', 'license_types', 'categories',
            'settings', 'commission_rules', 'email_template_map', 'legal_pages'] as $table) {
            $counts[$table] = $this->table($table)->count();
        }

        return $counts;
    }
}
