<?php

declare(strict_types=1);

use Digit7s\PulseCacheHealth\Enums\CacheStorageInspectionStatus;
use Digit7s\PulseCacheHealth\Enums\CacheStorageMetricAvailability;
use Digit7s\PulseCacheHealth\Inspectors\MySqlCacheStorageInspector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('collects table metadata when tablespace rows are hidden from a restricted account', function (): void {
    $connection = DB::connection('mysql_integration');
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    $result = app(MySqlCacheStorageInspector::class)->inspect();
    $queries = $connection->getQueryLog();

    expect($result->status)->toBe(CacheStorageInspectionStatus::Partial)
        ->and($result->databaseDriver)->toBe('mysql')
        ->and($result->schemaName)->toBe($this->mysqlSettings->database)
        ->and($result->cacheTable)->toBe('cache_fixture_'.$this->mysqlSettings->tokenSuffix())
        ->and($result->resolvedCacheTable)->toBe('it_cache_fixture_'.$this->mysqlSettings->tokenSuffix())
        ->and($result->engine->value)->toBe('InnoDB')
        ->and($result->dataLengthBytes->value)->toBeInt()
        ->and($result->indexLengthBytes->value)->toBeInt()
        ->and($result->estimatedAllocationBytes->value)
        ->toBe($result->dataLengthBytes->value + $result->indexLengthBytes->value)
        ->and($result->tablespaceId->value)->toBeNull()
        ->and($result->tablespaceId->availability)->toBe(CacheStorageMetricAvailability::PermissionDenied)
        ->and($result->errorCategory)->toBe('permission_denied')
        ->and($queries)->toHaveCount(1);

    expect($queries[0]['query'])->toContain('FROM INFORMATION_SCHEMA.TABLES')
        ->and($queries[0]['bindings'])->toBe([
            $this->mysqlSettings->database,
            'it_cache_fixture_'.$this->mysqlSettings->tokenSuffix(),
        ]);

    foreach ($queries as $query) {
        expect(str_starts_with(strtolower(ltrim($query['query'])), 'select'))->toBeTrue()
            ->and(strtolower($query['query']))
            ->not->toMatch('/\b(delete|update|insert|replace|truncate|optimize|analyze)\b/');
    }
});

it('maps a file-per-table tablespace and returns MySQL numeric values with PROCESS', function (): void {
    $this->configureProcessPrivilegeConnection();

    $result = app(MySqlCacheStorageInspector::class)->inspect();

    expect($result->status)->toBeIn([CacheStorageInspectionStatus::Success, CacheStorageInspectionStatus::Partial])
        ->and($result->tablespaceType->value)->toBe('Single')
        ->and($result->tablespaceId->value)->toBeInt()
        ->and($result->tablespaceName->value)->toBeString()
        ->and($result->fileSizeBytes->value === null || is_int($result->fileSizeBytes->value))->toBeTrue()
        ->and($result->allocatedSizeBytes->value === null || is_int($result->allocatedSizeBytes->value))->toBeTrue()
        ->and($result->clusterStorageBytes->value)->toBeNull();

    $connection = DB::connection('mysql_integration_process');
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    app(MySqlCacheStorageInspector::class)->inspect();
    $queries = $connection->getQueryLog();

    expect($queries)->toHaveCount(2)
        ->and($queries[0]['query'])->toContain('FROM INFORMATION_SCHEMA.TABLES')
        ->and($queries[0]['bindings'])->toBe([
            $this->mysqlSettings->database,
            'it_cache_fixture_'.$this->mysqlSettings->tokenSuffix(),
        ])
        ->and($queries[1]['query'])->toContain('it.NAME = CONCAT(?, \'/\', ?)')
        ->and($queries[1]['query'])->toContain('ts.SPACE = it.SPACE')
        ->and($queries[1]['query'])->toContain('MAX_EXECUTION_TIME(1000)')
        ->and($queries[1]['bindings'])->toBe([
            $this->mysqlSettings->database,
            'it_cache_fixture_'.$this->mysqlSettings->tokenSuffix(),
        ]);

    foreach ($queries as $query) {
        expect(str_starts_with(strtolower(ltrim($query['query'])), 'select'))->toBeTrue()
            ->and(strtolower($query['query']))
            ->not->toMatch('/\b(delete|update|insert|replace|truncate|optimize|analyze)\b/')
            ->and(strtolower($query['query']))
            ->not->toMatch('/\b(select\s+\*|count\s*\(|octet_length\s*\()/');

        $explain = $connection->select('EXPLAIN '.$query['query'], $query['bindings']);
        expect($explain)->not->toBeEmpty();

        $plan = json_encode($explain, JSON_THROW_ON_ERROR);
        expect($plan)->not->toContain('Table scan on `it_cache_fixture_'.$this->mysqlSettings->tokenSuffix().'`');
    }
});

