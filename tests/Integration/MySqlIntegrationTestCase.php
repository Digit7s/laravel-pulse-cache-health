<?php

declare(strict_types=1);

namespace Digit7s\PulseCacheHealth\Tests\Integration;

use Digit7s\PulseCacheHealth\Tests\Support\MySqlIntegrationSettings;
use Digit7s\PulseCacheHealth\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

abstract class MySqlIntegrationTestCase extends TestCase
{
    protected MySqlIntegrationSettings $mysqlSettings;

    /** @var list<string> */
    protected array $fixtureTables = [];

    protected function setUp(): void
    {
        $settings = MySqlIntegrationSettings::fromEnvironment();

        if ($settings === null) {
            $this->markTestSkipped('Set the complete isolated MySQL test environment to run integration tests.');
        }

        $this->mysqlSettings = $settings;

        parent::setUp();

        $identity = DB::connection('mysql_integration')->selectOne(
            'SELECT VERSION() AS server_version, DATABASE() AS database_name, @@port AS server_port, @@datadir AS data_directory',
        );

        if (! str_starts_with((string) $identity->server_version, $settings->expectedVersionPrefix)
            || $identity->database_name !== $settings->database
            || (int) $identity->server_port !== $settings->port
            || rtrim((string) $identity->data_directory, '/').'/' !== $settings->expectedDataDirectory) {
            throw new RuntimeException('Refusing MySQL integration tests: connected server identity did not match the isolated target.');
        }

        $this->createFixtureTable('cache_fixture_'.$settings->tokenSuffix());
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $settings = MySqlIntegrationSettings::fromEnvironment();

        if ($settings === null) {
            return;
        }

        $app['config']->set('database.default', 'mysql_integration');
        $app['config']->set('database.connections.mysql_integration', [
            'driver' => 'mysql',
            'url' => null,
            'host' => $settings->host,
            'port' => $settings->port,
            'database' => $settings->database,
            'username' => $settings->username,
            'password' => $settings->password,
            'unix_socket' => null,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
            'prefix' => 'it_',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => [],
        ]);
        $app['config']->set('cache.default', 'mysql_integration_cache');
        $app['config']->set('cache.stores.mysql_integration_cache', [
            'driver' => 'database',
            'connection' => 'mysql_integration',
            'table' => 'cache_fixture_'.$settings->tokenSuffix(),
        ]);
        $app['config']->set('pulse-cache-health.enabled', true);
        $app['config']->set('pulse-cache-health.collection.enabled', true);
        $app['config']->set('pulse-cache-health.cache_store', null);
    }

    protected function tearDown(): void
    {
        if (isset($this->app) && $this->fixtureTables !== []) {
            foreach ($this->fixtureTables as $logicalTable) {
                Schema::connection('mysql_integration')->dropIfExists($logicalTable);
            }
        }

        parent::tearDown();
    }

    protected function createFixtureTable(string $logicalTable, bool $populate = true): void
    {
        $this->fixtureTables[] = $logicalTable;

        Schema::connection('mysql_integration')->dropIfExists($logicalTable);
        Schema::connection('mysql_integration')->create($logicalTable, function ($table): void {
            $table->string('cache_key', 191)->primary();
            $table->binary('value');
            $table->unsignedBigInteger('expiration')->index();
        });

        if ($populate) {
            DB::connection('mysql_integration')->table($logicalTable)->insert([
                'cache_key' => 'synthetic-test-key',
                'value' => 'synthetic-test-payload',
                'expiration' => 2_000_000_000,
            ]);
        }
    }

    protected function configureProcessPrivilegeConnection(): void
    {
        $settings = $this->mysqlSettings;
        $this->app['config']->set('database.connections.mysql_integration_process', [
            ...$this->app['config']->get('database.connections.mysql_integration'),
            'username' => $settings->processUsername,
            'password' => $settings->processPassword,
        ]);
        $this->app['config']->set('cache.stores.mysql_integration_process_cache', [
            'driver' => 'database',
            'connection' => 'mysql_integration_process',
            'table' => 'cache_fixture_'.$settings->tokenSuffix(),
        ]);
        $this->app['config']->set('pulse-cache-health.cache_store', 'mysql_integration_process_cache');

    }
}
