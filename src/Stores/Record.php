<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Stores;

use Azhimov\Idempotency\Stores\Results\BeginResult;
use Azhimov\Idempotency\Stores\Results\Completed;
use Azhimov\Idempotency\Stores\Results\InProgress;
use Azhimov\Idempotency\Stores\Results\Mismatch;
use Azhimov\Idempotency\Support\Fingerprint;
use Azhimov\Idempotency\Support\StoredResponse;
use InvalidArgumentException;
use LogicException;

/**
 * One idempotency record, as stored. Holds the decision rules shared by all
 * built-in stores so they behave identically.
 *
 * @internal
 */
final readonly class Record
{
    /**
     * @param  int  $lockedUntil  unix time the processing claim expires
     * @param  int  $expiresAt  unix time the completed response expires (0 while processing)
     */
    public function __construct(
        public RecordStatus $status,
        public Fingerprint $fingerprint,
        public string $token,
        public int $lockedUntil,
        public int $expiresAt = 0,
        public ?StoredResponse $response = null,
    ) {
        if (($status === RecordStatus::Completed) !== ($response !== null)) {
            throw new LogicException('Only a completed record carries a response.');
        }
    }

    public static function processing(Fingerprint $fingerprint, int $lockedUntil): self
    {
        return new self(RecordStatus::Processing, $fingerprint, bin2hex(random_bytes(16)), $lockedUntil);
    }

    public function complete(StoredResponse $response, int $expiresAt): self
    {
        return new self(RecordStatus::Completed, $this->fingerprint, $this->token, $this->lockedUntil, $expiresAt, $response);
    }

    /**
     * An expired record is dead: a new request may take the key over.
     */
    public function isExpired(int $now): bool
    {
        return $this->status === RecordStatus::Processing
            ? $this->lockedUntil <= $now
            : $this->expiresAt <= $now;
    }

    public function isOwnedBy(string $token): bool
    {
        return $this->status === RecordStatus::Processing && hash_equals($this->token, $token);
    }

    /**
     * Why a live record blocks a new request. Must not be called on an expired record.
     */
    public function blockingResult(Fingerprint $fingerprint, int $now): BeginResult
    {
        if (! $this->fingerprint->equals($fingerprint)) {
            return new Mismatch;
        }

        if ($this->response !== null) {
            return new Completed($this->response);
        }

        return new InProgress($this->lockedUntil - $now);
    }

    /**
     * @return array{status: string, fingerprint: string, token: string, locked_until: int, expires_at: int, response: array<string, mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'fingerprint' => $this->fingerprint->value,
            'token' => $this->token,
            'locked_until' => $this->lockedUntil,
            'expires_at' => $this->expiresAt,
            'response' => $this->response?->toArray(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $status = RecordStatus::tryFrom(is_string($data['status'] ?? null) ? $data['status'] : '');
        $fingerprint = $data['fingerprint'] ?? null;
        $token = $data['token'] ?? null;
        $lockedUntil = $data['locked_until'] ?? null;
        $expiresAt = $data['expires_at'] ?? null;
        $response = $data['response'] ?? null;

        if ($status === null || ! is_string($fingerprint) || ! is_string($token) || $token === ''
            || ! is_int($lockedUntil) || ! is_int($expiresAt) || ($response !== null && ! is_array($response))) {
            throw new InvalidArgumentException('Malformed idempotency record.');
        }

        return new self(
            $status,
            Fingerprint::fromString($fingerprint),
            $token,
            $lockedUntil,
            $expiresAt,
            $response === null ? null : StoredResponse::fromArray($response),
        );
    }
}
