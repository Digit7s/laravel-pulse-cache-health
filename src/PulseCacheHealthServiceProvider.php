<?php

declare(strict_types=1);

namespace Digit7s\PulseCacheHealth;

use Illuminate\Support\ServiceProvider;

final class PulseCacheHealthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pulse-cache-health.php', 'pulse-cache-health');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/pulse-cache-health.php' => config_path('pulse-cache-health.php'),
        ], 'pulse-cache-health-config');
    }
}
