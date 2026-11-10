<?php

declare(strict_types=1);

namespace Azhimov\Idempotency;

use Azhimov\Idempotency\Contracts\ScopeResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

final class IdempotencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(self::configPath(), 'idempotency');

        $this->app->bind(ScopeResolver::class, fn (Application $app): ScopeResolver => self::makeConfigured(
            $app, 'idempotency.scope_resolver', ScopeResolver::class,
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::configPath() => config_path('idempotency.php'),
            ], 'idempotency-config');
        }
    }

    /**
     * Resolves a class name from config and checks it implements $contract.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return T
     */
    private static function makeConfigured(Application $app, string $configKey, string $contract): object
    {
        $class = $app->make('config')->get($configKey);

        if (! is_string($class) || ! is_a($class, $contract, true)) {
            throw new InvalidArgumentException(sprintf(
                'Config [%s] must be a class implementing %s.', $configKey, $contract,
            ));
        }

        $instance = $app->make($class);
        assert($instance instanceof $contract);

        return $instance;
    }

    private static function configPath(): string
    {
        return __DIR__.'/../config/idempotency.php';
    }
}
