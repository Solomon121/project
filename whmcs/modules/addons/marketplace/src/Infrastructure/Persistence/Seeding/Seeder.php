<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Infrastructure\Persistence\Seeding;

use Illuminate\Database\Query\Builder;
use WhmcsMarketplace\Infrastructure\Persistence\DatabaseContext;
use WhmcsMarketplace\Infrastructure\Persistence\Table;

/**
 * Base class for default-data seeders.
 *
 * Seeders are idempotent and insert-only: they add rows that are missing (by
 * natural key) and never modify existing rows, so administrator changes survive
 * module upgrades.
 */
abstract class Seeder
{
    public function __construct(
        protected readonly DatabaseContext $db,
        protected readonly string $configPath,
    ) {
    }

    /**
     * @return int number of rows inserted
     */
    abstract public function run(): int;

    protected function table(string $shortName): Builder
    {
        return $this->db->connection()->table(Table::name($shortName));
    }

    /** @return array<mixed> */
    protected function config(string $name): array
    {
        $file = rtrim($this->configPath, '/') . '/' . $name . '.php';
        $data = require $file;
        if (!is_array($data)) {
            throw new \UnexpectedValueException("Config file {$name}.php must return an array.");
        }

        return $data;
    }

    protected function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
