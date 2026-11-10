<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Tests\Fixtures;

/**
 * Counts how many times a test route's handler actually ran.
 */
final class Counter
{
    public static int $calls = 0;
}
