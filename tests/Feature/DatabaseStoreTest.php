<?php

declare(strict_types=1);

use Azhimov\Idempotency\Contracts\IdempotencyStore;
use Azhimov\Idempotency\IdempotencyServiceProvider;
use Azhimov\Idempotency\Stores\DatabaseStore;
use Azhimov\Idempotency\Stores\Results\InProgress;
use Azhimov\Idempotency\Stores\Results\Started;
use Azhimov\Idempotency\Support\Fingerprint;
use Azhimov\Idempotency\Support\StoredResponse;
use Azhimov\Idempotency\Tests\Fixtures\Counter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

function migrateIdempotencyTable(): void
{
    (require __DIR__.'/../../database/migrations/create_idempotency_keys_table.php.stub')->up();
}

beforeEach(function (): void {
    migrateIdempotencyTable();
});

storeContract(fn (): DatabaseStore => new DatabaseStore(DB::connection(), 'idempotency_keys'));

function dbStore(): DatabaseStore
{
    return new DatabaseStore(DB::connection(), 'idempotency_keys');
}

function fp(string $seed = 'a'): Fingerprint
{
    return Fingerprint::fromString(hash('sha256', $seed));
}

it('persists the claim as a row keyed by the record key', function (): void {
    dbStore()->begin('k1', fp(), 30);

    expect(DB::table('idempotency_keys')->where('key_hash', 'k1')->value('status'))->toBe('processing');
});

it('purges expired responses and abandoned claims, keeping live records', function (): void {
    $this->freezeTime();
    $store = dbStore();

    $done = $store->begin('completed-old', fp(), 30);
    $store->complete('completed-old', $done->token, new StoredResponse(201, '', []), 60);
    $live = $store->begin('completed-live', fp(), 30);
    $store->complete('completed-live', $live->token, new StoredResponse(201, '', []), 86400);
    $store->begin('abandoned', fp(), 30);

    $this->travel(2)->hours();
    $store->begin('running', fp(), 30);

    expect($store->purgeExpired())->toBe(2)
        ->and(DB::table('idempotency_keys')->orderBy('key_hash')->pluck('key_hash')->all())
        ->toBe(['completed-live', 'running']);
});

it('does not overwrite a response completed by a late owner during takeover', function (): void {
    $this->freezeTime();
    $store = dbStore();
    $first = $store->begin('k1', fp(), 30);

    $this->travel(31)->seconds();
    // The late owner finishes just before a retry tries to take the key over.
    $store->complete('k1', $first->token, new StoredResponse(201, 'first', []), 3600);

    expect($store->begin('k1', fp(), 30))->not->toBeInstanceOf(Started::class);
});

it('reads integer columns that a driver returns as strings', function (): void {
    $this->freezeTime();
    dbStore()->begin('k1', fp(), 30);
    DB::table('idempotency_keys')->update(['locked_until' => (string) (now()->getTimestamp() + 30)]);

    expect(dbStore()->begin('k1', fp(), 30))->toBeInstanceOf(InProgress::class);
});

it('can be selected in config and protects routes end to end', function (): void {
    Counter::reset();
    config(['idempotency.store' => 'database']);
    Route::post('/payments', fn () => response()->json(['id' => 'pay_'.++Counter::$calls], 201))->middleware('idempotent');

    expect(app(IdempotencyStore::class))->toBeInstanceOf(DatabaseStore::class);

    $this->postJson('/payments', ['amount' => 1500], ['Idempotency-Key' => 'k'])->assertCreated();
    $this->postJson('/payments', ['amount' => 1500], ['Idempotency-Key' => 'k'])
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJson(['id' => 'pay_1']);

    expect(DB::table('idempotency_keys')->count())->toBe(1);
});

it('uses the configured connection and table', function (): void {
    config([
        'database.connections.idempotency' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'idempotency.store' => 'database',
        'idempotency.database' => ['connection' => 'idempotency', 'table' => 'idem_records'],
    ]);
    migrateIdempotencyTable();

    app(IdempotencyStore::class)->begin('k1', fp(), 30);

    expect(DB::connection('idempotency')->table('idem_records')->count())->toBe(1);
});

it('publishes the migration under the idempotency-migrations tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(IdempotencyServiceProvider::class, 'idempotency-migrations');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toEndWith('_create_idempotency_keys_table.php');
});

it('purges via artisan', function (): void {
    config(['idempotency.store' => 'database']);
    $this->freezeTime();
    $store = dbStore();
    $started = $store->begin('k1', fp(), 30);
    $store->complete('k1', $started->token, new StoredResponse(201, '', []), 60);
    $this->travel(61)->seconds();

    $this->artisan('idempotency:purge')
        ->expectsOutputToContain('Purged 1 expired idempotency record')
        ->assertSuccessful();
});

it('explains that a cache store needs no purging', function (): void {
    config(['idempotency.store' => 'cache']);

    $this->artisan('idempotency:purge')
        ->expectsOutputToContain('nothing to purge')
        ->assertSuccessful();
});
