<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Stores\Results;

/**
 * Another request with the same key and payload is still running.
 */
final readonly class InProgress implements BeginResult
{
    /** Seconds the client should wait before retrying (≥ 1). */
    public int $retryAfter;

    public function __construct(int $retryAfter)
    {
        $this->retryAfter = max(1, $retryAfter);
    }
}
