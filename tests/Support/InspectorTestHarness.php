<?php

declare(strict_types=1);

namespace Digit7s\PulseCacheHealth\Tests\Support;

use Digit7s\PulseCacheHealth\Inspectors\MySqlCacheStorageInspector;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use Throwable;

final class InspectorTestHarness
{
    /**
     * @return array{inspector: MySqlCacheStorageInspector, connection: Connection, calls: array<int, array{string, array}>}
     */
    public static function make(
        array $tableRows = [],
        array $tablespaceRows = [],
        ?Throwable $tableException = null,
        ?Throwable $tablespaceException = null,
        string $storeName = 'database',
        string $connectionName = 'mysql-cache',
        string $schema = 'cache_test',
        string $tablePrefix = '',
        string $databaseDriver = 'mysql',
        ?Throwable $resolutionException = null,
    ): array {
        config()->set('pulse-cache-health.enabled', true);
        config()->set('pulse-cache-health.collection.enabled', true);
        config()->set('pulse-cache-health.cache_store', $storeName === 'database' ? null : $storeName);
        config()->set('cache.default', $storeName);
        config()->set("cache.stores.{$storeName}", [
            'driver' => 'database',
            'connection' => $connectionName,
            'table' => 'cache',
        ]);
        config()->set('database.default', $connectionName);

        $calls = [];
        $cacheFactory = \Mockery::mock(CacheFactory::class);
        $repository = \Mockery::mock(Repository::class);
        $databaseStore = \Mockery::mock(DatabaseStore::class);
        $connection = \Mockery::mock(Connection::class);

        $cacheFactory->shouldReceive('store')->once()->with($storeName)->andReturn($repository);
        if ($resolutionException !== null) {
            $repository->shouldReceive('getStore')->once()->andThrow($resolutionException);
        } else {
            $repository->shouldReceive('getStore')->once()->andReturn($databaseStore);
            $databaseStore->shouldReceive('getConnection')->once()->andReturn($connection);
        }
        $connection->shouldReceive('getName')->andReturn($connectionName);
        $connection->shouldReceive('getDatabaseName')->andReturn($schema);
        $connection->shouldReceive('getTablePrefix')->andReturn($tablePrefix);
        $connection->shouldReceive('getDriverName')->andReturn($databaseDriver);
        $connection->shouldReceive('select')->zeroOrMoreTimes()->andReturnUsing(
            function (string $query, array $bindings) use (&$calls, $tableRows, $tablespaceRows, $tableException, $tablespaceException): array {
                $calls[] = [$query, $bindings];

                if (str_contains($query, 'FROM INFORMATION_SCHEMA.TABLES')) {
                    if ($tableException !== null) {
                        throw $tableException;
                    }

                    return $tableRows;
                }

                if (str_contains($query, 'INFORMATION_SCHEMA.INNODB_TABLES')) {
                    if ($tablespaceException !== null) {
                        throw $tablespaceException;
                    }

                    return $tablespaceRows;
                }

                throw new \RuntimeException('Unexpected query issued by inspector.');
            },
        );

        return [
            'inspector' => new MySqlCacheStorageInspector($cacheFactory, app('config')),
            'connection' => $connection,
            'calls' => &$calls,
        ];
    }
}
