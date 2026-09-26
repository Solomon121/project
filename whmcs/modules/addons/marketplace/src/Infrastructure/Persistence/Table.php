<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Infrastructure\Persistence;

/**
 * Marketplace table naming. All marketplace tables share the WHMCS addon prefix
 * so they are easy to identify, back up and remove.
 */
final class Table
{
    public const PREFIX = 'mod_marketplace_';

    public static function name(string $shortName): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $shortName)) {
            throw new \InvalidArgumentException("Invalid table short name: {$shortName}");
        }

        return self::PREFIX . $shortName;
    }
}
