<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Contracts;

/**
 * Implemented by stores whose records do not expire on their own.
 */
interface PurgesExpiredRecords
{
    /**
     * Deletes expired responses and abandoned locks; returns how many records were removed.
     */
    public function purgeExpired(): int;
}
