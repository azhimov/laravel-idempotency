<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Stores\Results;

use Azhimov\Idempotency\Support\StoredResponse;

/**
 * The operation already finished; its response must be replayed.
 */
final readonly class Completed implements BeginResult
{
    public function __construct(public StoredResponse $response) {}
}
