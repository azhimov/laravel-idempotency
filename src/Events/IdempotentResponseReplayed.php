<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Events;

use Illuminate\Http\Request;

/**
 * A stored response was replayed; the handler did not run.
 */
final readonly class IdempotentResponseReplayed
{
    public function __construct(
        public Request $request,
        public string $key,
        public int $status,
    ) {}
}
