<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Stores\Results;

use InvalidArgumentException;

/**
 * The caller owns the key and must eventually complete() or release() it.
 */
final readonly class Started implements BeginResult
{
    public function __construct(public string $token)
    {
        if ($token === '') {
            throw new InvalidArgumentException('An owner token must not be empty.');
        }
    }
}
