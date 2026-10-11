<?php

declare(strict_types=1);

namespace Digit7s\PulseCacheHealth\Enums;

enum CacheStorageMetricAvailability: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';
    case Disabled = 'disabled';
    case PermissionDenied = 'permission_denied';
    case Unsupported = 'unsupported';
}
