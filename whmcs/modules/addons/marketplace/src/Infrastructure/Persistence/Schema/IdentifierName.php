<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Infrastructure\Persistence\Schema;

/**
 * Deterministic names for indexes and foreign keys.
 *
 * MySQL/MariaDB limit identifiers to 64 characters. Laravel's automatic names
 * ("{table}_{columns}_index") routinely exceed that with the mod_marketplace_
 * prefix, so every index and FK in our migrations is named through this class.
 */
final class IdentifierName
{
    public const MAX_LENGTH = 64;

    /**
     * @param list<string> $columns
     */
    public static function for(string $type, string $table, array $columns): string
    {
        $short = str_starts_with($table, 'mod_marketplace_') ? substr($table, strlen('mod_marketplace_')) : $table;
        $base = 'mkp_' . $short . '_' . implode('_', $columns) . '_' . $type;

        if (strlen($base) <= self::MAX_LENGTH) {
            return $base;
        }

        // Keep a readable prefix and make it unique with a hash of the full name.
        return substr($base, 0, self::MAX_LENGTH - 11) . '_' . substr(sha1($base), 0, 10);
    }
}
