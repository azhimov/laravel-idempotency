<?php

declare(strict_types=1);

use Azhimov\Idempotency\Stores\Results\InProgress;
use Azhimov\Idempotency\Stores\Results\Started;

it('never asks the client to retry in less than a second', function (int $seconds, int $expected): void {
    expect((new InProgress($seconds))->retryAfter)->toBe($expected);
})->with([
    [0, 1],
    [-5, 1],
    [7, 7],
]);

it('requires an owner token for a started request', function (): void {
    new Started('');
})->throws(InvalidArgumentException::class);
