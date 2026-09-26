<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Infrastructure\Persistence;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use WHMCS\Database\Capsule;

/**
 * Database context backed by WHMCS's Capsule. Only valid inside a WHMCS runtime
 * (addon module functions, hooks, or a script that has required init.php).
 */
final class WhmcsDatabaseContext implements DatabaseContext
{
    public function connection(): Connection
    {
        return Capsule::connection();
    }

    public function schema(): Builder
    {
        return Capsule::schema();
    }
}
