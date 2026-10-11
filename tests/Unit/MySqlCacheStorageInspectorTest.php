<?php

declare(strict_types=1);

use Digit7s\PulseCacheHealth\Enums\CacheStorageInspectionStatus;
use Digit7s\PulseCacheHealth\Enums\CacheStorageMetricAvailability;
use Digit7s\PulseCacheHealth\Inspectors\MySqlCacheStorageInspector;
use Digit7s\PulseCacheHealth\Tests\Support\InspectorTestHarness;
use Digit7s\PulseCacheHealth\Tests\Support\MySqlIntegrationSettings;
use Digit7s\PulseCacheHealth\ValueObjects\CacheStorageMetric;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

it('does no cache resolution or metadata query while collection is disabled', function (): void {
    $cache = Mockery::mock(CacheFactory::class);
    $cache->shouldNotReceive('store');
    config()->set('pulse-cache-health.enabled', true);

    $result = (new MySqlCacheStorageInspector($cache, app('config')))->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::Disabled)
        ->and($result->dataLengthBytes->value)->toBeNull()
        ->and($result->dataLengthBytes->availability)->toBe(CacheStorageMetricAvailability::Disabled);
});

it('requires boolean true rather than a truthy string to enable collection', function (): void {
    $cache = Mockery::mock(CacheFactory::class);
    $cache->shouldNotReceive('store');
    config()->set('pulse-cache-health.enabled', true);
    config()->set('pulse-cache-health.collection.enabled', 'false');

    $result = (new MySqlCacheStorageInspector($cache, app('config')))->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::Disabled);
});

it('allows only the explicitly gated GitHub MySQL service outside loopback', function (): void {
    $keys = [
        'GITHUB_ACTIONS',
        'PULSE_CACHE_HEALTH_MYSQL_CI_SERVICE',
        'PULSE_CACHE_HEALTH_MYSQL_TESTS',
        'PULSE_CACHE_HEALTH_MYSQL_TEST_TOKEN',
        'PULSE_CACHE_HEALTH_MYSQL_TEST_HOST',
        'PULSE_CACHE_HEALTH_MYSQL_TEST_PORT',
        'PULSE_CACHE_HEALTH_MYSQL_TEST_DATABASE',
        'PULSE_CACHE_HEALTH_MYSQL_TEST_USERNAME',
        'PULSE_CACHE_HEALTH_MYSQL_TEST_PASSWORD',
        'PULSE_CACHE_HEALTH_MYSQL_PROCESS_USERNAME',
        'PULSE_CACHE_HEALTH_MYSQL_PROCESS_PASSWORD',
        'PULSE_CACHE_HEALTH_MYSQL_EXPECTED_DATADIR',
        'PULSE_CACHE_HEALTH_MYSQL_EXPECTED_VERSION',
    ];
    $previous = [];

    foreach ($keys as $key) {
        $previous[$key] = getenv($key);
    }

    $token = '9a34c8f3d2a1a9e84d8c0a2f764abc10';
    $suffix = substr($token, 0, 12);
    putenv('GITHUB_ACTIONS=false');
    putenv('PULSE_CACHE_HEALTH_MYSQL_CI_SERVICE=0');
    putenv('PULSE_CACHE_HEALTH_MYSQL_TESTS=1');
    putenv("PULSE_CACHE_HEALTH_MYSQL_TEST_TOKEN={$token}");
    putenv('PULSE_CACHE_HEALTH_MYSQL_TEST_HOST=mysql');
    putenv('PULSE_CACHE_HEALTH_MYSQL_TEST_PORT=3306');
    putenv("PULSE_CACHE_HEALTH_MYSQL_TEST_DATABASE=pulse_cache_health_test_{$suffix}");
    putenv("PULSE_CACHE_HEALTH_MYSQL_TEST_USERNAME=pch_it_{$suffix}");
    putenv('PULSE_CACHE_HEALTH_MYSQL_TEST_PASSWORD=test-password');
    putenv("PULSE_CACHE_HEALTH_MYSQL_PROCESS_USERNAME=pch_proc_{$suffix}");
    putenv('PULSE_CACHE_HEALTH_MYSQL_PROCESS_PASSWORD=process-password');
    putenv('PULSE_CACHE_HEALTH_MYSQL_EXPECTED_DATADIR=/var/lib/mysql');
    putenv('PULSE_CACHE_HEALTH_MYSQL_EXPECTED_VERSION=8.4.');

    try {
        expect(fn () => MySqlIntegrationSettings::fromEnvironment())
            ->toThrow(RuntimeException::class, 'host must be loopback or the explicitly enabled GitHub MySQL service');

        putenv('GITHUB_ACTIONS=true');
        putenv('PULSE_CACHE_HEALTH_MYSQL_CI_SERVICE=1');
        $settings = MySqlIntegrationSettings::fromEnvironment();

        expect($settings)->toBeInstanceOf(MySqlIntegrationSettings::class)
            ->and($settings->host)->toBe('mysql')
            ->and($settings->port)->toBe(3306);
    } finally {
        foreach ($previous as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
    }
});

it('resolves the Laravel default database cache store and reports typed table metrics', function (): void {
    $harness = InspectorTestHarness::make(
        [(object) ['ENGINE' => 'InnoDB', 'TABLE_ROWS' => '27', 'DATA_LENGTH' => '4096', 'INDEX_LENGTH' => 2048, 'DATA_FREE' => '0']],
        [(object) ['TABLE_ID' => 91, 'SPACE' => 12, 'SPACE_TYPE' => 'Single', 'TABLESPACE_NAME' => 'cache_test/cache', 'FILE_SIZE' => '8192', 'ALLOCATED_SIZE' => '12288']],
    );

    $result = $harness['inspector']->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::Success)
        ->and($result->collectedAt->getTimezone()->getName())->toBe('UTC')
        ->and($result->cacheStore)->toBe('database')
        ->and($result->databaseDriver)->toBe('mysql')
        ->and($result->connectionName)->toBe('mysql-cache')
        ->and($result->schemaName)->toBe('cache_test')
        ->and($result->cacheTable)->toBe('cache')
        ->and($result->engine->value)->toBe('InnoDB')
        ->and($result->estimatedRowCount->value)->toBe(27)
        ->and($result->dataLengthBytes->value)->toBe(4096)
        ->and($result->indexLengthBytes->value)->toBe(2048)
        ->and($result->estimatedAllocationBytes->value)->toBe(6144)
        ->and($result->fileSizeBytes->value)->toBe(8192)
        ->and($result->allocatedSizeBytes->value)->toBe(12288)
        ->and($result->clusterStorageBytes->value)->toBeNull()
        ->and($result->clusterStorageBytes->availability)->toBe(CacheStorageMetricAvailability::Unsupported)
        ->and(count($harness['calls']))->toBe(2);
});

