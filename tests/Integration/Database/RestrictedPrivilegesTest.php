<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use WhmcsMarketplace\Application\Installer;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migrator;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\SchemaCheck;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\SchemaVerifier;
use WhmcsMarketplace\Infrastructure\Persistence\StandaloneDatabaseContext;
use WhmcsMarketplace\Infrastructure\Persistence\Table;
use WhmcsMarketplace\Kernel\ModuleInfo;
use WhmcsMarketplace\Tests\Support\DatabaseTestCase;

/**
 * Installation on a host where the WHMCS database user lacks the TRIGGER
 * privilege (common on shared hosting).
 *
 * Opt-in: needs a second database and user configured through
 * MKP_TEST_NOTRIGGER_DATABASE / _USERNAME / _PASSWORD (same host/socket as
 * the main test database). CI provisions it; see .github/workflows/ci.yml.
 */
final class RestrictedPrivilegesTest extends TestCase
{
    private StandaloneDatabaseContext $db;

    protected function setUp(): void
    {
        $database = getenv('MKP_TEST_NOTRIGGER_DATABASE');
        if ($database === false || $database === '') {
            self::markTestSkipped('MKP_TEST_NOTRIGGER_* not configured.');
        }
        $this->db = new StandaloneDatabaseContext([
            'host' => getenv('MKP_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('MKP_TEST_DB_PORT') ?: 3306,
            'socket' => getenv('MKP_TEST_DB_SOCKET') ?: '',
            'database' => $database,
            'username' => (string) getenv('MKP_TEST_NOTRIGGER_USERNAME'),
            'password' => (string) getenv('MKP_TEST_NOTRIGGER_PASSWORD'),
        ]);
        DatabaseTestCase::wipe($this->db);
    }

    protected function tearDown(): void
    {
        DatabaseTestCase::wipe($this->db);
    }

    public function testInstallSucceedsAndReportsDegradedImmutability(): void
    {
        $result = (new Installer($this->db, ModuleInfo::migrationsDir(), ModuleInfo::configDir()))->install();

        self::assertCount(16, $result['migrated']);
        $connection = $this->db->connection();
        self::assertSame('false', $connection->table(Table::name('settings'))
            ->where('name', 'database.append_only_triggers')->value('value'));

        $event = $connection->table(Table::name('health_events'))->where('code', 'APPEND_ONLY_TRIGGERS_NOT_INSTALLED')->first();
        self::assertNotNull($event);
        self::assertSame('warning', $event->severity);

        $checks = [];
        foreach ((new SchemaVerifier($this->db, new Migrator($this->db, ModuleInfo::migrationsDir())))->verify() as $check) {
            $checks[$check->code] = $check->status;
        }
        self::assertSame(SchemaCheck::WARNING, $checks['APPEND_ONLY_NOT_ENFORCED']);
        self::assertSame(SchemaCheck::PASS, $checks['TABLES_PRESENT']);
    }
}
