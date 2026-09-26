<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Infrastructure\Persistence\Schema;

final class MigrationException extends \RuntimeException
{
    public static function failed(string $migration, \Throwable $previous, ?\Throwable $cleanupError = null): self
    {
        $message = "Migration {$migration} failed: {$previous->getMessage()}";
        if ($cleanupError !== null) {
            $message .= " Cleanup of the partial migration also failed: {$cleanupError->getMessage()}"
                . ' Manual inspection of the database is required before retrying.';
        }

        return new self($message, 0, $previous);
    }

    public static function financialDataPresent(string $migration, string $table): self
    {
        return new self(
            "Refusing to roll back {$migration}: table {$table} contains financial records. "
            . 'Export the data first, then re-run with the force-destroy-financial-data option.'
        );
    }

    /**
     * @param list<string> $tables
     */
    public static function tablesAlreadyExist(string $migration, array $tables): self
    {
        return new self(
            "Cannot run {$migration}: table(s) " . implode(', ', $tables) . ' already exist but the migration '
            . 'is not recorded as applied. Nothing was changed. Inspect these tables (they may be left over from '
            . 'a manual or interrupted install), back them up, and remove them before retrying.'
        );
    }

    public static function lockUnavailable(): self
    {
        return new self('Another marketplace migration is already running (could not acquire lock).');
    }

    public static function invalidFile(string $file): self
    {
        return new self("Migration file {$file} must return an instance of " . Migration::class . '.');
    }
}
