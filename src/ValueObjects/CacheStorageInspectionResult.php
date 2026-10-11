<?php

declare(strict_types=1);

namespace Digit7s\PulseCacheHealth\ValueObjects;

use DateTimeImmutable;
use Digit7s\PulseCacheHealth\Enums\CacheStorageInspectionStatus;

final readonly class CacheStorageInspectionResult
{
    public function __construct(
        public DateTimeImmutable $collectedAt,
        public ?string $cacheStore,
        public ?string $databaseDriver,
        public ?string $connectionName,
        public ?string $schemaName,
        public ?string $cacheTable,
        public ?string $resolvedCacheTable,
        public CacheStorageMetric $engine,
        public CacheStorageMetric $estimatedRowCount,
        public CacheStorageMetric $dataLengthBytes,
        public CacheStorageMetric $indexLengthBytes,
        public CacheStorageMetric $estimatedAllocationBytes,
        public CacheStorageMetric $dataFreeBytes,
        public CacheStorageMetric $tablespaceId,
        public CacheStorageMetric $tablespaceType,
        public CacheStorageMetric $tablespaceName,
        public CacheStorageMetric $fileSizeBytes,
        public CacheStorageMetric $allocatedSizeBytes,
        public CacheStorageMetric $clusterStorageBytes,
        public CacheStorageInspectionStatus $status,
        public ?string $errorCategory = null,
    ) {}
}
