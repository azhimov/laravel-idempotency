<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Http\Middleware;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Effective settings for one middleware invocation: config defaults
 * overridden by route parameters (`idempotent:required,ttl=3600`).
 *
 * @internal
 */
final readonly class MiddlewareOptions
{
    /**
     * @param  list<string>  $methods
     * @param  list<array{int, int}>  $storeStatusRanges
     * @param  list<string>  $replayHeaders
     */
    public function __construct(
        public string $header,
        public array $methods,
        public bool $required,
        public int $ttl,
        public int $lockTimeout,
        public string $replayHeader,
        public array $storeStatusRanges,
        public array $replayHeaders,
        public int $maxKeyLength,
    ) {}

    /**
     * @param  list<string>  $routeOptions
     */
    public static function resolve(Repository $config, array $routeOptions): self
    {
        $required = self::bool($config->get('idempotency.required'), 'required');
        $ttl = self::positiveInt($config->get('idempotency.ttl'), 'ttl');

        foreach ($routeOptions as $option) {
            match (true) {
                $option === 'required' => $required = true,
                $option === 'optional' => $required = false,
                str_starts_with($option, 'ttl=') => $ttl = self::positiveInt(
                    filter_var(substr($option, 4), FILTER_VALIDATE_INT), 'ttl',
                ),
                default => throw new InvalidArgumentException(
                    "Unknown idempotent middleware option [{$option}]. Use required, optional or ttl=<seconds>.",
                ),
            };
        }

        return new self(
            header: self::string($config->get('idempotency.header'), 'header'),
            methods: array_map(strtoupper(...), self::strings($config->get('idempotency.methods'), 'methods')),
            required: $required,
            ttl: $ttl,
            lockTimeout: self::positiveInt($config->get('idempotency.lock_timeout'), 'lock_timeout'),
            replayHeader: self::string($config->get('idempotency.replay_header'), 'replay_header'),
            storeStatusRanges: self::ranges($config->get('idempotency.store_status_codes')),
            replayHeaders: self::strings($config->get('idempotency.replay_headers'), 'replay_headers'),
            maxKeyLength: self::positiveInt($config->get('idempotency.max_key_length'), 'max_key_length'),
        );
    }

    public function appliesTo(string $method): bool
    {
        return in_array(strtoupper($method), $this->methods, true);
    }

    public function shouldStore(int $status): bool
    {
        foreach ($this->storeStatusRanges as [$from, $to]) {
            if ($status >= $from && $status <= $to) {
                return true;
            }
        }

        return false;
    }

    private static function string(mixed $value, string $key): string
    {
        if (! is_string($value) || $value === '') {
            throw self::invalid($key, 'a non-empty string');
        }

        return $value;
    }

    private static function bool(mixed $value, string $key): bool
    {
        if (! is_bool($value)) {
            throw self::invalid($key, 'a boolean');
        }

        return $value;
    }

    private static function positiveInt(mixed $value, string $key): int
    {
        if (! is_int($value) || $value < 1) {
            throw self::invalid($key, 'a positive integer');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value, string $key): array
    {
        if (! is_array($value)) {
            throw self::invalid($key, 'a list of strings');
        }

        return array_values(array_map(static fn (mixed $item): string => self::string($item, $key), $value));
    }

    /**
     * @return list<array{int, int}>
     */
    private static function ranges(mixed $value): array
    {
        if (! is_array($value)) {
            throw self::invalid('store_status_codes', 'a list of [from, to] status ranges');
        }

        $ranges = [];

        foreach ($value as $range) {
            if (! is_array($range) || count($range) !== 2
                || ! is_int($range[0] ?? null) || ! is_int($range[1] ?? null) || $range[0] > $range[1]) {
                throw self::invalid('store_status_codes', 'a list of [from, to] status ranges');
            }

            if ($range[1] >= 500) {
                // 5xx must stay retryable: storing it would replay a failure forever.
                throw self::invalid('store_status_codes', 'ranges below 500');
            }

            $ranges[] = [$range[0], $range[1]];
        }

        return $ranges;
    }

    private static function invalid(string $key, string $expected): InvalidArgumentException
    {
        return new InvalidArgumentException("Config [idempotency.{$key}] must be {$expected}.");
    }
}
