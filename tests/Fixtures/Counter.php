<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Response;

/**
 * Counts how many times a test route's handler actually ran and keeps
 * the response of a request issued from inside a handler.
 */
final class Counter
{
    public static int $calls = 0;

    public static ?Response $inner = null;

    public static function reset(): void
    {
        self::$calls = 0;
        self::$inner = null;
    }
}
