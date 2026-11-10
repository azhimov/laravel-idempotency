<?php

declare(strict_types=1);

use Azhimov\Idempotency\Contracts\IdempotencyStore;
use Azhimov\Idempotency\Stores\CacheStore;
use Azhimov\Idempotency\Stores\Results\BeginResult;
use Azhimov\Idempotency\Support\Fingerprint;
use Azhimov\Idempotency\Support\StoredResponse;
use Azhimov\Idempotency\Tests\Fixtures\Counter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Counter::reset();
    $this->freezeTime();
});

function sendPayment(string $uri): TestResponse
{
    return test()->postJson($uri, ['amount' => 1500], ['Idempotency-Key' => 'key-1']);
}

it('does not store a 5xx response, so the retry executes again', function (): void {
    Route::post('/payments', function () {
        return ++Counter::$calls === 1
            ? response()->json(['error' => 'gateway timeout'], 504)
            : response()->json(['id' => 'pay_1'], 201);
    })->middleware('idempotent');

    sendPayment('/payments')->assertStatus(504);
    sendPayment('/payments')->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    sendPayment('/payments')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect(Counter::$calls)->toBe(2);
});

it('releases the key when the handler throws', function (): void {
    Route::post('/payments', function () {
        if (++Counter::$calls === 1) {
            throw new RuntimeException('database is down');
        }

        return response()->json(['id' => 'pay_1'], 201);
    })->middleware('idempotent');

    sendPayment('/payments')->assertStatus(500);
    sendPayment('/payments')->assertCreated();

    expect(Counter::$calls)->toBe(2);
});

it('releases the key when an exception escapes the pipeline', function (): void {
    Route::post('/payments', function () {
        if (++Counter::$calls === 1) {
            throw new RuntimeException('boom');
        }

        return response()->json(['id' => 'pay_1'], 201);
    })->middleware('idempotent');

    $this->withoutExceptionHandling();

    expect(fn () => sendPayment('/payments'))->toThrow(RuntimeException::class, 'boom');
    sendPayment('/payments')->assertCreated();
});

it('does not store a status outside the configured ranges', function (): void {
    Route::post('/payments', function () {
        Counter::$calls++;

        return redirect('/payments/pay_1');
    })->middleware('idempotent');

    sendPayment('/payments')->assertRedirect();
    sendPayment('/payments')->assertRedirect()->assertHeaderMissing('Idempotent-Replayed');

    expect(Counter::$calls)->toBe(2);
});

it('passes streamed responses through without storing them', function (): void {
    Route::post('/exports', function () {
        Counter::$calls++;

        return response()->stream(fn () => print ('a,b'), 200);
    })->middleware('idempotent');

    sendPayment('/exports')->assertOk();
    sendPayment('/exports')->assertOk()->assertHeaderMissing('Idempotent-Replayed');

    expect(Counter::$calls)->toBe(2);
});

it('still returns the real response when storing it fails', function (): void {
    Exceptions::fake();

    app()->instance(IdempotencyStore::class, new class(new CacheStore(Cache::store('array'))) implements IdempotencyStore
    {
        public function __construct(private readonly IdempotencyStore $inner) {}

        public function begin(string $key, Fingerprint $fingerprint, int $lockTimeout): BeginResult
        {
            return $this->inner->begin($key, $fingerprint, $lockTimeout);
        }

        public function complete(string $key, string $token, StoredResponse $response, int $ttl): bool
        {
            throw new RuntimeException('cache unavailable');
        }

        public function release(string $key, string $token): void
        {
            $this->inner->release($key, $token);
        }
    });

    Route::post('/payments', function () {
        Counter::$calls++;

        return response()->json(['id' => 'pay_1'], 201);
    })->middleware('idempotent');

    sendPayment('/payments')->assertCreated()->assertJson(['id' => 'pay_1']);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'cache unavailable');
});
