<?php

declare(strict_types=1);

namespace WhmcsMarketplace\Infrastructure\Persistence;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;

/**
 * Database context for running outside WHMCS: the development CLI and the test
 * suite. It requires illuminate/database to be installed (a dev dependency), and
 * is never used by the module at runtime inside WHMCS.
 */
final class StandaloneDatabaseContext implements DatabaseContext
{
    private Manager $manager;

    /**
     * @param array{host?:string,port?:int|string,database:string,username:string,password?:string,socket?:string} $config
     */
    public function __construct(array $config)
    {
        $this->manager = new Manager();
        $this->manager->addConnection([
            'driver' => 'mysql',
            'host' => $config['host'] ?? '127.0.0.1',
            'port' => (int) ($config['port'] ?? 3306),
            'unix_socket' => $config['socket'] ?? '',
            'database' => $config['database'],
            'username' => $config['username'],
            'password' => $config['password'] ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);
    }

    /**
     * Builds a context from MKP_DB_* environment variables.
     */
    public static function fromEnvironment(): self
    {
        $database = getenv('MKP_DB_DATABASE');
        if ($database === false || $database === '') {
            throw new \RuntimeException('MKP_DB_DATABASE is not set; cannot connect outside WHMCS.');
        }

        return new self([
            'host' => getenv('MKP_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('MKP_DB_PORT') ?: 3306,
            'socket' => getenv('MKP_DB_SOCKET') ?: '',
            'database' => $database,
            'username' => getenv('MKP_DB_USERNAME') ?: 'root',
            'password' => getenv('MKP_DB_PASSWORD') ?: '',
        ]);
    }

    public function connection(): Connection
    {
        return $this->manager->getConnection();
    }

    public function schema(): Builder
    {
        return $this->connection()->getSchemaBuilder();
    }
}
