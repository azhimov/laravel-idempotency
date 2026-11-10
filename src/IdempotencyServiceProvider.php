<?php

declare(strict_types=1);

namespace Azhimov\Idempotency;

use Illuminate\Support\ServiceProvider;

final class IdempotencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(self::configPath(), 'idempotency');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::configPath() => config_path('idempotency.php'),
            ], 'idempotency-config');
        }
    }

    private static function configPath(): string
    {
        return __DIR__.'/../config/idempotency.php';
    }
}
