<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Events;

use Illuminate\Http\Request;

/**
 * The key was reused with a different request (422).
 */
final readonly class IdempotencyKeyMismatch
{
    public function __construct(
        public Request $request,
        public string $key,
    ) {}
}
