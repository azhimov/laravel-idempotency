<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Tests;

use Azhimov\Idempotency\IdempotencyServiceProvider;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [IdempotencyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');

        // CI runs the database store suite against real servers:
        // IDEMPOTENCY_TEST_DB=mysql|pgsql plus DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD.
        $driver = getenv('IDEMPOTENCY_TEST_DB');

        if ($driver === 'mysql' || $driver === 'pgsql') {
            $app['config']->set('database.default', $driver);
            $app['config']->set("database.connections.{$driver}", [
                'driver' => $driver,
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: ($driver === 'mysql' ? '3306' : '5432'),
                'database' => getenv('DB_DATABASE') ?: 'idempotency',
                'username' => getenv('DB_USERNAME') ?: ($driver === 'mysql' ? 'root' : 'postgres'),
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
                'collation' => $driver === 'mysql' ? 'utf8mb4_unicode_ci' : null,
                'prefix' => '',
                'schema' => 'public',
                'sslmode' => 'prefer',
            ]);
        }
    }
}
