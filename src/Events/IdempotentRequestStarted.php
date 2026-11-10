<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Events;

use Illuminate\Http\Request;

/**
 * The key was claimed; the handler is about to run.
 */
final readonly class IdempotentRequestStarted
{
    public function __construct(
        public Request $request,
        public string $key,
    ) {}
}
