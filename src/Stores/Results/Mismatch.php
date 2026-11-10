<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Stores\Results;

/**
 * The key is already used for a request with a different fingerprint.
 */
final readonly class Mismatch implements BeginResult {}
