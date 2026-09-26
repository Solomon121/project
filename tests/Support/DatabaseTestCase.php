<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Tests\Support;

use PHPUnit\Framework\TestCase;
use WhmcsMarketplace\Application\Installer;
use WhmcsMarketplace\Infrastructure\Persistence\DatabaseContext;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migrator;
use WhmcsMarketplace\Infrastructure\Persistence\StandaloneDatabaseContext;
use WhmcsMarketplace\Infrastructure\Persistence\Table;
use WhmcsMarketplace\Kernel\ModuleInfo;

/**
 * Base class for tests against a real MySQL/MariaDB database.
 *
 * Every test starts from a database with no marketplace tables or triggers.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected DatabaseContext $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = self::newContext();
        try {
            $this->db->connection()->getPdo();
        } catch (\Throwable $e) {
            $this->fail('Integration test database unavailable (configure MKP_TEST_DB_*): ' . $e->getMessage());
        }
        self::wipe($this->db);
    }

    protected function tearDown(): void
    {
        self::wipe($this->db);
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    public static function newContext(): StandaloneDatabaseContext
    {
        $env = static fn (string $name): string => (string) (getenv($name) ?: ($_ENV[$name] ?? ''));

        return new StandaloneDatabaseContext([
            'host' => $env('MKP_TEST_DB_HOST') ?: '127.0.0.1',
            'port' => $env('MKP_TEST_DB_PORT') ?: 3306,
            'socket' => $env('MKP_TEST_DB_SOCKET'),
            'database' => $env('MKP_TEST_DB_DATABASE'),
            'username' => $env('MKP_TEST_DB_USERNAME'),
            'password' => $env('MKP_TEST_DB_PASSWORD'),
        ]);
    }

    /** Drops every marketplace trigger and table (and the tbladdonmodules test double). */
    public static function wipe(DatabaseContext $db): void
    {
        $connection = $db->connection();
        foreach ($connection->select(
            "SELECT TRIGGER_NAME AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'mkp\\_%'"
        ) as $row) {
            $connection->unprepared("DROP TRIGGER IF EXISTS `{$row->n}`");
        }
        $connection->statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($connection->select(
            "SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
               AND (TABLE_NAME LIKE 'mod\\_marketplace\\_%' OR TABLE_NAME = 'tbladdonmodules')"
        ) as $row) {
            $connection->statement("DROP TABLE IF EXISTS `{$row->t}`");
        }
        $connection->statement('SET FOREIGN_KEY_CHECKS=1');
    }

    protected function installer(): Installer
    {
        return new Installer($this->db, ModuleInfo::migrationsDir(), ModuleInfo::configDir());
    }

    protected function migrator(?string $path = null, int $lockTimeout = 30): Migrator
    {
        return new Migrator($this->db, $path ?? ModuleInfo::migrationsDir(), $lockTimeout);
    }

    protected function table(string $shortName): \Illuminate\Database\Query\Builder
    {
        return $this->db->connection()->table(Table::name($shortName));
    }

    protected function hasTable(string $shortName): bool
    {
        return $this->db->schema()->hasTable(Table::name($shortName));
    }

    /** @return list<string> */
    protected function marketplaceTables(): array
    {
        return array_map(
            static fn (object $r): string => (string) $r->t,
            $this->db->connection()->select(
                "SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'mod\\_marketplace\\_%' ORDER BY TABLE_NAME"
            )
        );
    }

    protected function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** Creates a temporary directory that is removed after the test. */
    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/mkp-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /** @var list<string> */
    private array $tempDirs = [];
}