it('uses an explicitly selected database cache store connection and custom prefixed table', function (): void {
    $harness = InspectorTestHarness::make(
        [(object) ['ENGINE' => 'InnoDB', 'TABLE_ROWS' => 2, 'DATA_LENGTH' => 5, 'INDEX_LENGTH' => 3, 'DATA_FREE' => null]],
        [],
        storeName: 'cache_secondary',
        connectionName: 'secondary',
        schema: 'isolated_cache',
        tablePrefix: 'tenant_',
    );
    config()->set('cache.stores.cache_secondary.table', 'custom_cache');

    $result = $harness['inspector']->inspect();

    expect($result->cacheStore)->toBe('cache_secondary')
        ->and($result->connectionName)->toBe('secondary')
        ->and($result->schemaName)->toBe('isolated_cache')
        ->and($result->cacheTable)->toBe('custom_cache')
        ->and($result->resolvedCacheTable)->toBe('tenant_custom_cache')
        ->and($harness['calls'][0][1])->toBe(['isolated_cache', 'tenant_custom_cache']);
});

it('does not resolve unsupported cache stores', function (string $driver): void {
    $cache = Mockery::mock(CacheFactory::class);
    $cache->shouldNotReceive('store');
    config()->set('pulse-cache-health.enabled', true);
    config()->set('pulse-cache-health.collection.enabled', true);
    config()->set('cache.default', 'other');
    config()->set('cache.stores.other', ['driver' => $driver]);

    $result = (new MySqlCacheStorageInspector($cache, app('config')))->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::UnsupportedCacheDriver)
        ->and($result->cacheStore)->toBe('other');
})->with(['redis', 'file', 'array']);

it('reports missing cache-store configuration without resolving a store', function (): void {
    $cache = Mockery::mock(CacheFactory::class);
    $cache->shouldNotReceive('store');
    config()->set('pulse-cache-health.enabled', true);
    config()->set('pulse-cache-health.collection.enabled', true);
    config()->set('cache.default', 'missing');

    $result = (new MySqlCacheStorageInspector($cache, app('config')))->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::MissingConfiguration);
});

