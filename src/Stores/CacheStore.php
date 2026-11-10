<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Stores;

use Azhimov\Idempotency\Contracts\IdempotencyStore;
use Azhimov\Idempotency\Stores\Results\BeginResult;
use Azhimov\Idempotency\Stores\Results\InProgress;
use Azhimov\Idempotency\Stores\Results\Started;
use Azhimov\Idempotency\Support\Fingerprint;
use Azhimov\Idempotency\Support\StoredResponse;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * Stores records in any Laravel cache store that supports atomic locks.
 *
 * Every state transition runs as read-modify-write under a short per-key
 * mutex (a cache lock), which makes begin/complete/release atomic per key.
 *
 * Caveat: a cache may evict entries under memory pressure. An evicted
 * record means a retry executes again — use the database store when that
 * is unacceptable (e.g. payments).
 */
final class CacheStore implements IdempotencyStore
{
    /**
     * A processing entry outlives its logical lock (locked_until) by this many
     * seconds, so a request that finishes late can still store its response
     * when nobody has taken the key over.
     */
    private const LATE_COMPLETION_GRACE = 3600;

    private readonly LockProvider $locks;

    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'idempotency:',
        private readonly int $mutexSeconds = 10,
        private readonly int $mutexWaitSeconds = 5,
    ) {
        $store = $cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new InvalidArgumentException('The idempotency cache store must support atomic locks (e.g. redis, memcached, database, file, array).');
        }

        $this->locks = $store;
    }

    public function begin(string $key, Fingerprint $fingerprint, int $lockTimeout): BeginResult
    {
        try {
            return $this->locked($key, function () use ($key, $fingerprint, $lockTimeout): BeginResult {
                $now = Date::now()->getTimestamp();
                $record = $this->read($key);

                if ($record !== null && ! $record->isExpired($now)) {
                    return $record->blockingResult($fingerprint, $now);
                }

                $record = Record::processing($fingerprint, $now + $lockTimeout);
                $this->cache->put($this->prefix.$key, $record->toArray(), $lockTimeout + self::LATE_COMPLETION_GRACE);

                return new Started($record->token);
            });
        } catch (LockTimeoutException) {
            // Another transition on this key is running right now.
            return new InProgress(1);
        }
    }

    public function complete(string $key, string $token, StoredResponse $response, int $ttl): bool
    {
        try {
            return $this->locked($key, function () use ($key, $token, $response, $ttl): bool {
                $record = $this->read($key);

                if ($record === null || ! $record->isOwnedBy($token)) {
                    return false;
                }

                $completed = $record->complete($response, Date::now()->getTimestamp() + $ttl);
                $this->cache->put($this->prefix.$key, $completed->toArray(), $ttl);

                return true;
            });
        } catch (LockTimeoutException) {
            return false;
        }
    }

    public function release(string $key, string $token): void
    {
        try {
            $this->locked($key, function () use ($key, $token): void {
                if ($this->read($key)?->isOwnedBy($token) === true) {
                    $this->cache->forget($this->prefix.$key);
                }
            });
        } catch (LockTimeoutException) {
            // The claim expires on its own after the lock timeout.
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws LockTimeoutException
     */
    private function locked(string $key, callable $callback): mixed
    {
        return $this->locks
            ->lock($this->prefix.$key.':mutex', $this->mutexSeconds)
            ->block($this->mutexWaitSeconds, $callback);
    }

    private function read(string $key): ?Record
    {
        $data = $this->cache->get($this->prefix.$key);

        return is_array($data) ? Record::fromArray($data) : null;
    }
}
