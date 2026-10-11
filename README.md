# Digit7s Laravel Pulse Cache Health

Database cache storage health monitoring foundations for Laravel Pulse.

> **Development status:** Phase 2 MySQL metadata inspector. It can be invoked explicitly after both opt-in switches are enabled. Snapshot history, scheduled collection, dashboard cards, lifecycle analysis, and notifications are not implemented.

## Development target

- PHP 8.4+
- Laravel Framework 13
- Laravel Pulse 1.8.x, package-tested with 1.8.1
- Livewire 4
- MySQL 8.4 is the initial target; a live isolated MySQL 8.4 run remains outstanding. The guarded live suite was run on MySQL 9.5.0 as supplementary compatibility evidence.

Other Laravel majors and Pulse minors are not currently claimed as supported.

## Installation

After the package is available from its Composer repository, install it in a Laravel 13 application with Laravel Pulse 1.8.x and Livewire 4:

```sh
composer require digit7s/laravel-pulse-cache-health
php artisan vendor:publish --tag=pulse-cache-health-config
```

Composer package auto-discovery registers the service provider. The package is not published yet. Phase 2 does not register a card or start background work.

## Configuration

The published `config/pulse-cache-health.php` keeps both `enabled` and `collection.enabled` false. The inspector performs no work until its `inspect()` method is explicitly invoked and both switches are true. No collector schedule is registered.

For a deliberate local or background invocation, resolve `Digit7s\PulseCacheHealth\Inspectors\MySqlCacheStorageInspector` from Laravel's container and call `inspect()`. A null `cache_store` selects Laravel's configured default cache store; an explicit value selects a named store. Only database cache stores on MySQL connections are inspected. The result reports both the configured table name and the resolved physical table name after Laravel's connection prefix is applied.

### Metrics and limits

- `DATA_LENGTH + INDEX_LENGTH` is a reported table allocation estimate in bytes, not exact disk usage or cache payload size.
- InnoDB `TABLE_ROWS`, `DATA_LENGTH`, `INDEX_LENGTH`, and related `INFORMATION_SCHEMA.TABLES` values are estimates and can reflect stale statistics.
- `FILE_SIZE` and `ALLOCATED_SIZE` are separate MySQL-reported tablespace metrics. They do not report cluster storage utilization. Sizes for shared/system tablespaces are not attributed to one cache table.
- Database-cluster storage utilization is outside this inspector's scope and is represented as unsupported/unavailable, never as zero.
- Each metadata query includes MySQL's server-side `MAX_EXECUTION_TIME` hint. It limits supported SELECT execution but does not bound connection establishment or every client/network wait.
- Tablespace metadata can require MySQL `PROCESS` privilege. If access is denied, table metadata remains available where permitted and tablespace metrics are marked permission-denied.
- Unsupported stores/drivers, incomplete metadata, and query failures have explicit statuses; unavailable values remain `null`, not zero. An empty `INFORMATION_SCHEMA.TABLES` result is reported as “missing or not visible,” because MySQL metadata visibility can hide tables from accounts without privileges.

The inspector never reads cache keys or values and never modifies cache data. It issues at most two exact-filtered metadata SELECTs per explicit inspection. It does not clear, prune, delete, truncate, analyze, or optimize tables.

`snapshots.connection` defaults to `null`. Its eventual fallback behavior must be defined before snapshot persistence is implemented. A null value must not be taken to mean that monitoring writes are isolated from the monitored database.

## Safety

Package registration performs no database work. Explicit metadata inspection is read-only and cache-data-free. No snapshots, scheduled work, Pulse dashboard, pruning, notification delivery, production integration, FilamentPHP, or Laravel Cloud dependency is included.

## Roadmap

1. Package foundation
2. MySQL cache storage metadata (current)
3. Bounded historical snapshots and growth trends
4. Laravel Pulse dashboard card
5. Optional cache lifecycle and payload insights
6. Threshold alerts and notification adapters
7. Consumer integration and hardening
8. Release preparation

See [the architecture notes](docs/architecture.md) for boundaries and future design decisions.

The guarded, opt-in MySQL integration suite is documented in [MySQL integration testing](docs/testing-mysql.md). It skips unless an explicitly isolated test target is fully configured.
