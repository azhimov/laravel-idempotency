<?php

declare(strict_types=1);

use Azhimov\Idempotency\Exceptions\InvalidIdempotencyKey;
use Azhimov\Idempotency\Support\IdempotencyKey;

it('accepts a typical client key', function (string $raw): void {
    expect(IdempotencyKey::fromString($raw)->value)->toBe($raw);
})->with([
    'uuid' => '6f1c2b0e-8a4d-4c1e-9f3a-2b7d5e8c1a90',
    'prefixed' => 'order_2026-11-11:attempt.1',
    'printable ascii' => 'A~z!#$%&*+/=?^`{|}',
]);

it('trims surrounding whitespace', function (): void {
    expect(IdempotencyKey::fromString("  abc-123 \t")->value)->toBe('abc-123');
});

it('unwraps an RFC 8941 quoted string, as in the IETF draft', function (): void {
    expect(IdempotencyKey::fromString('"8e03978e-40d5-43e8-bc93-6894a57f9324"')->value)
        ->toBe('8e03978e-40d5-43e8-bc93-6894a57f9324');
});

it('rejects an empty key', function (string $raw): void {
    IdempotencyKey::fromString($raw);
})->with(['', '   ', '""'])->throws(InvalidIdempotencyKey::class, 'empty');

it('rejects a key longer than the limit', function (): void {
    IdempotencyKey::fromString(str_repeat('a', 256));
})->throws(InvalidIdempotencyKey::class, '255');

it('honours a custom length limit', function (): void {
    expect(IdempotencyKey::fromString(str_repeat('a', 10), 10)->value)->toHaveLength(10);

    IdempotencyKey::fromString(str_repeat('a', 11), 10);
})->throws(InvalidIdempotencyKey::class, '10');

it('rejects characters outside visible ASCII', function (string $raw): void {
    IdempotencyKey::fromString($raw);
})->with([
    'inner space' => 'abc 123',
    'control char' => "abc\x01",
    'newline' => "abc\ndef",
    'non-ascii' => 'ключ-1',
])->throws(InvalidIdempotencyKey::class, 'characters');
