<?php

declare(strict_types=1);

namespace Digit7s\PulseCacheHealth\Enums;

enum CacheStorageInspectionStatus: string
{
    case Success = 'success';
    case Partial = 'partial';
    case Disabled = 'disabled';
    case UnsupportedCacheDriver = 'unsupported_cache_driver';
    case UnsupportedDatabaseDriver = 'unsupported_database_driver';
    case MissingConfiguration = 'missing_configuration';
    case InvalidTableIdentifier = 'invalid_table_identifier';
    case UnsupportedSchemaConfiguration = 'unsupported_schema_configuration';
    case TableMissingOrNotVisible = 'table_missing_or_not_visible';
    case PermissionDenied = 'permission_denied';
    case DatabaseUnavailable = 'database_unavailable';
    case QueryFailure = 'query_failure';
    case UnsupportedMetadataCapability = 'unsupported_metadata_capability';
}
