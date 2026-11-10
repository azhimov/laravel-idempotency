<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Contracts;

use Azhimov\Idempotency\Stores\Results\BeginResult;
use Azhimov\Idempotency\Support\Fingerprint;
use Azhimov\Idempotency\Support\StoredResponse;

/**
 * Persists idempotency records and owns the lock on them.
 *
 * A record goes processing → completed. Every acquisition gets a fresh owner
 * token; complete() and release() only act while the caller still owns the
 * record (compare-and-set), so a request whose lock expired can never
 * overwrite the outcome of a request that took the key over.
 *
 * Implementations must make each method atomic per key.
 */
interface IdempotencyStore
{
    /**
     * Atomically claims the key, or reports why it cannot be claimed.
     *
     * @param  string  $key  opaque, already scoped record key (≤ 64 chars)
     * @param  int  $lockTimeout  seconds the claim stays exclusive
     */
    public function begin(string $key, Fingerprint $fingerprint, int $lockTimeout): BeginResult;

    /**
     * Stores the response for $ttl seconds. Returns false when $token no
     * longer owns the record (the lock expired and was taken over).
     */
    public function complete(string $key, string $token, StoredResponse $response, int $ttl): bool;

    /**
     * Drops an in-progress claim so the client can retry. No-op if $token
     * does not own the record.
     */
    public function release(string $key, string $token): void;
}
