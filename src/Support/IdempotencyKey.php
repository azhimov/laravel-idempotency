<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Support;

use Azhimov\Idempotency\Exceptions\InvalidIdempotencyKey;

/**
 * A validated, client-supplied idempotency key.
 */
final readonly class IdempotencyKey
{
    private function __construct(public string $value) {}

    /**
     * @throws InvalidIdempotencyKey
     */
    public static function fromString(string $raw, int $maxLength = 255): self
    {
        $value = trim($raw);

        // The IETF draft defines the header as an RFC 8941 sf-string ("..."),
        // while most clients send the bare value. Accept both.
        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }

        if ($value === '') {
            throw InvalidIdempotencyKey::empty();
        }

        if (strlen($value) > $maxLength) {
            throw InvalidIdempotencyKey::tooLong($maxLength);
        }

        if (preg_match('/^[\x21-\x7E]+$/', $value) !== 1) {
            throw InvalidIdempotencyKey::invalidCharacters();
        }

        return new self($value);
    }
}