it('reports an unconfigured database connection without exposing exception text', function (): void {
    $harness = InspectorTestHarness::make(resolutionException: new InvalidArgumentException('Database connection [secret-name] not configured.'));

    $result = $harness['inspector']->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::MissingConfiguration)
        ->and($result->errorCategory)->toBe('connection_configuration_missing')
        ->and(json_encode($result))->not->toContain('secret-name');
});

it('reports missing or metadata-invisible cache tables without guessing which condition applies', function (): void {
    $harness = InspectorTestHarness::make();
    $result = $harness['inspector']->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::TableMissingOrNotVisible)
        ->and($result->errorCategory)->toBe('table_missing_or_not_visible')
        ->and(count($harness['calls']))->toBe(1)
        ->and($harness['calls'][0][0])->toContain('INFORMATION_SCHEMA.TABLES')
        ->and($harness['calls'][0][0])->not->toContain('COUNT(*)');
});

it('returns table metrics when InnoDB metadata permission is denied', function (): void {
    $denied = new PDOException('permission denied; never expose this detail');
    $denied->errorInfo = ['42000', 1227, 'insufficient privileges'];
    $harness = InspectorTestHarness::make(
        [(object) ['ENGINE' => 'InnoDB', 'TABLE_ROWS' => '8', 'DATA_LENGTH' => '512', 'INDEX_LENGTH' => '256', 'DATA_FREE' => '0']],
        tablespaceException: $denied,
    );

    $result = $harness['inspector']->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::Partial)
        ->and($result->estimatedRowCount->value)->toBe(8)
        ->and($result->estimatedAllocationBytes->value)->toBe(768)
        ->and($result->fileSizeBytes->value)->toBeNull()
        ->and($result->fileSizeBytes->availability)->toBe(CacheStorageMetricAvailability::PermissionDenied)
        ->and($result->errorCategory)->toBe('permission_denied')
        ->and(json_encode($result))->not->toContain('permission denied; never expose this detail');
});

it('keeps tablespace metrics unavailable when the metadata row is absent', function (): void {
    $harness = InspectorTestHarness::make(
        [(object) ['ENGINE' => 'InnoDB', 'TABLE_ROWS' => 1, 'DATA_LENGTH' => 64, 'INDEX_LENGTH' => 0, 'DATA_FREE' => 0]],
    );

    $result = $harness['inspector']->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::Partial)
        ->and($result->dataLengthBytes->value)->toBe(64)
        ->and($result->tablespaceId->value)->toBeNull()
        ->and($result->fileSizeBytes->value)->toBeNull()
        ->and($result->errorCategory)->toBe('tablespace_metadata_unavailable');
});

it('does not attribute shared tablespace file sizes to an individual table', function (): void {
    $harness = InspectorTestHarness::make(
        [(object) ['ENGINE' => 'InnoDB', 'TABLE_ROWS' => 1, 'DATA_LENGTH' => 64, 'INDEX_LENGTH' => 0, 'DATA_FREE' => 0]],
        [(object) ['TABLE_ID' => 91, 'SPACE' => 0, 'SPACE_TYPE' => 'General', 'TABLESPACE_NAME' => 'shared', 'FILE_SIZE' => '999999', 'ALLOCATED_SIZE' => '1000000']],
    );

    $result = $harness['inspector']->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::Partial)
        ->and($result->tablespaceType->value)->toBe('General')
        ->and($result->fileSizeBytes->value)->toBeNull()
        ->and($result->fileSizeBytes->availability)->toBe(CacheStorageMetricAvailability::Unsupported)
        ->and($result->allocatedSizeBytes->value)->toBeNull();
});

it('classifies non-MySQL database cache connections as unsupported', function (): void {
    $harness = InspectorTestHarness::make(databaseDriver: 'sqlite');

    $result = $harness['inspector']->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::UnsupportedDatabaseDriver)
        ->and($harness['calls'])->toBeEmpty();
});

it('rejects qualified table names rather than treating them as schema configuration', function (): void {
    $harness = InspectorTestHarness::make();
    config()->set('cache.stores.database.table', 'other.cache');

    $result = $harness['inspector']->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::UnsupportedSchemaConfiguration)
        ->and($harness['calls'])->toBeEmpty();
});

