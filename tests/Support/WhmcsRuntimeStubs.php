<?php

/**
 * Minimal stand-ins for the WHMCS runtime used by the module entry points
 * (marketplace.php) in tests. They mirror only the documented API surface we
 * call: WHMCS\Database\Capsule::{connection,schema,table} and logActivity().
 *
 * Tests using these are "tested against stubs"; behaviour on a real WHMCS
 * installation still has to be verified there (docs/architecture/11 §2).
 */

declare(strict_types=1);

namespace WHMCS\Database {

    use Illuminate\Database\Connection;
    use Illuminate\Database\Query\Builder as QueryBuilder;
    use Illuminate\Database\Schema\Builder as SchemaBuilder;
    use WhmcsMarketplace\Infrastructure\Persistence\DatabaseContext;

    if (!class_exists(Capsule::class, false)) {
        final class Capsule
        {
            public static ?DatabaseContext $context = null;

            public static function connection(): Connection
            {
                return self::context()->connection();
            }

            public static function schema(): SchemaBuilder
            {
                return self::context()->schema();
            }

            public static function table(string $table): QueryBuilder
            {
                return self::context()->connection()->table($table);
            }

            private static function context(): DatabaseContext
            {
                if (self::$context === null) {
                    throw new \LogicException('Test Capsule stub has no database context.');
                }

                return self::$context;
            }
        }
    }
}

namespace {
    if (!defined('WHMCS')) {
        define('WHMCS', true);
    }

    if (!function_exists('logActivity')) {
        /** @var list<string> $GLOBALS['__mkp_activity_log'] */
        $GLOBALS['__mkp_activity_log'] = [];

        function logActivity(string $message): void
        {
            $GLOBALS['__mkp_activity_log'][] = $message;
        }
    }
}
