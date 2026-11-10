<?php

declare(strict_types=1);

use Azhimov\Idempotency\Contracts\IdempotencyStore;
use Azhimov\Idempotency\Events\IdempotencyConflict;
use Azhimov\Idempotency\Events\IdempotencyKeyMismatch;
use Azhimov\Idempotency\Events\IdempotencyLockLost;
use Azhimov\Idempotency\Events\IdempotentRequestStarted;
use Azhimov\Idempotency\Events\IdempotentResponseReplayed;
use Azhimov\Idempotency\Events\IdempotentResponseStored;
use Azhimov\Idempotency\Stores\CacheStore;
use Azhimov\Idempotency\Stores\Results\BeginResult;
use Azhimov\Idempotency\Support\Fingerprint;
use Azhimov\Idempotency\Support\StoredResponse;
use Azhimov\Idempotency\Tests\Fixtures\Counter;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

const ALL_EVENTS = [
    IdempotentRequestStarted::class,
    IdempotentResponseStored::class,
    IdempotentResponseReplayed::class,
    IdempotencyKeyMismatch::class,
    IdempotencyConflict::class,
    IdempotencyLockLost::class,
];

beforeEach(function (): void {
    Counter::reset();
    $this->freezeTime();
    Event::fake(ALL_EVENTS);

    Route::post('/payments', function (Request $request) {
        Counter::$calls++;

        return $request->input('fail')
            ? response()->json([], 503)
            : response()->json(['id' => 'pay_1'], 201);
    })->middleware('idempotent');
});

function payWithKey(array $body = ['amount' => 1500]): TestResponse
{
    return test()->postJson('/payments', $body, ['Idempotency-Key' => 'key-1']);
}

it('reports a started and stored request', function (): void {
    payWithKey();

    Event::assertDispatched(IdempotentRequestStarted::class, fn ($e): bool => $e->key === 'key-1' && $e->request->is('payments'));
    Event::assertDispatched(IdempotentResponseStored::class, fn ($e): bool => $e->key === 'key-1' && $e->status === 201);
    Event::assertNotDispatched(IdempotentResponseReplayed::class);
});

it('reports a replay', function (): void {
    payWithKey();
    payWithKey();

    Event::assertDispatchedTimes(IdempotentRequestStarted::class, 1);
    Event::assertDispatched(IdempotentResponseReplayed::class, fn ($e): bool => $e->key === 'key-1' && $e->status === 201);
});

it('reports a key reused with a different payload', function (): void {
    payWithKey();
    payWithKey(['amount' => 1]);

    Event::assertDispatched(IdempotencyKeyMismatch::class, fn ($e): bool => $e->key === 'key-1');
});

it('reports a concurrent retry', function (): void {
    Route::post('/slow', function () {
        app(Kernel::class)->handle(Request::create('/slow', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_IDEMPOTENCY_KEY' => 'key-1',
        ], content: '{}'));

        return response()->json([], 201);
    })->middleware('idempotent');

    test()->postJson('/slow', [], ['Idempotency-Key' => 'key-1']);

    Event::assertDispatched(IdempotencyConflict::class, fn ($e): bool => $e->key === 'key-1' && $e->retryAfter === 30);
});

it('does not report a stored response for a 5xx', function (): void {
    payWithKey(['fail' => true]);

    Event::assertDispatched(IdempotentRequestStarted::class);
    Event::assertNotDispatched(IdempotentResponseStored::class);
});

it('reports a lost lock when the key was taken over mid-request', function (): void {
    app()->instance(IdempotencyStore::class, new class(new CacheStore(Cache::store('array'))) implements IdempotencyStore
    {
        public function __construct(private readonly IdempotencyStore $inner) {}

        public function begin(string $key, Fingerprint $fingerprint, int $lockTimeout): BeginResult
        {
            return $this->inner->begin($key, $fingerprint, $lockTimeout);
        }

        public function complete(string $key, string $token, StoredResponse $response, int $ttl): bool
        {
            return false;
        }

        public function release(string $key, string $token): void
        {
            $this->inner->release($key, $token);
        }
    });

    payWithKey()->assertCreated();

    Event::assertDispatched(IdempotencyLockLost::class, fn ($e): bool => $e->key === 'key-1' && $e->status === 201);
    Event::assertNotDispatched(IdempotentResponseStored::class);
});
