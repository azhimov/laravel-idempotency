<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * Loads the package config file with the given environment variables set.
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function idempotencyConfigWithEnv(array $env): array
{
    foreach ($env as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    try {
        return require __DIR__.'/../../config/idempotency.php';
    } finally {
        foreach (array_keys($env) as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }
}

it('uses safe defaults without env variables', function (): void {
    $config = idempotencyConfigWithEnv([]);

    expect($config['store'])->toBe('cache')
        ->and($config['ttl'])->toBe(86400)
        ->and($config['required'])->toBeFalse();
});

it('reads store, ttl and required from env with proper types', function (): void {
    $config = idempotencyConfigWithEnv([
        'IDEMPOTENCY_STORE' => 'database',
        'IDEMPOTENCY_TTL' => '3600',
        'IDEMPOTENCY_REQUIRED' => 'true',
    ]);

    expect($config['store'])->toBe('database')
        ->and($config['ttl'])->toBe(3600)
        ->and($config['required'])->toBeTrue();
});

it('accepts common boolean spellings for IDEMPOTENCY_REQUIRED', function (string $value, bool $expected): void {
    expect(idempotencyConfigWithEnv(['IDEMPOTENCY_REQUIRED' => $value])['required'])->toBe($expected);
})->with([
    ['1', true],
    ['yes', true],
    ['on', true],
    ['false', false],
    ['0', false],
    ['off', false],
]);

it('refuses to run with a malformed env value instead of guessing', function (array $env, string $key): void {
    config(['idempotency.'.$key => idempotencyConfigWithEnv($env)[$key]]);
    Route::post('/payments', fn () => response()->json([], 201))->middleware('idempotent');

    $this->withoutExceptionHandling();
    $this->postJson('/payments', [], ['Idempotency-Key' => 'k']);
})->with([
    'ttl not a number' => [['IDEMPOTENCY_TTL' => 'one-day'], 'ttl'],
    'required not a boolean' => [['IDEMPOTENCY_REQUIRED' => 'maybe'], 'required'],
])->throws(InvalidArgumentException::class, 'Config [idempotency.');