it('binds unusual table identifiers as values instead of interpolating them into SQL', function (): void {
    $harness = InspectorTestHarness::make(
        [(object) ['ENGINE' => 'MyISAM', 'TABLE_ROWS' => 1, 'DATA_LENGTH' => 1, 'INDEX_LENGTH' => 1, 'DATA_FREE' => 0]],
    );
    config()->set('cache.stores.database.table', 'cache`archive');

    $result = $harness['inspector']->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::Partial)
        ->and($harness['calls'][0][0])->not->toContain('cache`archive')
        ->and($harness['calls'][0][1])->toBe(['cache_test', 'cache`archive']);
});

it('uses only bounded read-only metadata queries and never reads cache payloads', function (): void {
    $harness = InspectorTestHarness::make(
        [(object) ['ENGINE' => 'InnoDB', 'TABLE_ROWS' => 3, 'DATA_LENGTH' => 128, 'INDEX_LENGTH' => 64, 'DATA_FREE' => 0]],
        [(object) ['SPACE' => 5, 'SPACE_TYPE' => 'Single', 'TABLESPACE_NAME' => 'cache_test/cache', 'FILE_SIZE' => 192, 'ALLOCATED_SIZE' => 256]],
    );

    $harness['inspector']->inspect();

    foreach ($harness['calls'] as [$sql]) {
        expect($sql)->toStartWith('SELECT /*+ MAX_EXECUTION_TIME(1000) */')
            ->and($sql)->not->toMatch('/\b(DELETE|TRUNCATE|UPDATE|INSERT|OPTIMIZE|ANALYZE)\b/i')
            ->and($sql)->not->toContain('SELECT *')
            ->and($sql)->not->toContain('cache_key')
            ->and($sql)->not->toContain('cache_value');
    }
});

it('sanitizes table metadata query failures', function (): void {
    $failure = new PDOException('mysql://secret-user:secret-password@host/db raw query secret');
    $failure->errorInfo = ['HY000', 2002, 'connection refused'];
    $harness = InspectorTestHarness::make(tableException: $failure);

    $result = $harness['inspector']->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::DatabaseUnavailable)
        ->and($result->errorCategory)->toBe('database_unavailable')
        ->and($result->cacheTable)->toBe('cache')
        ->and(json_encode($result))->not->toContain('secret-password')
        ->and(json_encode($result))->not->toContain('raw query secret');
});

it('keeps unavailable numeric metadata null instead of converting it to zero', function (): void {
    $harness = InspectorTestHarness::make(
        [(object) ['ENGINE' => 'InnoDB', 'TABLE_ROWS' => null, 'DATA_LENGTH' => 'not-a-number', 'INDEX_LENGTH' => 0, 'DATA_FREE' => null]],
        [(object) ['SPACE' => null, 'SPACE_TYPE' => 'Single', 'TABLESPACE_NAME' => 'cache_test/cache', 'FILE_SIZE' => null, 'ALLOCATED_SIZE' => 'overflow-999999999999999999999999999']],
    );

    $result = $harness['inspector']->inspect();

    expect($result->estimatedRowCount->value)->toBeNull()
        ->and($result->dataLengthBytes->value)->toBeNull()
        ->and($result->estimatedAllocationBytes->value)->toBeNull()
        ->and($result->fileSizeBytes->value)->toBeNull()
        ->and($result->tablespaceId->value)->toBeNull()
        ->and(CacheStorageMetric::unavailable('test')->value)->toBeNull();
});

it('reports a permission error for the base table metadata query', function (): void {
    $denied = new PDOException('access denied');
    $denied->errorInfo = ['42000', 1142, 'access denied'];
    $harness = InspectorTestHarness::make(tableException: $denied);

    $result = $harness['inspector']->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::PermissionDenied)
        ->and($result->errorCategory)->toBe('permission_denied');
});

it('returns table metrics when optional tablespace inspection is disabled', function (): void {
    $harness = InspectorTestHarness::make(
        [(object) ['ENGINE' => 'InnoDB', 'TABLE_ROWS' => 1, 'DATA_LENGTH' => 64, 'INDEX_LENGTH' => 0, 'DATA_FREE' => 0]],
    );
    config()->set('pulse-cache-health.metadata.tablespaces', false);

    $result = $harness['inspector']->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::Success)
        ->and($result->dataLengthBytes->value)->toBe(64)
        ->and($result->tablespaceId->availability)->toBe(CacheStorageMetricAvailability::Disabled)
        ->and(count($harness['calls']))->toBe(1);
});
