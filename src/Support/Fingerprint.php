<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use JsonException;

/**
 * Hash of everything that defines "the same request": method, path, query and body.
 *
 * Semantically equal payloads produce the same fingerprint (JSON key order,
 * whitespace, query/form parameter order do not matter); anything that could
 * change the outcome of the operation (values, value types, list order,
 * uploaded file contents) does.
 */
final readonly class Fingerprint
{
    private function __construct(public string $value) {}

    public static function fromRequest(Request $request): self
    {
        [$kind, $body] = self::normalizedBody($request);

        $payload = serialize([
            $request->getMethod(),
            $request->getPathInfo(),
            self::sortKeys($request->query->all()),
            $kind,
            $body,
        ]);

        return new self(hash('sha256', $payload));
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException('A fingerprint must be a lowercase sha256 hex digest.');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    /**
     * @return array{string, mixed}
     */
    private static function normalizedBody(Request $request): array
    {
        $content = $request->getContent();

        if ($request->isJson() && $content !== '') {
            try {
                return ['json', self::sortKeys(json_decode($content, true, 512, JSON_THROW_ON_ERROR))];
            } catch (JsonException) {
                return ['raw', $content];
            }
        }

        $form = $request->request->all();
        $files = self::describeFiles($request->allFiles());

        if ($form !== [] || $files !== []) {
            return ['form', [self::sortKeys($form), self::sortKeys($files)]];
        }

        return ['raw', $content];
    }

    /**
     * Recursively sorts associative arrays by key; lists keep their order.
     */
    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sortKeys(...), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array<array-key, mixed>
     */
    private static function describeFiles(array $files): array
    {
        $described = [];

        foreach ($files as $name => $file) {
            $described[$name] = match (true) {
                $file instanceof UploadedFile => [
                    'name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'sha256' => hash_file('sha256', $file->getPathname()),
                ],
                is_array($file) => self::describeFiles($file),
                default => null,
            };
        }

        return $described;
    }
}
