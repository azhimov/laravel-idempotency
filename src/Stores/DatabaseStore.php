<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Stores;

use Azhimov\Idempotency\Contracts\IdempotencyStore;
use Azhimov\Idempotency\Contracts\PurgesExpiredRecords;
use Azhimov\Idempotency\Stores\Results\BeginResult;
use Azhimov\Idempotency\Stores\Results\InProgress;
use Azhimov\Idempotency\Stores\Results\Started;
use Azhimov\Idempotency\Support\Fingerprint;
use Azhimov\Idempotency\Support\StoredResponse;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use JsonException;

/**
 * Durable store: one row per key, processing → completed.
 *
 * - The claim is a primary-key insert (insertOrIgnore: no exception, so a
 *   surrounding PostgreSQL transaction is not aborted on conflict).
 * - Takeover, complete and release are single conditional UPDATE/DELETE
 *   statements (compare-and-set on owner token and status).
 * - Unlike a cache, records are never evicted: run `idempotency:purge`.
 */
final class DatabaseStore implements IdempotencyStore, PurgesExpiredRecords
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table = 'idempotency_keys',
    ) {}

    public function begin(string $key, Fingerprint $fingerprint, int $lockTimeout): BeginResult
    {
        $now = Date::now()->getTimestamp();
        $claim = Record::processing($fingerprint, $now + $lockTimeout);

        if ($this->query()->insertOrIgnore(['key_hash' => $key, 'created_at' => $now] + $this->columns($claim, $now)) === 1) {
            return new Started($claim->token);
        }

        $existing = $this->find($key);

        if ($existing === null) {
            // Deleted between our insert and read: let the client retry.
            return new InProgress(1);
        }

        if (! $existing->isExpired($now)) {
            return $existing->blockingResult($fingerprint, $now);
        }

        // Take over a dead record, unless someone changed it since we read it.
        $taken = $this->query()
            ->where('key_hash', $key)
            ->where('owner_token', $existing->token)
            ->where('status', $existing->status->value)
            ->update($this->columns($claim, $now));

        return $taken === 1 ? new Started($claim->token) : new InProgress(1);
    }

    public function complete(string $key, string $token, StoredResponse $response, int $ttl): bool
    {
        $record = $this->find($key);

        if ($record === null || ! $record->isOwnedBy($token)) {
            return false;
        }

        $now = Date::now()->getTimestamp();
        $completed = $record->complete($response, $now + $ttl);

        return $this->query()
            ->where('key_hash', $key)
            ->where('owner_token', $token)
            ->where('status', RecordStatus::Processing->value)
            ->update($this->columns($completed, $now)) === 1;
    }

    public function release(string $key, string $token): void
    {
        $this->query()
            ->where('key_hash', $key)
            ->where('owner_token', $token)
            ->where('status', RecordStatus::Processing->value)
            ->delete();
    }

    public function purgeExpired(): int
    {
        return $this->query()
            ->where('purge_after', '<=', Date::now()->getTimestamp())
            ->delete();
    }

    private function query(): Builder
    {
        return $this->connection->table($this->table);
    }

    private function find(string $key): ?Record
    {
        $row = $this->query()->where('key_hash', $key)->first();

        if ($row === null) {
            return null;
        }

        $row = (array) $row;
        $response = $row['response'] ?? null;

        try {
            $response = is_string($response) ? json_decode($response, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $response = false; // rejected by Record::fromArray
        }

        return Record::fromArray([
            'status' => $row['status'] ?? null,
            'fingerprint' => $row['fingerprint'] ?? null,
            'token' => $row['owner_token'] ?? null,
            'locked_until' => self::int($row['locked_until'] ?? null),
            'expires_at' => self::int($row['expires_at'] ?? null),
            'response' => $response,
        ]);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function columns(Record $record, int $now): array
    {
        return [
            'fingerprint' => $record->fingerprint->value,
            'owner_token' => $record->token,
            'status' => $record->status->value,
            'locked_until' => $record->lockedUntil,
            'expires_at' => $record->expiresAt,
            'purge_after' => $record->purgeAfter(),
            'response' => $record->response === null
                ? null
                : json_encode($record->response->toArray(), JSON_THROW_ON_ERROR),
            'updated_at' => $now,
        ];
    }

    /**
     * Some drivers (e.g. MySQL with emulated prepares) return integers as strings.
     */
    private static function int(mixed $value): mixed
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : $value;
    }
}
