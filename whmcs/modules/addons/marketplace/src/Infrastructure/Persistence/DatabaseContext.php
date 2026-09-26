<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Infrastructure\Persistence;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;

/**
 * Access to the database connection and schema builder.
 *
 * Inside WHMCS this wraps WHMCS\Database\Capsule (the documented Capsule DBAL).
 * The standalone implementation is used by the CLI outside WHMCS and by tests.
 * Nothing outside Infrastructure\Persistence may depend on Capsule directly.
 */
interface DatabaseContext
{
    public function connection(): Connection;

    public function schema(): Builder;
}
