<?php

declare(strict_types=1);

return [
    /*
    | Package-wide opt-in switch reserved for future cache-health functionality.
    | Package registration does not run the explicit inspector or start background work.
    */
    'enabled' => false,

    /*
    | Collection remains disabled. The inspector only runs after a caller invokes
    | it explicitly and this opt-in is enabled; no schedule is registered.
    */
    'collection' => [
        'enabled' => false,
    ],

    // Null selects cache.default; explicit names must identify a database store.
    'cache_store' => null,

    // Options are consulted only after an explicit inspector call and opt-in.
    'metadata' => [
        'tablespaces' => true,
        // MySQL MAX_EXECUTION_TIME hint for each SELECT (1..60000 ms).
        'query_timeout_ms' => 1000,
    ],

    /*
    | Reserved for the future snapshot repository. A null connection has no
    | fallback behavior until that behavior is specified in Phase 3. It must not
    | be assumed to isolate monitoring writes from the monitored database.
    */
    'snapshots' => [
        'connection' => null,
        'retention_days' => 30,
    ],

];
