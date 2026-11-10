<?php

declare(strict_types=1);

use Azhimov\Idempotency\IdempotencyServiceProvider;
use Illuminate\Support\ServiceProvider;

it('merges the package config', function (): void {
    expect(config('idempotency.header'))->toBe('Idempotency-Key')
        ->and(config('idempotency.methods'))->toBe(['POST', 'PATCH']);
});

it('publishes the config under the idempotency-config tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(IdempotencyServiceProvider::class, 'idempotency-config');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toBe(config_path('idempotency.php'));
});
