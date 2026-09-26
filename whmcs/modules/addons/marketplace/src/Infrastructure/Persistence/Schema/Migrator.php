<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Infrastructure\Persistence\Schema;

use Illuminate\Database\Schema\Blueprint;
use WhmcsMarketplace\Infrastructure\Persistence\DatabaseContext;
use WhmcsMarketplace\Infrastructure\Persistence\Table;

/**
 * Runs, tracks and rolls back marketplace migrations.
 *
 * - Migration files live in /migrations and are named NNNN_description.php; each
 *   returns a Migration instance. Files run in filename order.
 * - Each successful migration is recorded with a batch number, so re-running is a
 *   no-op and rollback undoes whole batches.
 * - MySQL DDL auto-commits, so a failed migration cannot be rolled back by a
 *   transaction. Instead the migrator calls the migration's down() to remove the
 *   partial state before re-throwing, and the failure is never swallowed. This is
 *   only safe because a migration never starts when any table it owns already
 *   exists (an unrecorded table is reported, not dropped).
 * - A named DB lock prevents two concurrent runs (e.g. two admins opening the
 *   module right after an upgrade).
 */
final class Migrator
{
    public const REPOSITORY_TABLE = 'migrations';
    private const LOCK_NAME = 'mod_marketplace_migrate';

