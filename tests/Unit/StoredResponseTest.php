<?php

declare(strict_types=1);

use Azhimov\Idempotency\Support\StoredResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

it('captures status, body and only the whitelisted headers', function (): void {
    $response = new JsonResponse(['id' => 'pay_1'], 201, [
        'Location' => '/payments/pay_1',
        'Set-Cookie' => 'session=secret',
        'X-Request-Id' => 'abc',
    ]);

    $stored = StoredResponse::fromResponse($response, ['Content-Type', 'Location']);

    expect($stored->status)->toBe(201)
        ->and($stored->body)->toBe('{"id":"pay_1"}')
        ->and($stored->headers)->toBe([
            'Content-Type' => ['application/json'],
            'Location' => ['/payments/pay_1'],
        ]);
});

it('matches whitelisted header names case-insensitively', function (): void {
    $stored = StoredResponse::fromResponse(new Response('', 201, ['location' => '/x']), ['LOCATION']);

    expect($stored->headers)->toBe(['LOCATION' => ['/x']]);
});

it('rebuilds an equivalent HTTP response', function (): void {
    $stored = new StoredResponse(201, '{"id":"pay_1"}', ['Content-Type' => ['application/json']]);

    $response = $stored->toResponse();

    expect($response->getStatusCode())->toBe(201)
        ->and($response->getContent())->toBe('{"id":"pay_1"}')
        ->and($response->headers->get('Content-Type'))->toBe('application/json');
});

it('round-trips through an array, including binary bodies', function (): void {
    $stored = new StoredResponse(200, "\x00\xFF binary", ['Content-Type' => ['application/octet-stream']]);

    $restored = StoredResponse::fromArray($stored->toArray());

    expect($restored)->toEqual($stored)
        ->and(json_encode($stored->toArray()))->toBeString();
});

it('refuses to rehydrate malformed data', function (array $data): void {
    StoredResponse::fromArray($data);
})->with([
    'missing status' => [['body' => '', 'headers' => []]],
    'status out of range' => [['status' => 99, 'body' => '', 'headers' => []]],
    'body not base64' => [['status' => 200, 'body' => '%%%', 'headers' => []]],
    'headers not a map of lists' => [['status' => 200, 'body' => '', 'headers' => ['A' => 'b']]],
])->throws(InvalidArgumentException::class);

it('knows which responses can be captured', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'idem');

    expect(StoredResponse::canCapture(new Response('ok')))->toBeTrue()
        ->and(StoredResponse::canCapture(new StreamedResponse(fn () => null)))->toBeFalse()
        ->and(StoredResponse::canCapture(new BinaryFileResponse((string) $file)))->toBeFalse();

    unlink((string) $file);
});
