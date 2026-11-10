<?php

declare(strict_types=1);

use Azhimov\Idempotency\Support\Fingerprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

function jsonRequest(string $body, string $uri = '/payments', string $method = 'POST'): Request
{
    return Request::create($uri, $method, server: ['CONTENT_TYPE' => 'application/json'], content: $body);
}

it('is a stable sha256 hex digest', function (): void {
    $a = Fingerprint::fromRequest(jsonRequest('{"amount":1500}'));
    $b = Fingerprint::fromRequest(jsonRequest('{"amount":1500}'));

    expect($a->value)->toMatch('/^[a-f0-9]{64}$/')
        ->and($a->equals($b))->toBeTrue();
});

it('ignores JSON object key order and whitespace', function (): void {
    $a = Fingerprint::fromRequest(jsonRequest('{"amount":1500,"currency":"KZT","meta":{"b":2,"a":1}}'));
    $b = Fingerprint::fromRequest(jsonRequest("{\n  \"meta\": {\"a\": 1, \"b\": 2},\n  \"currency\": \"KZT\",\n  \"amount\": 1500\n}"));

    expect($a->equals($b))->toBeTrue();
});

it('keeps JSON list order significant', function (): void {
    $a = Fingerprint::fromRequest(jsonRequest('{"items":[1,2]}'));
    $b = Fingerprint::fromRequest(jsonRequest('{"items":[2,1]}'));

    expect($a->equals($b))->toBeFalse();
});

it('distinguishes JSON value types', function (): void {
    $a = Fingerprint::fromRequest(jsonRequest('{"amount":1500}'));
    $b = Fingerprint::fromRequest(jsonRequest('{"amount":"1500"}'));

    expect($a->equals($b))->toBeFalse();
});

it('changes when the body changes', function (): void {
    $a = Fingerprint::fromRequest(jsonRequest('{"amount":1500}'));
    $b = Fingerprint::fromRequest(jsonRequest('{"amount":1501}'));

    expect($a->equals($b))->toBeFalse();
});

it('changes when the method or path changes', function (): void {
    $base = Fingerprint::fromRequest(jsonRequest('{}', '/payments/1', 'POST'));

    expect($base->equals(Fingerprint::fromRequest(jsonRequest('{}', '/payments/1', 'PATCH'))))->toBeFalse()
        ->and($base->equals(Fingerprint::fromRequest(jsonRequest('{}', '/payments/2', 'POST'))))->toBeFalse();
});

it('includes the query string but ignores parameter order', function (): void {
    $a = Fingerprint::fromRequest(jsonRequest('{}', '/payments?b=2&a=1'));
    $b = Fingerprint::fromRequest(jsonRequest('{}', '/payments?a=1&b=2'));
    $c = Fingerprint::fromRequest(jsonRequest('{}', '/payments?a=1&b=3'));

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse();
});

it('normalizes form fields', function (): void {
    $a = Fingerprint::fromRequest(Request::create('/payments', 'POST', ['amount' => '10', 'currency' => 'KZT']));
    $b = Fingerprint::fromRequest(Request::create('/payments', 'POST', ['currency' => 'KZT', 'amount' => '10']));
    $c = Fingerprint::fromRequest(Request::create('/payments', 'POST', ['currency' => 'KZT', 'amount' => '11']));

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse();
});

it('hashes the content of uploaded files', function (): void {
    $make = fn (string $content): Request => Request::create('/documents', 'POST', files: [
        'scan' => UploadedFile::fake()->createWithContent('invoice.pdf', $content),
    ]);

    expect(Fingerprint::fromRequest($make('%PDF-1'))->equals(Fingerprint::fromRequest($make('%PDF-1'))))->toBeTrue()
        ->and(Fingerprint::fromRequest($make('%PDF-1'))->equals(Fingerprint::fromRequest($make('%PDF-2'))))->toBeFalse();
});

it('falls back to the raw body for non-JSON content', function (): void {
    $make = fn (string $body): Request => Request::create('/webhooks', 'POST', server: ['CONTENT_TYPE' => 'text/plain'], content: $body);

    expect(Fingerprint::fromRequest($make('a=1'))->equals(Fingerprint::fromRequest($make('a=1'))))->toBeTrue()
        ->and(Fingerprint::fromRequest($make('a=1'))->equals(Fingerprint::fromRequest($make('a=2'))))->toBeFalse();
});

it('treats malformed JSON as raw bytes instead of failing', function (): void {
    expect(Fingerprint::fromRequest(jsonRequest('{"amount":'))->value)->toMatch('/^[a-f0-9]{64}$/');
});

it('round-trips through its string form', function (): void {
    $original = Fingerprint::fromRequest(jsonRequest('{"amount":1500}'));

    expect(Fingerprint::fromString($original->value)->equals($original))->toBeTrue();
});

it('rejects a malformed stored fingerprint', function (): void {
    Fingerprint::fromString('not-a-hash');
})->throws(InvalidArgumentException::class);
