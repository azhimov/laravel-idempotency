<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Exceptions;

use InvalidArgumentException;

final class InvalidIdempotencyKey extends InvalidArgumentException
{
    public static function empty(): self
    {
        return new self('The idempotency key is empty.');
    }

    public static function tooLong(int $maxLength): self
    {
        return new self("The idempotency key must not be longer than {$maxLength} characters.");
    }

    public static function invalidCharacters(): self
    {
        return new self('The idempotency key may only contain visible ASCII characters.');
    }
}
