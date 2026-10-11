<?php

declare(strict_types=1);

namespace Digit7s\PulseCacheHealth\ValueObjects;

use Digit7s\PulseCacheHealth\Enums\CacheStorageMetricAvailability;
use InvalidArgumentException;

final readonly class CacheStorageMetric
{
    public function __construct(
        public int|string|null $value,
        public CacheStorageMetricAvailability $availability,
        public string $provenance,
    ) {
        if (($availability === CacheStorageMetricAvailability::Available) !== ($value !== null)) {
            throw new InvalidArgumentException('Available metrics require a value; unavailable metrics must be null.');
        }
    }

    public static function available(int|string $value, string $provenance): self
    {
        return new self($value, CacheStorageMetricAvailability::Available, $provenance);
    }

    public static function unavailable(
        string $provenance,
        CacheStorageMetricAvailability $availability = CacheStorageMetricAvailability::Unavailable,
    ): self {
        return new self(null, $availability, $provenance);
    }
}