it('reports an absent configured table as missing or metadata-invisible', function (): void {
    $missingName = 'missing_fixture_'.$this->mysqlSettings->tokenSuffix();
    config()->set('cache.stores.mysql_integration_cache.table', $missingName);

    $result = app(MySqlCacheStorageInspector::class)->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::TableMissingOrNotVisible)
        ->and($result->errorCategory)->toBe('table_missing_or_not_visible');
});

it('inspects an empty synthetic InnoDB table without reading or counting its rows', function (): void {
    $logicalName = 'empty_fixture_'.$this->mysqlSettings->tokenSuffix();
    $this->createFixtureTable($logicalName, populate: false);
    config()->set('cache.stores.mysql_integration_cache.table', $logicalName);

    $result = app(MySqlCacheStorageInspector::class)->inspect();

    expect($result->engine->value)->toBe('InnoDB')
        ->and($result->dataLengthBytes->value)->toBeInt()
        ->and($result->indexLengthBytes->value)->toBeInt()
        ->and($result->estimatedAllocationBytes->value)
        ->toBe($result->dataLengthBytes->value + $result->indexLengthBytes->value);
});

it('supports valid special-character table names through Laravel table prefixing', function (): void {
    $logicalName = 'cache-dash-'.$this->mysqlSettings->tokenSuffix();
    $this->createFixtureTable($logicalName);
    config()->set('cache.stores.mysql_integration_cache.table', $logicalName);

    $result = app(MySqlCacheStorageInspector::class)->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::Partial)
        ->and($result->cacheTable)->toBe($logicalName)
        ->and($result->resolvedCacheTable)->toBe('it_'.$logicalName)
        ->and($result->engine->value)->toBe('InnoDB');
});

it('reports a non-InnoDB cache table without issuing an InnoDB dictionary query', function (): void {
    $logicalName = 'myisam_fixture_'.$this->mysqlSettings->tokenSuffix();
    $this->fixtureTables[] = $logicalName;
    Schema::connection('mysql_integration')->create($logicalName, function (Blueprint $table): void {
        $table->engine = 'MyISAM';
        $table->string('cache_key', 191)->primary();
        $table->binary('value');
        $table->unsignedBigInteger('expiration');
    });
    config()->set('cache.stores.mysql_integration_cache.table', $logicalName);

    $connection = DB::connection('mysql_integration');
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    $result = app(MySqlCacheStorageInspector::class)->inspect();
    $queries = $connection->getQueryLog();

    expect($result->status)->toBe(CacheStorageInspectionStatus::Partial)
        ->and($result->engine->value)->toBe('MyISAM')
        ->and($result->tablespaceType->availability)->toBe(CacheStorageMetricAvailability::Unsupported)
        ->and($queries)->toHaveCount(1)
        ->and($queries[0]['query'])->toContain('INFORMATION_SCHEMA.TABLES');
});

it('does not attribute shared tablespace size when MySQL exposes a shared-space fixture', function (): void {
    $logicalName = 'system_space_fixture_'.$this->mysqlSettings->tokenSuffix();
    $this->fixtureTables[] = $logicalName;
    DB::connection('mysql_integration')->statement(
        'CREATE TABLE `it_'.$logicalName.'` (cache_key VARCHAR(191) PRIMARY KEY, value LONGBLOB, expiration BIGINT UNSIGNED) ENGINE=InnoDB TABLESPACE=innodb_system',
    );
    config()->set('cache.stores.mysql_integration_cache.table', $logicalName);
    $this->configureProcessPrivilegeConnection();
    config()->set('cache.stores.mysql_integration_process_cache.table', $logicalName);

    $space = DB::connection('mysql_integration_process')->selectOne(
        'SELECT SPACE_TYPE FROM INFORMATION_SCHEMA.INNODB_TABLES WHERE NAME = CONCAT(?, \'/\', ?)',
        [$this->mysqlSettings->database, 'it_'.$logicalName],
    );

    if (! in_array($space->SPACE_TYPE ?? null, ['System', 'General'], true)) {
        $this->markTestSkipped('This MySQL build kept the fixture in a Single tablespace; no shared tablespace was reproduced.');
    }

    $result = app(MySqlCacheStorageInspector::class)->inspect();

    expect($result->status)->toBe(CacheStorageInspectionStatus::Partial)
        ->and($result->tablespaceType->value)->toBeIn(['System', 'General'])
        ->and($result->fileSizeBytes->value)->toBeNull()
        ->and($result->allocatedSizeBytes->value)->toBeNull()
        ->and($result->errorCategory)->toBe('shared_tablespace_size_not_attributable');
});
