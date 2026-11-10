<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Events;

use Illuminate\Http\Request;

/**
 * The handler ran, but its claim had expired and was taken over, so the
 * response was not stored. The operation may have been executed twice:
 * alert on this and raise lock_timeout above your slowest handler.
 */
final readonly class IdempotencyLockLost
{
    public function __construct(
        public Request $request,
        public string $key,
        public int $status,
    ) {}
}