    /** @var array<string, Migration>|null */
    private ?array $migrations = null;

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly string $migrationsPath,
        private readonly int $lockTimeoutSeconds = 30,
    ) {
    }

    /**
     * Runs all pending migrations in one new batch.
     *
     * @return list<string> names of migrations that ran
     */
    public function migrate(): array
    {
        return $this->withLock(function (): array {
            $this->ensureRepository();
            $pending = $this->pending();
            if ($pending === []) {
                return [];
            }

            $batch = $this->lastBatch() + 1;
            $ran = [];
            foreach ($pending as $name) {
                $migration = $this->all()[$name];

                // Refuse before touching anything if tables this migration owns already
                // exist: they were not created by a recorded run, and the failure
                // cleanup below must only ever remove tables created by this run.
                $existing = array_values(array_filter(
                    $migration->tables(),
                    fn (string $short): bool => $this->db->schema()->hasTable(Table::name($short))
                ));
                if ($existing !== []) {
                    throw MigrationException::tablesAlreadyExist($name, array_map([Table::class, 'name'], $existing));
                }

                try {
                    $migration->up();
                } catch (\Throwable $e) {
                    $cleanupError = null;
                    try {
                        $migration->down();
                    } catch (\Throwable $cleanup) {
                        $cleanupError = $cleanup;
                    }
                    throw MigrationException::failed($name, $e, $cleanupError);
                }

                $this->repository()->insert([
                    'migration' => $name,
                    'batch' => $batch,
                    'ran_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $ran[] = $name;
            }

            return $ran;
        });
    }

    /**
     * Rolls back the last $steps batches (newest migration first).
     *
     * @return list<string> names of migrations rolled back
     */
    public function rollback(int $steps = 1, bool $forceDestroyFinancialData = false): array
    {
        if ($steps < 1) {
            throw new \InvalidArgumentException('Rollback steps must be at least 1.');
        }

        return $this->withLock(function () use ($steps, $forceDestroyFinancialData): array {
            $this->ensureRepository();
            $lastBatch = $this->lastBatch();
            if ($lastBatch === 0) {
                return [];
            }

            $names = $this->repository()
                ->where('batch', '>', $lastBatch - $steps)
                ->orderByDesc('batch')
                ->orderByDesc('migration')
                ->pluck('migration')
                ->all();

            return $this->rollbackNames($names, $forceDestroyFinancialData);
        });
    }

    /**
     * Rolls back every migration (used by full uninstall).
     *
     * @return list<string>
     */
    public function reset(bool $forceDestroyFinancialData = false): array
    {
        return $this->withLock(function () use ($forceDestroyFinancialData): array {
            $this->ensureRepository();
            $names = $this->repository()->orderByDesc('migration')->pluck('migration')->all();

            return $this->rollbackNames($names, $forceDestroyFinancialData);
        });
    }

    /**
     * @return list<array{migration:string, ran:bool, batch:int|null}>
     */
    public function status(): array
    {
        $ran = $this->ranWithBatches();
        $rows = [];
        foreach (array_keys($this->all()) as $name) {
            $rows[] = ['migration' => $name, 'ran' => isset($ran[$name]), 'batch' => $ran[$name] ?? null];
        }

        return $rows;
    }

    /** @return list<string> */
    public function pending(): array
    {
        $ran = $this->ranWithBatches();

        return array_values(array_filter(
            array_keys($this->all()),
            static fn (string $name): bool => !isset($ran[$name])
        ));
    }

    public function hasPending(): bool
    {
        return $this->pending() !== [];
    }

    /**
     * All migrations, keyed by name, in execution order.
     *
     * @return array<string, Migration>
     */
    public function all(): array
    {
        if ($this->migrations !== null) {
            return $this->migrations;
        }

        $files = glob(rtrim($this->migrationsPath, '/') . '/[0-9][0-9][0-9][0-9]_*.php') ?: [];
        sort($files, SORT_STRING);

        $migrations = [];
        foreach ($files as $file) {
            $migration = require $file;
            if (!$migration instanceof Migration) {
                throw MigrationException::invalidFile(basename($file));
            }
            $migration->setContext($this->db);
            $migrations[basename($file, '.php')] = $migration;
        }

        return $this->migrations = $migrations;
    }

    /**
     * Short names of every table owned by the (known) migrations.
     *
     * @return list<string>
     */
    public function ownedTables(): array
    {
        $tables = [self::REPOSITORY_TABLE];
        foreach ($this->all() as $migration) {
            array_push($tables, ...$migration->tables());
        }

        return $tables;
    }

    public function dropRepository(): void
    {
        $this->db->schema()->dropIfExists(Table::name(self::REPOSITORY_TABLE));
    }

    // ---------------------------------------------------------------- internals

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function rollbackNames(array $names, bool $forceDestroyFinancialData): array
    {
        $all = $this->all();

        // Check every financial guard before touching anything.
        if (!$forceDestroyFinancialData) {
            foreach ($names as $name) {
                if (isset($all[$name]) && $all[$name]->isFinancial()) {
                    foreach ($all[$name]->tables() as $shortName) {
                        $table = Table::name($shortName);
                        if ($this->db->schema()->hasTable($table)
                            && $this->db->connection()->table($table)->exists()) {
                            throw MigrationException::financialDataPresent($name, $table);
                        }
                    }
                }
            }
        }

        $rolledBack = [];
        foreach ($names as $name) {
            if (!isset($all[$name])) {
                throw new MigrationException("Cannot roll back {$name}: migration file is missing.");
            }
            $all[$name]->down();
            $this->repository()->where('migration', $name)->delete();
            $rolledBack[] = $name;
        }

        return $rolledBack;
    }

    private function ensureRepository(): void
    {
        $table = Table::name(self::REPOSITORY_TABLE);
        $schema = $this->db->schema();
        if ($schema->hasTable($table)) {
            return;
        }

        $schema->create($table, function (Blueprint $t): void {
            $t->engine = 'InnoDB';
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_unicode_ci';
            $t->increments('id');
            $t->string('migration', 191)->charset('ascii')->collation('ascii_bin');
            $t->unsignedInteger('batch');
            $t->dateTime('ran_at', 6);
            $t->unique(['migration'], 'mkp_migrations_migration_uq');
        });
    }

    /** @return array<string, int> */
    private function ranWithBatches(): array
    {
        $table = Table::name(self::REPOSITORY_TABLE);
        if (!$this->db->schema()->hasTable($table)) {
            return [];
        }

        $rows = $this->repository()->get(['migration', 'batch']);
        $ran = [];
        foreach ($rows as $row) {
            $ran[(string) $row->migration] = (int) $row->batch;
        }

        return $ran;
    }

    private function lastBatch(): int
    {
        return (int) $this->repository()->max('batch');
    }

    private function repository(): \Illuminate\Database\Query\Builder
    {
        return $this->db->connection()->table(Table::name(self::REPOSITORY_TABLE));
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        $connection = $this->db->connection();
        $acquired = $connection->selectOne('SELECT GET_LOCK(?, ?) AS l', [self::LOCK_NAME, $this->lockTimeoutSeconds]);
        if ((int) ($acquired->l ?? 0) !== 1) {
            throw MigrationException::lockUnavailable();
        }

        try {
            return $callback();
        } finally {
            $connection->select('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        }
    }
}
