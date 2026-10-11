<?php

declare(strict_types=1);

namespace Digit7s\PulseCacheHealth\Tests\Support;

use RuntimeException;

final readonly class MySqlIntegrationSettings
{
    private function __construct(
        public string $token,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
        public string $processUsername,
        public string $processPassword,
        public string $expectedDataDirectory,
        public string $expectedVersionPrefix,
    ) {}

    public function tokenSuffix(): string
    {
        return substr($this->token, 0, 12);
    }

    public static function fromEnvironment(): ?self
    {
        if (getenv('PULSE_CACHE_HEALTH_MYSQL_TESTS') !== '1') {
            return null;
        }

        $token = getenv('PULSE_CACHE_HEALTH_MYSQL_TEST_TOKEN');
        $host = getenv('PULSE_CACHE_HEALTH_MYSQL_TEST_HOST');
        $port = getenv('PULSE_CACHE_HEALTH_MYSQL_TEST_PORT');
        $database = getenv('PULSE_CACHE_HEALTH_MYSQL_TEST_DATABASE');
        $username = getenv('PULSE_CACHE_HEALTH_MYSQL_TEST_USERNAME');
        $password = getenv('PULSE_CACHE_HEALTH_MYSQL_TEST_PASSWORD');
        $processUsername = getenv('PULSE_CACHE_HEALTH_MYSQL_PROCESS_USERNAME');
        $processPassword = getenv('PULSE_CACHE_HEALTH_MYSQL_PROCESS_PASSWORD');
        $dataDirectory = getenv('PULSE_CACHE_HEALTH_MYSQL_EXPECTED_DATADIR');
        $versionPrefix = getenv('PULSE_CACHE_HEALTH_MYSQL_EXPECTED_VERSION');

        if (! is_string($token) || preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            throw new RuntimeException('Refusing MySQL integration tests: invalid isolation token.');
        }

        $suffix = substr($token, 0, 12);

        $isLoopback = is_string($host) && in_array($host, ['127.0.0.1', '::1'], true);
        $isGitHubService = $host === 'mysql'
            && getenv('GITHUB_ACTIONS') === 'true'
            && getenv('PULSE_CACHE_HEALTH_MYSQL_CI_SERVICE') === '1';

        if (! $isLoopback && ! $isGitHubService) {
            throw new RuntimeException('Refusing MySQL integration tests: host must be loopback or the explicitly enabled GitHub MySQL service.');
        }

        $validPort = is_string($port)
            && ctype_digit($port)
            && (int) $port <= 65535
            && ((int) $port >= 1024 || ($isGitHubService && (int) $port === 3306));

        if (! $validPort) {
            throw new RuntimeException('Refusing MySQL integration tests: invalid local test port.');
        }

        if ($database !== "pulse_cache_health_test_{$suffix}") {
            throw new RuntimeException('Refusing MySQL integration tests: database name does not match the isolation token.');
        }

        if ($username !== "pch_it_{$suffix}" || ! is_string($password) || $password === '') {
            throw new RuntimeException('Refusing MySQL integration tests: restricted test account is missing or invalid.');
        }

        if ($processUsername !== "pch_proc_{$suffix}" || ! is_string($processPassword) || $processPassword === '') {
            throw new RuntimeException('Refusing MySQL integration tests: PROCESS test account is missing or invalid.');
        }

        if (! is_string($dataDirectory) || $dataDirectory === '') {
            throw new RuntimeException('Refusing MySQL integration tests: expected private data directory is required.');
        }

        if (! is_string($versionPrefix) || ! in_array($versionPrefix, ['8.4.', '9.5.'], true)) {
            throw new RuntimeException('Refusing MySQL integration tests: expected MySQL 8.4 or supplementary 9.5 version is required.');
        }

        return new self(
            token: $token,
            host: $host,
            port: (int) $port,
            database: $database,
            username: $username,
            password: $password,
            processUsername: $processUsername,
            processPassword: $processPassword,
            expectedDataDirectory: rtrim($dataDirectory, '/').'/',
            expectedVersionPrefix: $versionPrefix,
        );
    }
}
