<?php

declare(strict_types=1);

use Azhimov\Idempotency\Stores\CacheStore;
use Azhimov\Idempotency\Stores\Results\Started;
use Azhimov\Idempotency\Support\Fingerprint;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;

storeContract(fn (): CacheStore => new CacheStore(Cache::store('array')));

it('requires a cache store with atomic locks', function (): void {
    new CacheStore(new Repository(Mockery::mock(Store::class)));
})->throws(InvalidArgumentException::class, 'atomic locks');

it('namespaces its entries with a prefix', function (): void {
    $store = new CacheStore(Cache::store('array'), 'idem-test:');

    $store->begin('k1', Fingerprint::fromString(hash('sha256', 'a')), 30);

    expect(Cache::store('array')->has('idem-test:k1'))->toBeTrue();
});

it('lets a second store instance on the same cache see the claim', function (): void {
    $fingerprint = Fingerprint::fromString(hash('sha256', 'a'));

    expect((new CacheStore(Cache::store('array')))->begin('k1', $fingerprint, 30))->toBeInstanceOf(Started::class)
        ->and((new CacheStore(Cache::store('array')))->begin('k1', $fingerprint, 30))->not->toBeInstanceOf(Started::class);
});
