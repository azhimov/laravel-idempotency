<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Events;

use Illuminate\Http\Request;

/**
 * The handler's response was stored for replay.
 */
final readonly class IdempotentResponseStored
{
    public function __construct(
        public Request $request,
        public string $key,
        public int $status,
    ) {}
}
