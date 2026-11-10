<?php

declare(strict_types=1);

namespace Azhimov\Idempotency;

use Azhimov\Idempotency\Contracts\ErrorResponder;
use Azhimov\Idempotency\Contracts\IdempotencyStore;
use Azhimov\Idempotency\Contracts\ScopeResolver;
use Azhimov\Idempotency\Http\Middleware\EnsureIdempotency;
use Azhimov\Idempotency\Stores\CacheStore;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
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

        $this->app->bind(ErrorResponder::class, fn (Application $app): ErrorResponder => self::makeConfigured(
            $app, 'idempotency.error_responder', ErrorResponder::class,
        ));

        $this->app->bind(IdempotencyStore::class, fn (Application $app): IdempotencyStore => self::makeStore($app));
    }

    public function boot(): void
    {
        $this->app->make(Router::class)->aliasMiddleware('idempotent', EnsureIdempotency::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::configPath() => config_path('idempotency.php'),
            ], 'idempotency-config');
        }
    }

    private static function makeStore(Application $app): IdempotencyStore
    {
        $config = $app->make('config');
        $store = $config->get('idempotency.store');

        if ($store === 'cache') {
            $cacheStore = $config->get('idempotency.cache_store');

            return new CacheStore($app->make('cache')->store(is_string($cacheStore) ? $cacheStore : null));
        }

        return self::makeConfigured($app, 'idempotency.store', IdempotencyStore::class);
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
