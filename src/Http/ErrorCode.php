<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Http;

/**
 * Machine-readable reasons the middleware refused a request.
 */
enum ErrorCode: string
{
    case KeyMissing = 'idempotency_key_missing';
    case KeyInvalid = 'idempotency_key_invalid';
    case KeyReused = 'idempotency_key_reused';
    case RequestInProgress = 'idempotency_request_in_progress';

    public function status(): int
    {
        return match ($this) {
            self::KeyMissing, self::KeyInvalid => 400,
            self::KeyReused => 422,
            self::RequestInProgress => 409,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::KeyMissing => 'This endpoint requires an Idempotency-Key header.',
            self::KeyInvalid => 'The Idempotency-Key header is invalid.',
            self::KeyReused => 'This idempotency key was already used with a different request.',
            self::RequestInProgress => 'A request with this idempotency key is still being processed. Retry later.',
        };
    }
}
