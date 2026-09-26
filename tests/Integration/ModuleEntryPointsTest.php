<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use WHMCS\Database\Capsule;
use WhmcsMarketplace\Application\Installer;
use WhmcsMarketplace\Kernel\ModuleInfo;
use WhmcsMarketplace\Tests\Support\DatabaseTestCase;

/**
 * Exercises the WHMCS addon module functions in marketplace.php against a real
 * database, with WHMCS runtime classes stubbed (tests/Support/WhmcsRuntimeStubs.php).
 */
final class ModuleEntryPointsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Capsule::$context = $this->db;
        require_once ModuleInfo::moduleDir() . '/marketplace.php';

        // Test double for WHMCS's addon settings table.
        $this->db->schema()->create('tbladdonmodules', static function (Blueprint $t): void {
            $t->string('module', 64);
            $t->string('setting', 64);
            $t->text('value');
        });
        $GLOBALS['__mkp_activity_log'] = [];
    }

    protected function tearDown(): void
    {
        Capsule::$context = null;
        parent::tearDown();
    }

    public function testConfigDeclaresModuleMetadata(): void
    {
        $config = marketplace_config();

        self::assertSame('Marketplace', $config['name']);
        self::assertSame(ModuleInfo::VERSION, $config['version']);
        self::assertArrayHasKey('remove_data_on_deactivate', $config['fields']);
        self::assertSame('yesno', $config['fields']['remove_data_on_deactivate']['Type']);
    }

    public function testActivateInstallsSchemaAndDefaults(): void
    {
        $result = marketplace_activate();

        self::assertSame('success', $result['status'], $result['description']);
        self::assertStringContainsString('16 migration(s)', $result['description']);
        self::assertTrue($this->hasTable('wallet_transactions'));
        self::assertGreaterThan(0, $this->table('permissions')->count());
    }

    public function testActivateReportsFailureWithoutThrowing(): void
    {
        // Simulate an unrecorded leftover table: installation must stop and say so.
        $this->db->schema()->create('mod_marketplace_settings', static function (Blueprint $t): void {
            $t->increments('id');
        });

        $result = marketplace_activate();

        self::assertSame('error', $result['status']);
        self::assertStringContainsString('mod_marketplace_settings', $result['description']);
        self::assertCount(1, $GLOBALS['__mkp_activity_log']);
        self::assertStringContainsString('Marketplace activation failed', $GLOBALS['__mkp_activity_log'][0]);
    }

    public function testUpgradeAppliesPendingWorkAndIsIdempotent(): void
    {
        marketplace_activate();
        $this->table('permissions')->where('code', 'reports.export')->delete();

        marketplace_upgrade(['version' => '0.1.0']);
        marketplace_upgrade(['version' => '0.1.0']);

        self::assertSame(1, $this->table('permissions')->where('code', 'reports.export')->count());
    }

    public function testDeactivateKeepsDataByDefault(): void
    {
        marketplace_activate();

        $result = marketplace_deactivate();

        self::assertSame('success', $result['status']);
        self::assertStringContainsString('kept', $result['description']);
        self::assertTrue($this->hasTable('wallet_transactions'));
    }

    public function testDeactivateRefusesToRemoveFinancialDataWithoutTypedConfirmation(): void
    {
        marketplace_activate();
        $this->saveAddonSetting('remove_data_on_deactivate', 'on');
        $this->saveAddonSetting('remove_data_confirmation', 'delete');

        $result = marketplace_deactivate();

        self::assertSame('error', $result['status']);
        self::assertStringContainsString('NOT removed', $result['description']);
        self::assertTrue($this->hasTable('commission_rules'));
        self::assertSame(16, $this->table('migrations')->count());
    }

    public function testDeactivateRemovesEverythingWithTypedConfirmation(): void
    {
        marketplace_activate();
        $this->saveAddonSetting('remove_data_on_deactivate', 'on');
        $this->saveAddonSetting('remove_data_confirmation', Installer::REMOVE_DATA_CONFIRMATION);

        $result = marketplace_deactivate();

        self::assertSame('success', $result['status'], $result['description']);
        self::assertSame([], $this->marketplaceTables());
    }

    public function testAdminOutputShowsHealthAndEscapesContent(): void
    {
        marketplace_activate();

        ob_start();
        marketplace_output(['modulelink' => 'addonmodules.php?module=marketplace']);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Schema health', $html);
        self::assertStringContainsString('APPEND_ONLY_ENFORCED', $html);
        self::assertStringContainsString('0016_install_append_only_triggers', $html);
        self::assertStringNotContainsString('ERROR', strip_tags($html));
        self::assertStringNotContainsString('<script', $html);
    }

    private function saveAddonSetting(string $setting, string $value): void
    {
        $this->db->connection()->table('tbladdonmodules')->insert([
            'module' => 'marketplace', 'setting' => $setting, 'value' => $value,
        ]);
    }
}
