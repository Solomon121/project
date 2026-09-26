<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Application;

use WhmcsMarketplace\Infrastructure\Persistence\DatabaseContext;
use WhmcsMarketplace\Infrastructure\Persistence\Schema\Migrator;
use WhmcsMarketplace\Infrastructure\Persistence\Seeding\DatabaseSeeder;
use WhmcsMarketplace\Infrastructure\Persistence\Table;

/**
 * Install / upgrade / uninstall orchestration used by the WHMCS module
 * functions and the CLI.
 *
 * Install and upgrade are the same idempotent operation: apply pending
 * migrations, then insert any missing default data.
 *
 * Uninstall keeps all data by default. Removing data requires an explicit
 * request, and removing financial records additionally requires the operator's
 * typed confirmation (see marketplace_deactivate).
 */
final class Installer
{
    public const REMOVE_DATA_CONFIRMATION = 'DELETE MARKETPLACE DATA';

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly string $migrationsPath,
        private readonly string $configPath,
    ) {
    }

    /**
     * @return array{migrated: list<string>, seeded: array<string, int>}
     */
    public function install(): array
    {
        $migrated = $this->migrator()->migrate();
        $seeded = (new DatabaseSeeder($this->db, $this->configPath))->run();

        return ['migrated' => $migrated, 'seeded' => $seeded];
    }

    /**
     * @return array{migrated: list<string>, seeded: array<string, int>}
     */
    public function upgrade(): array
    {
        return $this->install();
    }

    /**
     * Removes every marketplace table. Refuses when financial tables hold rows
     * unless $forceDestroyFinancialData is true.
     *
     * @return list<string> migrations rolled back
     */
    public function removeAllData(bool $forceDestroyFinancialData): array
    {
        $migrator = $this->migrator();
        $rolledBack = $migrator->reset($forceDestroyFinancialData);
        $migrator->dropRepository();

        return $rolledBack;
    }

    public function isInstalled(): bool
    {
        return $this->db->schema()->hasTable(Table::name(Migrator::REPOSITORY_TABLE));
    }

    public function migrator(): Migrator
    {
        return new Migrator($this->db, $this->migrationsPath);
    }
}
