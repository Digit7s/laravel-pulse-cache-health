<?php

declare(strict_types=1);

use Digit7s\PulseCacheHealth\Tests\Support\MySqlIntegrationSettings;

/**
 * @param  array<string, string|null>  $overrides
 */
function withMysqlIntegrationSettingsEnvironment(array $overrides, Closure $callback): mixed
{
    $token = '0123456789abcdef0123456789abcdef';
    $suffix = substr($token, 0, 12);
    $environment = [
        'GITHUB_ACTIONS' => 'false',
        'PULSE_CACHE_HEALTH_MYSQL_CI_SERVICE' => '0',
        'PULSE_CACHE_HEALTH_MYSQL_TESTS' => '1',
        'PULSE_CACHE_HEALTH_MYSQL_TEST_TOKEN' => $token,
        'PULSE_CACHE_HEALTH_MYSQL_TEST_HOST' => '127.0.0.1',
        'PULSE_CACHE_HEALTH_MYSQL_TEST_PORT' => '49153',
        'PULSE_CACHE_HEALTH_MYSQL_EXPECTED_SERVER_PORT' => '3306',
        'PULSE_CACHE_HEALTH_MYSQL_TEST_DATABASE' => "pulse_cache_health_test_{$suffix}",
        'PULSE_CACHE_HEALTH_MYSQL_TEST_USERNAME' => "pch_it_{$suffix}",
        'PULSE_CACHE_HEALTH_MYSQL_TEST_PASSWORD' => 'synthetic-test-password',
        'PULSE_CACHE_HEALTH_MYSQL_PROCESS_USERNAME' => "pch_proc_{$suffix}",
        'PULSE_CACHE_HEALTH_MYSQL_PROCESS_PASSWORD' => 'synthetic-process-password',
        'PULSE_CACHE_HEALTH_MYSQL_EXPECTED_DATADIR' => '/var/lib/mysql',
        'PULSE_CACHE_HEALTH_MYSQL_EXPECTED_VERSION' => '8.4.',
    ];
    $keys = array_unique([...array_keys($environment), ...array_keys($overrides)]);
    $previous = [];

    foreach ($keys as $key) {
        $previous[$key] = getenv($key);
    }

    foreach (array_replace($environment, $overrides) as $key => $value) {
        if ($value === null) {
            putenv($key);
        } else {
            putenv("{$key}={$value}");
        }
    }

    try {
        return $callback();
    } finally {
        foreach ($previous as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
    }
}

function mysqlIdentity(array $overrides = []): object
{
    return (object) array_replace([
        'server_version' => '8.4.8',
        'database_name' => 'pulse_cache_health_test_0123456789ab',
        'server_port' => 3306,
        'data_directory' => '/var/lib/mysql/',
    ], $overrides);
}

it('accepts a mapped client port that differs from the verified server-side port', function (): void {
    withMysqlIntegrationSettingsEnvironment([
        'GITHUB_ACTIONS' => 'true',
    ], function (): void {
        $settings = MySqlIntegrationSettings::fromEnvironment();

        expect($settings->port)->toBe(49153)
            ->and($settings->expectedServerPort)->toBe(3306);

        $settings->assertServerIdentity(mysqlIdentity());
    });
});

it('accepts the GitHub MySQL service hostname only with both CI isolation gates', function (): void {
    withMysqlIntegrationSettingsEnvironment([
        'GITHUB_ACTIONS' => 'true',
        'PULSE_CACHE_HEALTH_MYSQL_CI_SERVICE' => '1',
        'PULSE_CACHE_HEALTH_MYSQL_TEST_HOST' => 'mysql',
        'PULSE_CACHE_HEALTH_MYSQL_TEST_PORT' => '3306',
    ], function (): void {
        $settings = MySqlIntegrationSettings::fromEnvironment();

        expect($settings->host)->toBe('mysql');
        $settings->assertServerIdentity(mysqlIdentity());
    });
});

it('rejects a non-loopback host that is not the gated GitHub service alias', function (): void {
    expect(fn () => withMysqlIntegrationSettingsEnvironment([
        'PULSE_CACHE_HEALTH_MYSQL_TEST_HOST' => 'mysql.example.test',
    ], fn () => MySqlIntegrationSettings::fromEnvironment()))
        ->toThrow(RuntimeException::class, 'host must be loopback or the explicitly enabled GitHub MySQL service');
});

it('rejects server identity values that differ from the isolated target', function (string $field, mixed $value): void {
    withMysqlIntegrationSettingsEnvironment([], function () use ($field, $value): void {
        $settings = MySqlIntegrationSettings::fromEnvironment();
        $identity = mysqlIdentity([$field => $value]);

        expect(fn () => $settings->assertServerIdentity($identity))
            ->toThrow(RuntimeException::class, 'connected server identity did not match the isolated target');
    });
})->with([
    'unexpected internal server port' => ['server_port', 3307],
    'non-integer internal server port' => ['server_port', '3306.5'],
    'incorrect MySQL version' => ['server_version', '9.5.0'],
    'incorrect database name' => ['database_name', 'mysql'],
    'incorrect data directory' => ['data_directory', '/tmp/unexpected-mysql/'],
]);

it('rejects an incorrect database name in the configured temporary target', function (): void {
    expect(fn () => withMysqlIntegrationSettingsEnvironment([
        'PULSE_CACHE_HEALTH_MYSQL_TEST_DATABASE' => 'pulse_cache_health_test_other',
    ], fn () => MySqlIntegrationSettings::fromEnvironment()))
        ->toThrow(RuntimeException::class, 'database name does not match the isolation token');
});

it('fails closed when the isolation token is missing', function (): void {
    expect(fn () => withMysqlIntegrationSettingsEnvironment([
        'PULSE_CACHE_HEALTH_MYSQL_TEST_TOKEN' => null,
    ], fn () => MySqlIntegrationSettings::fromEnvironment()))
        ->toThrow(RuntimeException::class, 'invalid isolation token');
});

it('fails closed when the expected server port is missing for required integration tests', function (): void {
    expect(fn () => withMysqlIntegrationSettingsEnvironment([
        'PULSE_CACHE_HEALTH_MYSQL_EXPECTED_SERVER_PORT' => null,
    ], fn () => MySqlIntegrationSettings::fromEnvironment()))
        ->toThrow(RuntimeException::class, 'expected MySQL server port is missing or invalid');
});
