<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Infrastructure\Persistence\Seeding;

use WhmcsMarketplace\Infrastructure\Persistence\DatabaseContext;

/**
 * Runs every default-data seeder in one transaction: either all defaults are
 * written, or none are.
 */
final class DatabaseSeeder
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly string $configPath,
    ) {
    }

    /**
     * @return array<string, int> rows inserted per seeder
     */
    public function run(): array
    {
        $seeders = [
            'roles_permissions' => new RolesAndPermissionsSeeder($this->db, $this->configPath),
            'catalog' => new CatalogSeeder($this->db, $this->configPath),
            'configuration' => new ConfigurationSeeder($this->db, $this->configPath),
        ];

        return $this->db->connection()->transaction(static function () use ($seeders): array {
            $results = [];
            foreach ($seeders as $name => $seeder) {
                $results[$name] = $seeder->run();
            }

            return $results;
        });
    }
}
