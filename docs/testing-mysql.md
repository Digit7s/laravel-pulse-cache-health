# Isolated MySQL Integration Tests

Run `composer test:mysql` only against a disposable, isolated MySQL instance. MySQL 8.4 is the target. MySQL 9.5 is supplementary evidence and does not replace 8.4 verification. CI uses `composer test:mysql:required`, which fails when any integration case is skipped so an unavailable target or unsupported fixture cannot pass silently.

The suite skips by default. To enable it locally, provide all of these environment variables:

Existing local integration-test setups must add `PULSE_CACHE_HEALTH_MYSQL_EXPECTED_SERVER_PORT` when `PULSE_CACHE_HEALTH_MYSQL_TESTS=1`. Set it to the disposable server's `@@port`; keep `PULSE_CACHE_HEALTH_MYSQL_TEST_PORT` set to the port the test process uses to connect. Missing expected-server-port configuration fails closed.

The Phase 2.2 GitHub Actions gate runs MySQL 8.4 on PHP 8.4 and 8.5 and records the exact MySQL patch, PHP patch, Laravel, and Pulse versions in each job summary. Until that workflow executes successfully, MySQL 8.4 compatibility remains unverified. The local package checks for the current Phase 2.2 preparation used PHP 8.5.5, Laravel 13.35.0, and Pulse 1.8.1; those unit/feature results do not constitute MySQL 8.4 integration evidence.

- `PULSE_CACHE_HEALTH_MYSQL_TESTS=1`
- `PULSE_CACHE_HEALTH_MYSQL_TEST_TOKEN`: 32 lowercase hexadecimal characters generated for this disposable database.
- `PULSE_CACHE_HEALTH_MYSQL_TEST_HOST`: `127.0.0.1` or `::1` locally. The exact hostname `mysql` is accepted only for container jobs in GitHub Actions when `GITHUB_ACTIONS=true` and `PULSE_CACHE_HEALTH_MYSQL_CI_SERVICE=1` are both present. This workflow runs on the host runner, so it connects through a dynamically mapped loopback port.
- `PULSE_CACHE_HEALTH_MYSQL_TEST_PORT`: the client connection port. Locally, use the port exposed to the test process; the GitHub host runner uses the dynamically mapped host port.
- `PULSE_CACHE_HEALTH_MYSQL_EXPECTED_SERVER_PORT`: required expected value of MySQL `@@port`. Configure it explicitly for each disposable local server. The GitHub Actions MySQL service independently verifies and requires internal server port `3306`, even when the client connects through a different mapped host port.
- `PULSE_CACHE_HEALTH_MYSQL_TEST_DATABASE`: `pulse_cache_health_test_` plus the first 12 token characters.
- `PULSE_CACHE_HEALTH_MYSQL_TEST_USERNAME`: `pch_it_` plus the first 12 token characters.
- `PULSE_CACHE_HEALTH_MYSQL_TEST_PASSWORD`: password for the restricted test account.
- `PULSE_CACHE_HEALTH_MYSQL_PROCESS_USERNAME`: `pch_proc_` plus the first 12 token characters.
- `PULSE_CACHE_HEALTH_MYSQL_PROCESS_PASSWORD`: password for a second account granted `PROCESS` and access only to the disposable schema for test fixture operations.
- `PULSE_CACHE_HEALTH_MYSQL_EXPECTED_DATADIR`: exact private MySQL data directory expected from `@@datadir`.
- `PULSE_CACHE_HEALTH_MYSQL_EXPECTED_VERSION`: `8.4.` or `9.5.`.
- `PULSE_CACHE_HEALTH_MYSQL_CI_SERVICE`: `1` only for the dedicated GitHub Actions `mysql` service alias.

The test case refuses all hosts other than loopback or the doubly gated GitHub service alias, client ports outside the allowed range, invalid expected server ports, unexpected schema/account names, missing marker/token/credentials, and connected servers whose version, server-side port, selected database, or data directory does not match. Unit coverage verifies that a dynamically mapped client port may differ from `@@port`, that the `mysql` alias remains rejected without both CI gates and is accepted with them, and that unexpected server identities are rejected. It verifies server identity before creating fixture tables. It never creates or drops databases or users. Fixtures are synthetic, uniquely suffixed, use the `it_` connection prefix, and are removed by the test case from that test database only. The workflow creates its database and random, per-run credentials inside the ephemeral service container; no repository secrets or production values are used.

The restricted account needs schema-scoped `SELECT`, `CREATE`, `INSERT`, `ALTER`, `INDEX`, and `DROP` privileges for disposable fixture setup and cleanup; it must not have `PROCESS`. The second account must have the same schema-scoped test privileges plus `PROCESS` for the positive tablespace metadata case. `PROCESS` is global in MySQL, so use only a dedicated test server with no unrelated workloads. Neither account should be reused by an application.

Tests capture the inspector's generated metadata statements, check the numeric `SPACE` join, confirm the statement timeout hint and query count, exercise permission degradation and non-InnoDB/shared tablespaces, and run `EXPLAIN` for the generated SELECTs. No `ANALYZE TABLE` or cache cleanup command is used. The CI gate runs on the PHP 8.4 minimum and PHP 8.5, the currently released minor versions admitted by the package's `^8.4` constraint, and records the exact MySQL patch release in each job summary. Integration results must be reported with the actual server version; skipped or unexecuted targets are not compatibility evidence.
