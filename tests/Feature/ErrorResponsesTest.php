<?php

declare(strict_types=1);

use Azhimov\Idempotency\Contracts\ErrorResponder;
use Azhimov\Idempotency\Http\ErrorCode;
use Azhimov\Idempotency\Tests\Fixtures\Counter;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    Counter::reset();
    $this->freezeTime();

    Route::post('/payments', function (Request $request) {
        Counter::$calls++;

        return response()->json(['id' => 'pay_'.Counter::$calls], 201);
    })->middleware('idempotent');

    Route::post('/strict/payments', fn () => response()->json([], 201))->middleware('idempotent:required');

    // While this handler runs it holds the key, and fires a retry with the same
    // key, i.e. what a client does after a timeout on a slow request.
    Route::post('/slow/payments', function (Request $request) {
        Counter::$calls++;
        Counter::$inner = app(Kernel::class)->handle(Request::create(
            '/slow/payments',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => 'key-1',
            ],
            content: (string) json_encode(['amount' => $request->input('retry_amount', $request->input('amount'))]),
        ));

        return response()->json(['id' => 'pay_1'], 201);
    })->middleware('idempotent');
});

function errorBody(string $code): array
{
    return ['error' => ['code' => $code, 'message' => ErrorCode::from($code)->message()]];
}

it('answers 409 with Retry-After to a retry while the first request runs', function (): void {
    config(['idempotency.lock_timeout' => 30]);

    test()->postJson('/slow/payments', ['amount' => 1500], ['Idempotency-Key' => 'key-1'])->assertCreated();

    expect(Counter::$inner?->getStatusCode())->toBe(409)
        ->and(Counter::$inner?->headers->get('Retry-After'))->toBe('30')
        ->and(json_decode((string) Counter::$inner?->getContent(), true))->toBe(errorBody('idempotency_request_in_progress'))
        ->and(Counter::$calls)->toBe(1);
});

it('answers 422 to a different payload while the first request runs', function (): void {
    test()->postJson('/slow/payments', ['amount' => 1500, 'retry_amount' => 1], ['Idempotency-Key' => 'key-1']);

    expect(Counter::$inner?->getStatusCode())->toBe(422);
});

it('answers 422 when a key is reused with a different payload', function (): void {
    test()->postJson('/payments', ['amount' => 1500], ['Idempotency-Key' => 'key-1']);

    test()->postJson('/payments', ['amount' => 9999], ['Idempotency-Key' => 'key-1'])
        ->assertStatus(422)
        ->assertExactJson(errorBody('idempotency_key_reused'));

    expect(Counter::$calls)->toBe(1);
});

it('answers 400 when a required key is missing', function (): void {
    test()->postJson('/strict/payments', [])
        ->assertStatus(400)
        ->assertExactJson(errorBody('idempotency_key_missing'));
});

it('answers 400 to an invalid key and explains why', function (string $key, string $reason): void {
    test()->postJson('/payments', [], ['Idempotency-Key' => $key])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'idempotency_key_invalid')
        ->assertJsonPath('error.message', fn (string $message): bool => str_contains($message, $reason));

    expect(Counter::$calls)->toBe(0);
})->with([
    'empty' => ['', 'empty'],
    'space inside' => ['bad key', 'visible ASCII'],
    'too long' => [str_repeat('k', 256), '255'],
]);

it('does not store error responses', function (): void {
    test()->postJson('/strict/payments', [])->assertStatus(400);
    test()->postJson('/strict/payments', [], ['Idempotency-Key' => 'key-1'])->assertCreated();
});

it('uses a custom ErrorResponder from config', function (): void {
    $responder = new class implements ErrorResponder
    {
        public function respond(Request $request, ErrorCode $code, string $message): Response
        {
            return response()->json(['type' => $code->value, 'detail' => $message], $code->status(), [
                'Content-Type' => 'application/problem+json',
            ]);
        }
    };
    app()->instance($responder::class, $responder);
    config(['idempotency.error_responder' => $responder::class]);

    test()->postJson('/strict/payments', [])
        ->assertStatus(400)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'idempotency_key_missing');
});

it('maps every error code to its HTTP status', function (): void {
    expect(ErrorCode::KeyMissing->status())->toBe(400)
        ->and(ErrorCode::KeyInvalid->status())->toBe(400)
        ->and(ErrorCode::KeyReused->status())->toBe(422)
        ->and(ErrorCode::RequestInProgress->status())->toBe(409);
});
