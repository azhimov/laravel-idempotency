<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Events;

use Illuminate\Http\Request;

/**
 * A request with the same key is still running (409).
 */
final readonly class IdempotencyConflict
{
    public function __construct(
        public Request $request,
        public string $key,
        public int $retryAfter,
    ) {}
}
