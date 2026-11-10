<?php

declare(strict_types=1);

use Azhimov\Idempotency\Tests\Fixtures\Counter;
use Azhimov\Idempotency\Tests\Fixtures\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Counter::$calls = 0;

    $handler = function (Request $request) {
        $id = 'pay_'.++Counter::$calls;

        return response()->json(['id' => $id, 'amount' => $request->input('amount')], 201, [
            'Location' => "/payments/{$id}",
            'Set-Cookie' => 'session=secret',
        ]);
    };

    Route::post('/payments', $handler)->middleware('idempotent');
    Route::patch('/payments/{id}', $handler)->middleware('idempotent');
    Route::post('/refunds', $handler)->middleware('idempotent');
    Route::get('/payments', $handler)->middleware('idempotent');
    Route::post('/strict/payments', $handler)->middleware('idempotent:required');
    Route::post('/short/payments', $handler)->middleware('idempotent:ttl=60');
});

function pay(array $body = ['amount' => 1500], ?string $key = 'key-1', string $uri = '/payments'): TestResponse
{
    return test()->postJson($uri, $body, $key === null ? [] : ['Idempotency-Key' => $key]);
}

it('executes the first request and returns its response', function (): void {
    pay()
        ->assertCreated()
        ->assertJson(['id' => 'pay_1', 'amount' => 1500])
        ->assertHeaderMissing('Idempotent-Replayed');

    expect(Counter::$calls)->toBe(1);
});

it('replays the stored response without calling the handler again', function (): void {
    pay();

    pay()
        ->assertCreated()
        ->assertExactJson(['id' => 'pay_1', 'amount' => 1500])
        ->assertHeader('Location', '/payments/pay_1')
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('Idempotent-Replayed', 'true');

    expect(Counter::$calls)->toBe(1);
});

it('does not replay headers outside the whitelist', function (): void {
    pay();

    expect(pay()->headers->has('Set-Cookie'))->toBeFalse();
});

it('treats different keys as different operations', function (): void {
    pay(key: 'key-1');
    pay(key: 'key-2')->assertJson(['id' => 'pay_2']);

    expect(Counter::$calls)->toBe(2);
});

it('scopes keys by route template', function (): void {
    pay(uri: '/payments');
    pay(uri: '/refunds')->assertHeaderMissing('Idempotent-Replayed');

    expect(Counter::$calls)->toBe(2);
});

it('scopes keys by user', function (): void {
    $this->actingAs(User::withId(1));
    pay();

    $this->actingAs(User::withId(2));
    pay()->assertJson(['id' => 'pay_2'])->assertHeaderMissing('Idempotent-Replayed');
});

it('protects PATCH by default', function (): void {
    $this->patchJson('/payments/pay_9', ['amount' => 1], ['Idempotency-Key' => 'k']);
    $this->patchJson('/payments/pay_9', ['amount' => 1], ['Idempotency-Key' => 'k'])
        ->assertHeader('Idempotent-Replayed', 'true');

    expect(Counter::$calls)->toBe(1);
});

it('ignores methods that are not configured', function (): void {
    $this->getJson('/payments', ['Idempotency-Key' => 'k']);
    $this->getJson('/payments', ['Idempotency-Key' => 'k'])->assertHeaderMissing('Idempotent-Replayed');

    config(['idempotency.methods' => ['POST']]);
    $this->patchJson('/payments/pay_9', [], ['Idempotency-Key' => 'k']);
    $this->patchJson('/payments/pay_9', [], ['Idempotency-Key' => 'k'])->assertHeaderMissing('Idempotent-Replayed');

    expect(Counter::$calls)->toBe(4);
});

it('runs without idempotency when the key is optional and absent', function (): void {
    pay(key: null)->assertCreated();
    pay(key: null)->assertCreated();

    expect(Counter::$calls)->toBe(2);
});

it('can require a key per route', function (): void {
    pay(key: null, uri: '/strict/payments')->assertStatus(400);

    expect(Counter::$calls)->toBe(0);
});

it('can require a key globally', function (): void {
    config(['idempotency.required' => true]);

    pay(key: null)->assertStatus(400);
});

it('honours a per-route ttl', function (): void {
    pay(uri: '/short/payments');

    $this->travel(59)->seconds();
    pay(uri: '/short/payments')->assertHeader('Idempotent-Replayed', 'true');

    $this->travel(2)->seconds();
    pay(uri: '/short/payments')->assertHeaderMissing('Idempotent-Replayed');

    expect(Counter::$calls)->toBe(2);
});

it('fails loudly on an unknown middleware option', function (): void {
    Route::post('/typo', fn () => 'ok')->middleware('idempotent:requird');

    $this->withoutExceptionHandling();
    $this->postJson('/typo', [], ['Idempotency-Key' => 'k']);
})->throws(InvalidArgumentException::class, 'requird');
