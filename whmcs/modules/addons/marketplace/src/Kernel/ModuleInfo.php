<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Kernel;

/**
 * Static module metadata and filesystem locations.
 */
final class ModuleInfo
{
    public const NAME = 'Marketplace';
    public const SYSTEM_NAME = 'marketplace';
    public const VERSION = '0.2.0';
    public const AUTHOR = 'WHMCS Marketplace';
    public const MIN_PHP = '8.2.0';

    public static function moduleDir(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function migrationsDir(): string
    {
        return self::moduleDir() . '/migrations';
    }

    public static function configDir(): string
    {
        return self::moduleDir() . '/config';
    }
}
