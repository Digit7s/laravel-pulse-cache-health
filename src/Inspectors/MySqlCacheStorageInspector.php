<?php

declare(strict_types=1);

namespace Digit7s\PulseCacheHealth\Inspectors;

use DateTimeImmutable;
use DateTimeZone;
use Digit7s\PulseCacheHealth\Enums\CacheStorageInspectionStatus;
use Digit7s\PulseCacheHealth\Enums\CacheStorageMetricAvailability;
use Digit7s\PulseCacheHealth\ValueObjects\CacheStorageInspectionResult;
use Digit7s\PulseCacheHealth\ValueObjects\CacheStorageMetric;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use Throwable;

/**
 * Reads bounded MySQL metadata for the configured Laravel database cache table.
 *
 * This class is deliberately not registered for automatic execution. Consumers
 * must call inspect() from an explicitly managed background process.
 */
final class MySqlCacheStorageInspector
{
    private const TABLE_PROVENANCE = 'mysql.information_schema.tables';

    private const TABLESPACE_PROVENANCE = 'mysql.information_schema.innodb_tables_and_tablespaces';

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly ConfigRepository $config,
    ) {}

    public function inspect(): CacheStorageInspectionResult
    {
        $collectedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($this->config->get('pulse-cache-health.enabled', false) !== true
            || $this->config->get('pulse-cache-health.collection.enabled', false) !== true) {
            return $this->emptyResult(
                $collectedAt,
                CacheStorageInspectionStatus::Disabled,
                CacheStorageMetricAvailability::Disabled,
            );
        }

        $storeName = $this->config->get('pulse-cache-health.cache_store')
            ?? $this->config->get('cache.default');

        if (! is_string($storeName) || trim($storeName) === '') {
            return $this->emptyResult($collectedAt, CacheStorageInspectionStatus::MissingConfiguration);
        }

        $storeConfig = $this->config->get("cache.stores.{$storeName}");

        if (! is_array($storeConfig) || ! isset($storeConfig['driver']) || ! is_string($storeConfig['driver'])) {
            return $this->emptyResult(
                $collectedAt,
                CacheStorageInspectionStatus::MissingConfiguration,
                storeName: $storeName,
            );
        }

        if ($storeConfig['driver'] !== 'database') {
            return $this->emptyResult(
                $collectedAt,
                CacheStorageInspectionStatus::UnsupportedCacheDriver,
                storeName: $storeName,
            );
        }

        $configuredTable = $storeConfig['table'] ?? null;

        if (! is_string($configuredTable) || trim($configuredTable) === '') {
            return $this->emptyResult(
                $collectedAt,
                CacheStorageInspectionStatus::MissingConfiguration,
                storeName: $storeName,
            );
        }

        try {
            $store = $this->cache->store($storeName)->getStore();

            if (! $store instanceof DatabaseStore) {
                return $this->emptyResult(
                    $collectedAt,
                    CacheStorageInspectionStatus::UnsupportedCacheDriver,
                    storeName: $storeName,
                );
            }

            $connection = $store->getConnection();

            if (! $connection instanceof Connection) {
                return $this->emptyResult(
                    $collectedAt,
                    CacheStorageInspectionStatus::UnsupportedDatabaseDriver,
                    storeName: $storeName,
                );
            }
        } catch (Throwable $exception) {
            return $this->emptyResult(
                $collectedAt,
                $this->classifyException($exception),
                storeName: $storeName,
                errorCategory: $this->errorCategory($exception),
            );
        }

        $schema = $connection->getDatabaseName();
        $tablePrefix = $connection->getTablePrefix();
        $effectiveTable = $tablePrefix.$configuredTable;
        $driver = $connection->getDriverName();
        $connectionName = $connection->getName();

        $context = [
            'storeName' => $storeName,
            'databaseDriver' => is_string($driver) ? $driver : null,
            'connectionName' => is_string($connectionName) && $connectionName !== ''
                ? $connectionName
                : ($storeConfig['connection'] ?? $this->config->get('database.default')),
            'schemaName' => is_string($schema) ? $schema : null,
            'cacheTable' => $configuredTable,
            'resolvedCacheTable' => $effectiveTable,
        ];

        if (! is_string($schema) || $schema === '' || ! is_string($driver) || $driver === '') {
            return $this->emptyResult(
                $collectedAt,
                CacheStorageInspectionStatus::MissingConfiguration,
                ...$context,
            );
        }

        if ($driver !== 'mysql') {
            return $this->emptyResult(
                $collectedAt,
                CacheStorageInspectionStatus::UnsupportedDatabaseDriver,
                ...$context,
            );
        }

        if (str_contains($configuredTable, '.') || str_contains($effectiveTable, '.')) {
            return $this->emptyResult(
                $collectedAt,
                CacheStorageInspectionStatus::UnsupportedSchemaConfiguration,
                ...$context,
            );
        }

        if (str_contains($configuredTable, "\0")
            || str_contains($effectiveTable, "\0")
            || strlen($effectiveTable) > 64) {
            return $this->emptyResult(
                $collectedAt,
                CacheStorageInspectionStatus::InvalidTableIdentifier,
                ...$context,
            );
        }

        $timeoutMs = $this->queryTimeoutMs();

        if ($timeoutMs === null) {
            return $this->emptyResult(
                $collectedAt,
                CacheStorageInspectionStatus::MissingConfiguration,
                ...$context,
                errorCategory: 'invalid_query_timeout_configuration',
            );
        }

        try {
            $tableRows = $connection->select(
                $this->tableMetadataQuery($timeoutMs),
                [$schema, $effectiveTable],
            );
        } catch (Throwable $exception) {
            return $this->emptyResult(
                $collectedAt,
                $this->classifyException($exception),
                ...$context,
                errorCategory: $this->errorCategory($exception),
            );
        }

        if ($tableRows === []) {
            return $this->emptyResult(
                $collectedAt,
                CacheStorageInspectionStatus::TableMissingOrNotVisible,
                ...$context,
                errorCategory: 'table_missing_or_not_visible',
            );
        }

        $tableMetadata = $tableRows[0];
        $engine = $this->stringMetric($tableMetadata->ENGINE ?? null, self::TABLE_PROVENANCE.'.ENGINE');
        $rows = $this->integerMetric($tableMetadata->TABLE_ROWS ?? null, self::TABLE_PROVENANCE.'.TABLE_ROWS estimate');
        $dataLength = $this->integerMetric($tableMetadata->DATA_LENGTH ?? null, self::TABLE_PROVENANCE.'.DATA_LENGTH bytes');
        $indexLength = $this->integerMetric($tableMetadata->INDEX_LENGTH ?? null, self::TABLE_PROVENANCE.'.INDEX_LENGTH bytes');
        $dataFree = $this->integerMetric($tableMetadata->DATA_FREE ?? null, self::TABLE_PROVENANCE.'.DATA_FREE bytes');
        $allocation = $this->sumMetrics($dataLength, $indexLength);

        $tableMetrics = [
            'engine' => $engine,
            'estimatedRowCount' => $rows,
            'dataLengthBytes' => $dataLength,
            'indexLengthBytes' => $indexLength,
            'estimatedAllocationBytes' => $allocation,
            'dataFreeBytes' => $dataFree,
        ];

        if ($this->config->get('pulse-cache-health.metadata.tablespaces', true) !== true) {
            $disabled = $this->unavailableTablespaceMetrics(CacheStorageMetricAvailability::Disabled);

            return $this->result(
                $collectedAt,
                CacheStorageInspectionStatus::Success,
                [...$context, ...$tableMetrics, ...$disabled],
            );
        }

        if ($engine->value !== 'InnoDB') {
            $unsupported = $this->unavailableTablespaceMetrics(CacheStorageMetricAvailability::Unsupported);

            return $this->result(
                $collectedAt,
                CacheStorageInspectionStatus::Partial,
                [...$context, ...$tableMetrics, ...$unsupported],
                'tablespace_metrics_require_innodb',
            );
        }

        if (str_contains($effectiveTable, '/')) {
            $unsupported = $this->unavailableTablespaceMetrics(CacheStorageMetricAvailability::Unsupported);

            return $this->result(
                $collectedAt,
                CacheStorageInspectionStatus::Partial,
                [...$context, ...$tableMetrics, ...$unsupported],
                'tablespace_name_mapping_ambiguous',
            );
        }

        try {
            $tablespaceRows = $connection->select(
                $this->tablespaceMetadataQuery($timeoutMs),
                [$schema, is_string($tableMetadata->TABLE_NAME ?? null) ? $tableMetadata->TABLE_NAME : $effectiveTable],
            );
        } catch (Throwable $exception) {
            $availability = $this->classifyException($exception) === CacheStorageInspectionStatus::PermissionDenied
                ? CacheStorageMetricAvailability::PermissionDenied
                : ($this->classifyException($exception) === CacheStorageInspectionStatus::UnsupportedMetadataCapability
                    ? CacheStorageMetricAvailability::Unsupported
                    : CacheStorageMetricAvailability::Unavailable);
            $unavailable = $this->unavailableTablespaceMetrics($availability);

            return $this->result(
                $collectedAt,
                CacheStorageInspectionStatus::Partial,
                [...$context, ...$tableMetrics, ...$unavailable],
                $this->errorCategory($exception),
            );
        }

        if ($tablespaceRows === []) {
            $unavailable = $this->unavailableTablespaceMetrics(CacheStorageMetricAvailability::Unsupported);

            return $this->result(
                $collectedAt,
                CacheStorageInspectionStatus::Partial,
                [...$context, ...$tableMetrics, ...$unavailable],
                'tablespace_metadata_unavailable',
            );
        }

        $tablespace = $tablespaceRows[0];
        $tablespaceType = $this->stringMetric($tablespace->SPACE_TYPE ?? null, self::TABLESPACE_PROVENANCE.'.SPACE_TYPE');
        $baseTablespace = [
            'tablespaceId' => $this->integerMetric($tablespace->SPACE ?? null, self::TABLESPACE_PROVENANCE.'.SPACE'),
            'tablespaceType' => $tablespaceType,
            'tablespaceName' => $this->stringMetric($tablespace->TABLESPACE_NAME ?? null, self::TABLESPACE_PROVENANCE.'.NAME'),
        ];

        if ($tablespaceType->value !== 'Single') {
            $shared = [
                'fileSizeBytes' => CacheStorageMetric::unavailable(
                    self::TABLESPACE_PROVENANCE.'.FILE_SIZE; shared/system tablespace is not attributable to one table',
                    CacheStorageMetricAvailability::Unsupported,
                ),
                'allocatedSizeBytes' => CacheStorageMetric::unavailable(
                    self::TABLESPACE_PROVENANCE.'.ALLOCATED_SIZE; shared/system tablespace is not attributable to one table',
                    CacheStorageMetricAvailability::Unsupported,
                ),
            ];

            return $this->result(
                $collectedAt,
                CacheStorageInspectionStatus::Partial,
                [...$context, ...$tableMetrics, ...$baseTablespace, ...$shared],
                'shared_tablespace_size_not_attributable',
            );
        }

        $singleTablespace = [
            ...$baseTablespace,
            'fileSizeBytes' => $this->integerMetric($tablespace->FILE_SIZE ?? null, self::TABLESPACE_PROVENANCE.'.FILE_SIZE bytes'),
            'allocatedSizeBytes' => $this->integerMetric($tablespace->ALLOCATED_SIZE ?? null, self::TABLESPACE_PROVENANCE.'.ALLOCATED_SIZE bytes'),
        ];

        $tableMetricsComplete = $engine->availability === CacheStorageMetricAvailability::Available
            && $rows->availability === CacheStorageMetricAvailability::Available
            && $dataLength->availability === CacheStorageMetricAvailability::Available
            && $indexLength->availability === CacheStorageMetricAvailability::Available
            && $allocation->availability === CacheStorageMetricAvailability::Available
            && $singleTablespace['tablespaceId']->availability === CacheStorageMetricAvailability::Available
            && $singleTablespace['tablespaceType']->availability === CacheStorageMetricAvailability::Available
            && $singleTablespace['fileSizeBytes']->availability === CacheStorageMetricAvailability::Available
            && $singleTablespace['allocatedSizeBytes']->availability === CacheStorageMetricAvailability::Available;

        return $this->result(
            $collectedAt,
            $tableMetricsComplete ? CacheStorageInspectionStatus::Success : CacheStorageInspectionStatus::Partial,
            [...$context, ...$tableMetrics, ...$singleTablespace],
            $tableMetricsComplete ? null : 'table_metadata_incomplete',
        );
    }

    /** @return array<string, CacheStorageMetric> */
    private function unavailableTablespaceMetrics(
        CacheStorageMetricAvailability $availability = CacheStorageMetricAvailability::Unavailable,
    ): array {
        $provenance = 'mysql.information_schema.innodb_tables_and_tablespaces';

        return [
            'tablespaceId' => CacheStorageMetric::unavailable($provenance.'.SPACE', $availability),
            'tablespaceType' => CacheStorageMetric::unavailable($provenance.'.SPACE_TYPE', $availability),
            'tablespaceName' => CacheStorageMetric::unavailable($provenance.'.NAME', $availability),
            'fileSizeBytes' => CacheStorageMetric::unavailable($provenance.'.FILE_SIZE bytes', $availability),
            'allocatedSizeBytes' => CacheStorageMetric::unavailable($provenance.'.ALLOCATED_SIZE bytes', $availability),
        ];
    }

    private function emptyResult(
        DateTimeImmutable $collectedAt,
        CacheStorageInspectionStatus $status,
        CacheStorageMetricAvailability $availability = CacheStorageMetricAvailability::Unavailable,
        ?string $storeName = null,
        ?string $databaseDriver = null,
        ?string $connectionName = null,
        ?string $schemaName = null,
        ?string $cacheTable = null,
        ?string $resolvedCacheTable = null,
        ?string $errorCategory = null,
    ): CacheStorageInspectionResult {
        $unavailable = fn (string $name): CacheStorageMetric => CacheStorageMetric::unavailable($name, $availability);

        return new CacheStorageInspectionResult(
            collectedAt: $collectedAt,
            cacheStore: $storeName,
            databaseDriver: $databaseDriver,
            connectionName: $connectionName,
            schemaName: $schemaName,
            cacheTable: $cacheTable,
            resolvedCacheTable: $resolvedCacheTable,
            engine: $unavailable('mysql.information_schema.tables.ENGINE'),
            estimatedRowCount: $unavailable('mysql.information_schema.tables.TABLE_ROWS estimate'),
            dataLengthBytes: $unavailable('mysql.information_schema.tables.DATA_LENGTH bytes'),
            indexLengthBytes: $unavailable('mysql.information_schema.tables.INDEX_LENGTH bytes'),
            estimatedAllocationBytes: $unavailable('DATA_LENGTH + INDEX_LENGTH estimate'),
            dataFreeBytes: $unavailable('mysql.information_schema.tables.DATA_FREE bytes'),
            tablespaceId: $unavailable('mysql.information_schema.innodb_tables.SPACE'),
            tablespaceType: $unavailable('mysql.information_schema.innodb_tables.SPACE_TYPE'),
            tablespaceName: $unavailable('mysql.information_schema.innodb_tablespaces.NAME'),
            fileSizeBytes: $unavailable('mysql.information_schema.innodb_tablespaces.FILE_SIZE bytes'),
            allocatedSizeBytes: $unavailable('mysql.information_schema.innodb_tablespaces.ALLOCATED_SIZE bytes'),
            clusterStorageBytes: CacheStorageMetric::unavailable(
                'not collected: database cluster storage usage is outside this inspector scope',
                CacheStorageMetricAvailability::Unsupported,
            ),
            status: $status,
            errorCategory: $errorCategory,
        );
    }

    /** @param array<string, mixed> $values */
    private function result(
        DateTimeImmutable $collectedAt,
        CacheStorageInspectionStatus $status,
        array $values,
        ?string $errorCategory = null,
    ): CacheStorageInspectionResult {
        return new CacheStorageInspectionResult(
            collectedAt: $collectedAt,
            cacheStore: $values['storeName'],
            databaseDriver: $values['databaseDriver'],
            connectionName: $values['connectionName'],
            schemaName: $values['schemaName'],
            cacheTable: $values['cacheTable'],
            resolvedCacheTable: $values['resolvedCacheTable'],
            engine: $values['engine'],
            estimatedRowCount: $values['estimatedRowCount'],
            dataLengthBytes: $values['dataLengthBytes'],
            indexLengthBytes: $values['indexLengthBytes'],
            estimatedAllocationBytes: $values['estimatedAllocationBytes'],
            dataFreeBytes: $values['dataFreeBytes'],
            tablespaceId: $values['tablespaceId'],
            tablespaceType: $values['tablespaceType'],
            tablespaceName: $values['tablespaceName'],
            fileSizeBytes: $values['fileSizeBytes'],
            allocatedSizeBytes: $values['allocatedSizeBytes'],
            clusterStorageBytes: CacheStorageMetric::unavailable(
                'not collected: database cluster storage usage is outside this inspector scope',
                CacheStorageMetricAvailability::Unsupported,
            ),
            status: $status,
            errorCategory: $errorCategory,
        );
    }

    private function integerMetric(mixed $value, string $provenance): CacheStorageMetric
    {
        if (is_int($value) && $value >= 0) {
            return CacheStorageMetric::available($value, $provenance);
        }

        if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            if ($integer !== false) {
                return CacheStorageMetric::available($integer, $provenance);
            }
        }

        return CacheStorageMetric::unavailable($provenance);
    }

    private function stringMetric(mixed $value, string $provenance): CacheStorageMetric
    {
        return is_string($value) && $value !== ''
            ? CacheStorageMetric::available($value, $provenance)
            : CacheStorageMetric::unavailable($provenance);
    }

    private function sumMetrics(CacheStorageMetric $left, CacheStorageMetric $right): CacheStorageMetric
    {
        if ($left->availability !== CacheStorageMetricAvailability::Available
            || $right->availability !== CacheStorageMetricAvailability::Available
            || ! is_int($left->value)
            || ! is_int($right->value)
            || $left->value > PHP_INT_MAX - $right->value) {
            return CacheStorageMetric::unavailable('DATA_LENGTH + INDEX_LENGTH estimate');
        }

        return CacheStorageMetric::available($left->value + $right->value, 'DATA_LENGTH + INDEX_LENGTH estimate');
    }

    private function queryTimeoutMs(): ?int
    {
        $timeout = $this->config->get('pulse-cache-health.metadata.query_timeout_ms', 1000);

        return is_int($timeout) && $timeout >= 1 && $timeout <= 60000 ? $timeout : null;
    }

    private function tableMetadataQuery(int $timeoutMs): string
    {
        return "SELECT /*+ MAX_EXECUTION_TIME({$timeoutMs}) */ TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, DATA_FREE\n".
            'FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1';
    }

    private function tablespaceMetadataQuery(int $timeoutMs): string
    {
        return "SELECT /*+ MAX_EXECUTION_TIME({$timeoutMs}) */ it.TABLE_ID, it.SPACE, it.SPACE_TYPE, ts.NAME AS TABLESPACE_NAME, ts.FILE_SIZE, ts.ALLOCATED_SIZE\n".
            "FROM INFORMATION_SCHEMA.INNODB_TABLES AS it LEFT JOIN INFORMATION_SCHEMA.INNODB_TABLESPACES AS ts ON ts.SPACE = it.SPACE\n".
            "WHERE it.NAME = CONCAT(?, '/', ?) LIMIT 1";
    }

    private function classifyException(Throwable $exception): CacheStorageInspectionStatus
    {
        $code = $this->driverErrorCode($exception);

        if (in_array($code, [1044, 1045, 1142, 1143, 1227], true)) {
            return CacheStorageInspectionStatus::PermissionDenied;
        }

        if ($exception instanceof InvalidArgumentException) {
            return CacheStorageInspectionStatus::MissingConfiguration;
        }

        if (in_array($code, [2002, 2003, 2006, 2013, 2055], true)
            || str_starts_with((string) $exception->getCode(), '08')) {
            return CacheStorageInspectionStatus::DatabaseUnavailable;
        }

        if (in_array($code, [1109, 1146, 1054], true)) {
            return CacheStorageInspectionStatus::UnsupportedMetadataCapability;
        }

        return CacheStorageInspectionStatus::QueryFailure;
    }

    private function errorCategory(Throwable $exception): string
    {
        return match ($this->classifyException($exception)) {
            CacheStorageInspectionStatus::MissingConfiguration => 'connection_configuration_missing',
            CacheStorageInspectionStatus::PermissionDenied => 'permission_denied',
            CacheStorageInspectionStatus::DatabaseUnavailable => 'database_unavailable',
            CacheStorageInspectionStatus::UnsupportedMetadataCapability => 'metadata_capability_unavailable',
            default => 'query_failed',
        };
    }

    private function driverErrorCode(Throwable $exception): ?int
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (property_exists($current, 'errorInfo') && is_array($current->errorInfo)) {
                $code = $current->errorInfo[1] ?? null;

                if (is_int($code) || (is_string($code) && ctype_digit($code))) {
                    return (int) $code;
                }
            }
        }

        return null;
    }
}
