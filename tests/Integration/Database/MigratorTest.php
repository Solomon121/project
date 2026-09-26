<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Tests\Integration\Database;

use WhmcsMarketplace\Infrastructure\Persistence\Schema\MigrationException;
use WhmcsMarketplace\Infrastructure\Persistence\Table;
use WhmcsMarketplace\Kernel\ModuleInfo;
use WhmcsMarketplace\Tests\Support\DatabaseTestCase;

final class MigratorTest extends DatabaseTestCase
{
    public function testFreshInstallAppliesEveryMigrationInOneBatch(): void
    {
        $migrator = $this->migrator();
        $ran = $migrator->migrate();

        self::assertCount(16, $ran);
        self::assertSame('0001_create_platform_tables', $ran[0]);
        self::assertSame('0016_install_append_only_triggers', $ran[15]);
        self::assertSame([], $migrator->pending());
        foreach ($migrator->status() as $row) {
            self::assertTrue($row['ran']);
            self::assertSame(1, $row['batch']);
        }
        self::assertCount(count($migrator->ownedTables()), $this->marketplaceTables());
    }

    public function testRerunningMigrateIsANoOp(): void
    {
        $this->migrator()->migrate();
        $tablesBefore = $this->marketplaceTables();

        self::assertSame([], $this->migrator()->migrate());
        self::assertSame($tablesBefore, $this->marketplaceTables());
        self::assertSame(16, $this->table('migrations')->count());
    }

    public function testFullRollbackRemovesEverythingAndCanBeReapplied(): void
    {
        $migrator = $this->migrator();
        $migrator->migrate();
        $rolledBack = $migrator->rollback();

        self::assertCount(16, $rolledBack);
        self::assertSame('0016_install_append_only_triggers', $rolledBack[0]);
        self::assertSame(['mod_marketplace_migrations'], $this->marketplaceTables());
        self::assertSame([], $this->db->connection()->select(
            "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'mkp\\_%'"
        ));

        self::assertCount(16, $migrator->migrate());
    }

    public function testRollbackUndoesOnlyTheLastBatch(): void
    {
        $dir = $this->tempDir();
        $files = glob(ModuleInfo::migrationsDir() . '/*.php');
        sort($files);
        foreach (array_slice($files, 0, 4) as $file) {
            copy($file, $dir . '/' . basename($file));
        }
        self::assertCount(4, $this->migrator($dir)->migrate());

        foreach (array_slice($files, 4, 2) as $file) {
            copy($file, $dir . '/' . basename($file));
        }
        $migrator = $this->migrator($dir);
        self::assertSame(['0005_create_product_tables', '0006_create_search_and_collections'], $migrator->migrate());

        self::assertSame(['0006_create_search_and_collections', '0005_create_product_tables'], $migrator->rollback());
        self::assertTrue($this->hasTable('vendors'));
        self::assertFalse($this->hasTable('products'));
        self::assertFalse($this->hasTable('search_index'));
        // The FK added by 0005 onto categories (owned by 0003) was removed with it.
        self::assertSame([], $this->db->connection()->select(
            "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'mod_marketplace_categories'
                AND REFERENCED_TABLE_NAME = 'mod_marketplace_media'"
        ));
    }

    public function testRollbackRefusesToDestroyFinancialDataWithoutForce(): void
    {
        $this->installer()->install();  // seeds the global commission rule (a financial record)

        try {
            $this->migrator()->reset();
            self::fail('Expected rollback to be refused.');
        } catch (MigrationException $e) {
            self::assertStringContainsString('financial records', $e->getMessage());
        }

        // Nothing was rolled back: the guard runs before any down().
        self::assertSame(16, $this->table('migrations')->count());
        self::assertTrue($this->hasTable('audit_logs'));

        self::assertCount(16, $this->migrator()->reset(true));
        self::assertSame(['mod_marketplace_migrations'], $this->marketplaceTables());
    }

    public function testFailedMigrationIsCleanedUpNotRecordedAndReported(): void
    {
        $dir = $this->tempDir();
        copy(ModuleInfo::migrationsDir() . '/0001_create_platform_tables.php', $dir . '/0001_create_platform_tables.php');
        file_put_contents($dir . '/0002_broken.php', <<<'PHP'
            <?php
            use Illuminate\Database\Schema\Blueprint;
            use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migration;
            return new class extends Migration {
                public function tables(): array { return ['broken_a', 'broken_b']; }
                public function up(): void {
                    $this->create('broken_a', function (Blueprint $t): void { $this->id($t); });
                    $this->statement('THIS IS NOT SQL');
                }
                public function down(): void { $this->dropAll($this->tables()); }
            };
            PHP);

        try {
            $this->migrator($dir)->migrate();
            self::fail('Expected the broken migration to fail.');
        } catch (MigrationException $e) {
            self::assertStringContainsString('0002_broken failed', $e->getMessage());
            self::assertNotNull($e->getPrevious());
        }

        self::assertFalse($this->hasTable('broken_a'), 'Partial table must be removed by down().');
        self::assertSame(['0001_create_platform_tables'], $this->table('migrations')->pluck('migration')->all());
        self::assertSame(['0002_broken'], $this->migrator($dir)->pending());
    }

    public function testUnrecordedExistingTableIsReportedAndNeverDropped(): void
    {
        $this->db->schema()->create(Table::name('settings'), static function ($t): void {
            $t->increments('id');
        });
        $this->db->connection()->table(Table::name('settings'))->insert(['id' => 7]);

        try {
            $this->migrator()->migrate();
            self::fail('Expected the pre-existing table to block the migration.');
        } catch (MigrationException $e) {
            self::assertStringContainsString('mod_marketplace_settings', $e->getMessage());
            self::assertStringContainsString('Nothing was changed', $e->getMessage());
        }

        // The pre-existing table and its data are untouched; nothing else was created.
        self::assertSame(1, $this->table('settings')->where('id', 7)->count());
        self::assertSame(['mod_marketplace_migrations', 'mod_marketplace_settings'], $this->marketplaceTables());
    }

    public function testConcurrentRunsAreSerialisedByLock(): void
    {
        $other = self::newContext();
        $held = $other->connection()->selectOne("SELECT GET_LOCK('mod_marketplace_migrate', 0) AS l");
        self::assertSame(1, (int) $held->l);

        try {
            $this->migrator(null, 1)->migrate();
            self::fail('Expected lock contention.');
        } catch (MigrationException $e) {
            self::assertStringContainsString('already running', $e->getMessage());
        } finally {
            $other->connection()->select("SELECT RELEASE_LOCK('mod_marketplace_migrate')");
        }

        self::assertCount(16, $this->migrator(null, 1)->migrate());
    }

    public function testInvalidMigrationFileIsRejected(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/0001_not_a_migration.php', "<?php return 42;\n");

        $this->expectException(MigrationException::class);
        $this->migrator($dir)->migrate();
    }
}
