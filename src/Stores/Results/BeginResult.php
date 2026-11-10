<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Stores\Results;

/**
 * Outcome of IdempotencyStore::begin(): Started | InProgress | Completed | Mismatch.
 */
interface BeginResult {}
