<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Support;

use Illuminate\Http\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The part of an HTTP response that is persisted and replayed.
 */
final readonly class StoredResponse
{
    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers,
    ) {}

    /**
     * Streamed and file responses are produced while being sent, so their body
     * cannot be captured in the middleware.
     */
    public static function canCapture(SymfonyResponse $response): bool
    {
        return ! $response instanceof StreamedResponse
            && ! $response instanceof BinaryFileResponse;
    }

    /**
     * @param  list<string>  $headerNames  whitelist of headers to keep
     */
    public static function fromResponse(SymfonyResponse $response, array $headerNames): self
    {
        $headers = [];

        foreach ($headerNames as $name) {
            $values = array_values(array_filter(
                $response->headers->all($name),
                static fn (?string $value): bool => $value !== null,
            ));

            if ($values !== []) {
                $headers[$name] = $values;
            }
        }

        return new self($response->getStatusCode(), (string) $response->getContent(), $headers);
    }

    public function toResponse(): Response
    {
        return new Response($this->body, $this->status, $this->headers);
    }

    /**
     * @return array{status: int, body: string, headers: array<string, list<string>>}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'body' => base64_encode($this->body),
            'headers' => $this->headers,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $status = $data['status'] ?? null;
        $body = is_string($data['body'] ?? null) ? base64_decode($data['body'], true) : false;
        $headers = $data['headers'] ?? null;

        if (! is_int($status) || $status < 100 || $status > 599) {
            throw new InvalidArgumentException('Stored response has an invalid status.');
        }

        if ($body === false) {
            throw new InvalidArgumentException('Stored response has an invalid body.');
        }

        return new self($status, $body, self::validateHeaders($headers));
    }

    /**
     * @return array<string, list<string>>
     */
    private static function validateHeaders(mixed $headers): array
    {
        if (! is_array($headers)) {
            throw new InvalidArgumentException('Stored response headers must be an array.');
        }

        $valid = [];

        foreach ($headers as $name => $values) {
            if (! is_string($name) || ! is_array($values) || ! array_is_list($values)) {
                throw new InvalidArgumentException('Stored response headers must map names to lists of values.');
            }

            $valid[$name] = array_map(static function (mixed $value): string {
                if (! is_string($value)) {
                    throw new InvalidArgumentException('Stored response header values must be strings.');
                }

                return $value;
            }, $values);
        }

        return $valid;
    }
}
