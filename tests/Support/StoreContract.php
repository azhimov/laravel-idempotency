<?php

declare(strict_types=1);

use Azhimov\Idempotency\Contracts\IdempotencyStore;
use Azhimov\Idempotency\Stores\Results\Completed;
use Azhimov\Idempotency\Stores\Results\InProgress;
use Azhimov\Idempotency\Stores\Results\Mismatch;
use Azhimov\Idempotency\Stores\Results\Started;
use Azhimov\Idempotency\Support\Fingerprint;
use Azhimov\Idempotency\Support\StoredResponse;

/**
 * Behaviour every IdempotencyStore must have. Call from a store's test file:
 *
 *     storeContract(fn (): IdempotencyStore => new MyStore(...));
 *
 * @param  Closure(): IdempotencyStore  $makeStore  called inside each test (the app is booted)
 */
function storeContract(Closure $makeStore): void
{
    $fp = fn (string $seed = 'a'): Fingerprint => Fingerprint::fromString(hash('sha256', $seed));
    $response = fn (): StoredResponse => new StoredResponse(201, '{"id":"pay_1"}', ['Content-Type' => ['application/json']]);

    $start = function (IdempotencyStore $store, string $key = 'k1', ?Fingerprint $fingerprint = null, int $lockTimeout = 30) use ($fp): string {
        $result = $store->begin($key, $fingerprint ?? $fp(), $lockTimeout);
        expect($result)->toBeInstanceOf(Started::class);
        assert($result instanceof Started);

        return $result->token;
    };

    describe('store contract', function () use ($makeStore, $fp, $response, $start): void {
        it('lets the first request start', function () use ($makeStore, $start): void {
            $start($makeStore());
        });

        it('reports a running request as in progress with a retry hint', function () use ($makeStore, $fp, $start): void {
            $store = $makeStore();
            $start($store, lockTimeout: 30);

            $this->travel(10)->seconds();
            $result = $store->begin('k1', $fp(), 30);

            expect($result)->toBeInstanceOf(InProgress::class)
                ->and($result->retryAfter)->toBe(20);
        });

        it('rejects a different payload while the first request runs', function () use ($makeStore, $fp, $start): void {
            $store = $makeStore();
            $start($store);

            expect($store->begin('k1', $fp('other'), 30))->toBeInstanceOf(Mismatch::class);
        });

        it('replays a completed response', function () use ($makeStore, $fp, $response, $start): void {
            $store = $makeStore();
            $token = $start($store);

            expect($store->complete('k1', $token, $response(), 3600))->toBeTrue();

            $result = $store->begin('k1', $fp(), 30);
            expect($result)->toBeInstanceOf(Completed::class)
                ->and($result->response)->toEqual($response());
        });

        it('rejects a different payload for a completed key', function () use ($makeStore, $fp, $response, $start): void {
            $store = $makeStore();
            $store->complete('k1', $start($store), $response(), 3600);

            expect($store->begin('k1', $fp('other'), 30))->toBeInstanceOf(Mismatch::class);
        });

        it('keeps keys independent', function () use ($makeStore, $start): void {
            $store = $makeStore();
            $start($store, 'k1');
            $start($store, 'k2');
        });

        it('does not complete with a foreign token', function () use ($makeStore, $fp, $response, $start): void {
            $store = $makeStore();
            $start($store);

            expect($store->complete('k1', 'not-the-owner', $response(), 3600))->toBeFalse()
                ->and($store->begin('k1', $fp(), 30))->toBeInstanceOf(InProgress::class);
        });

        it('frees the key on release so the client can retry', function () use ($makeStore, $start): void {
            $store = $makeStore();
            $store->release('k1', $start($store));

            $start($store);
        });

        it('ignores a release with a foreign token', function () use ($makeStore, $fp, $start): void {
            $store = $makeStore();
            $start($store);
            $store->release('k1', 'not-the-owner');

            expect($store->begin('k1', $fp(), 30))->toBeInstanceOf(InProgress::class);
        });

        it('does not release a completed record', function () use ($makeStore, $fp, $response, $start): void {
            $store = $makeStore();
            $token = $start($store);
            $store->complete('k1', $token, $response(), 3600);
            $store->release('k1', $token);

            expect($store->begin('k1', $fp(), 30))->toBeInstanceOf(Completed::class);
        });

        it('hands an expired lock over and fences out the previous owner', function () use ($makeStore, $response, $start): void {
            $store = $makeStore();
            $first = $start($store, lockTimeout: 30);

            $this->travel(31)->seconds();
            $second = $start($store, lockTimeout: 30);

            expect($second)->not->toBe($first)
                ->and($store->complete('k1', $first, $response(), 3600))->toBeFalse()
                ->and($store->complete('k1', $second, $response(), 3600))->toBeTrue();
        });

        it('lets a late owner complete when nobody took the key over', function () use ($makeStore, $response, $start): void {
            $store = $makeStore();
            $token = $start($store, lockTimeout: 30);

            $this->travel(31)->seconds();

            expect($store->complete('k1', $token, $response(), 3600))->toBeTrue();
        });

        it('forgets a completed response after its ttl', function () use ($makeStore, $response, $start): void {
            $store = $makeStore();
            $store->complete('k1', $start($store), $response(), 3600);

            $this->travel(3601)->seconds();

            $start($store);
        });

        it('treats an expired record as new even with a different payload', function () use ($makeStore, $fp, $response, $start): void {
            $store = $makeStore();
            $store->complete('k1', $start($store), $response(), 60);

            $this->travel(61)->seconds();

            $start($store, fingerprint: $fp('other'));
        });
    });
}
