# Architecture Notes

## Current implementation

The package is a Laravel library with an auto-discovered service provider and an explicitly invoked `MySqlCacheStorageInspector`. Both `pulse-cache-health.enabled` and `pulse-cache-health.collection.enabled` must be true before the inspector resolves a cache store or issues SQL. Registration and dashboard rendering do not invoke it.

## Responsibilities and boundaries

- **Pulse integration:** a future custom Livewire card will use Pulse's documented card extension point. The host application will choose where to include it in its Pulse dashboard.
- **Metadata inspection:** the current MySQL inspector resolves the configured Laravel database cache store and its live Laravel database connection, then uses two exact-filtered metadata-only SELECTs. It never reads cache rows. Unsupported drivers and missing privileges produce explicit unavailable states.
- **Historical storage:** dedicated snapshots are planned as the authoritative source for storage gauges. Retention will apply only to package-owned records.
- **Monitoring connection:** a future snapshot repository may use an explicitly configured connection. The meaning of `null` is intentionally unresolved; it must be specified before persistence is added. A shared default connection can increase pressure on the monitored database.
- **Safety:** no raw cache values or keys are required for storage metadata. Monitoring must remain read-only against the Laravel cache table, and there will be no clear, prune, truncate, or optimize action.

## Current metric definitions

| Metric | Meaning | Provenance and limits |
| --- | --- | --- |
| Estimated rows | MySQL's approximate `TABLE_ROWS` | `INFORMATION_SCHEMA.TABLES`; InnoDB statistics can be stale. |
| Data and index length | MySQL-reported table metadata in bytes | `DATA_LENGTH` and `INDEX_LENGTH`; estimates, not physical cluster usage. |
| Estimated allocation | Sum of data and index length when both are valid | Derived estimate; not logical cache payload bytes or exact disk use. |
| Data free | MySQL-reported free bytes | `DATA_FREE`; may describe tablespace free space rather than cache-owned reclaimable bytes. |
| Tablespace identity/type | `SPACE`, `SPACE_TYPE`, and `NAME` | Mapped from `INNODB_TABLES.SPACE` to `INNODB_TABLESPACES.SPACE`. |
| File / allocated size | `FILE_SIZE` / `ALLOCATED_SIZE` | MySQL-reported tablespace values; reported only for `Single` tablespaces, never attributed from shared/system files. |
| Cluster storage usage | Not collected | The result explicitly marks cluster-level storage as unsupported/unavailable; this inspector cannot report database-service or disk utilization. |

The base table metadata query filters `TABLE_SCHEMA` and `TABLE_NAME` using bound values. The tablespace query filters the canonical metadata table name and joins tablespace identity by numeric `SPACE`. Neither query reads monitored-table rows. Both have a bounded `LIMIT 1` and MySQL `MAX_EXECUTION_TIME` SELECT hint. The hint is a server execution limit where supported; it is not a universal end-to-end wall-clock guarantee. MySQL 9.5 `EXPLAIN` showed indexed dictionary lookups for the table metadata query. Its InnoDB metadata views were materialized and scanned as metadata views; this did not scan the synthetic cache table, but collection cost may grow with server dictionary size and should be observed in isolated MySQL 8.4 and staging-like tests before high-frequency use.

Tablespace dictionary visibility can require `PROCESS`. A privilege error leaves successful table metrics intact and marks tablespace metrics permission-denied. An empty `INFORMATION_SCHEMA.TABLES` result is reported as missing or not visible: MySQL can hide table metadata from an account without access, so the inspector does not claim a definitive missing-table diagnosis. Missing or incompatible optional tablespace metadata produces a partial result; error categories never include raw exception text, SQL, or connection strings.

Supported table resolution follows Laravel's configured `cache.default` or explicit `cache_store`, the named store's `connection` and `table`, the resolved `DatabaseStore` connection, and its table prefix. Results preserve both configured and prefix-resolved table names. Qualified table names are rejected instead of being interpreted as another schema. No fallback to an unrelated connection is attempted.

## Planned work

1. Store bounded snapshots and calculate growth from actual sample timestamps.
2. Collect samples asynchronously with explicit scheduling and failure recovery.
3. Render stored values through a Pulse card, with explicit stale and unavailable states.
4. Add opt-in lifecycle insights and alerts only after their safety and privacy boundaries are designed.

The locally tested dependency set is PHP 8.5.5, Laravel Framework 13.35.0, Pulse 1.8.1, Livewire 4.4.7, and Testbench 11.3.0. The isolated MySQL integration suite ran on MySQL 9.5.0; MySQL 8.4 is not installed in the local environment and remains unverified. Composer constraints target Laravel 13, Pulse 1.8.x, and Livewire 4. This phase does not validate other Laravel majors or Pulse minor versions.
