<?php

declare(strict_types=1);

use Digit7s\PulseCacheHealth\Inspectors\MySqlCacheStorageInspector;
use Digit7s\PulseCacheHealth\PulseCacheHealthServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Pulse\Livewire\Card;

it('declares its auto-discovered Laravel service provider', function (): void {
    $manifest = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['extra']['laravel']['providers'])
        ->toContain(PulseCacheHealthServiceProvider::class)
        ->and(class_exists(PulseCacheHealthServiceProvider::class))->toBeTrue();
});

it('loads package configuration with collection disabled by default', function (): void {
    expect(config('pulse-cache-health.enabled'))->toBeFalse()
        ->and(config('pulse-cache-health.collection.enabled'))->toBeFalse()
        ->and(config('pulse-cache-health.snapshots.connection'))->toBeNull()
        ->and(config('pulse-cache-health.snapshots.retention_days'))->toBe(30);
});

it('registers the configuration publishing target', function (): void {
    $paths = PulseCacheHealthServiceProvider::pathsToPublish(
        PulseCacheHealthServiceProvider::class,
        'pulse-cache-health-config',
    );
    $source = array_key_first($paths);

    expect(realpath((string) $source))->toBe(realpath(dirname(__DIR__, 2).'/config/pulse-cache-health.php'))
        ->and($paths[$source])->toBe(config_path('pulse-cache-health.php'));
});

it('preserves application configuration overrides when merging defaults', function (): void {
    config()->set('pulse-cache-health.enabled', true);

    (new PulseCacheHealthServiceProvider($this->app))->register();

    expect(config('pulse-cache-health.enabled'))->toBeTrue()
        ->and(config('pulse-cache-health.collection.enabled'))->toBeFalse();
});

it('does not register package schedules', function (): void {
    $packageEvents = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->description, 'pulse-cache-health'));

    expect($packageEvents)->toBeEmpty();
});

it('does not issue package database queries during provider boot', function (): void {
    expect(config('database.default'))->toBe('testing')
        ->and(config('database.connections.testing.driver'))->toBe('sqlite')
        ->and(config('database.connections.testing.database'))->toBe(':memory:');

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    (new PulseCacheHealthServiceProvider($this->app))->boot();

    expect($queries)->toBeEmpty();
});

it('does not register package routes', function (): void {
    $packageRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains($route->uri(), 'pulse-cache-health'));

    expect($packageRoutes)->toBeEmpty();
});

it('does not register package migrations', function (): void {
    $packagePaths = collect(app('migrator')->paths())
        ->filter(fn (string $path): bool => str_contains($path, 'pulse-cache-health'));

    expect($packagePaths)->toBeEmpty();
});

it('can load the installed Pulse custom card integration point', function (): void {
    expect(class_exists(Card::class))->toBeTrue();
});

it('can resolve the explicit inspector without connecting to a database', function (): void {
    expect(app(MySqlCacheStorageInspector::class))->toBeInstanceOf(MySqlCacheStorageInspector::class)
        ->and(config('database.connections.testing.database'))->toBe(':memory:');
});
