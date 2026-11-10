<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Http\Middleware;

use Azhimov\Idempotency\Contracts\IdempotencyStore;
use Azhimov\Idempotency\Contracts\ScopeResolver;
use Azhimov\Idempotency\Exceptions\InvalidIdempotencyKey;
use Azhimov\Idempotency\Stores\Results\Completed;
use Azhimov\Idempotency\Stores\Results\InProgress;
use Azhimov\Idempotency\Stores\Results\Mismatch;
use Azhimov\Idempotency\Stores\Results\Started;
use Azhimov\Idempotency\Support\Fingerprint;
use Azhimov\Idempotency\Support\IdempotencyKey;
use Azhimov\Idempotency\Support\StoredResponse;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Executes a request at most once per idempotency key and replays its response.
 *
 * Usage: ->middleware('idempotent'), 'idempotent:required', 'idempotent:ttl=3600'.
 */
final class EnsureIdempotency
{
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly ScopeResolver $scopes,
        private readonly Repository $config,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$options): Response
    {
        $settings = MiddlewareOptions::resolve($this->config, array_values($options));

        if (! $settings->appliesTo($request->getMethod())) {
            return $next($request);
        }

        $rawKey = $request->headers->get($settings->header);

        if ($rawKey === null) {
            if ($settings->required) {
                abort(400, 'The Idempotency-Key header is required.');
            }

            return $next($request);
        }

        try {
            $key = IdempotencyKey::fromString($rawKey, $settings->maxKeyLength);
        } catch (InvalidIdempotencyKey $e) {
            abort(400, $e->getMessage());
        }

        $recordKey = $this->recordKey($request, $key);
        $result = $this->store->begin($recordKey, Fingerprint::fromRequest($request), $settings->lockTimeout);

        return match (true) {
            $result instanceof Started => $this->execute($request, $next, $settings, $recordKey, $result->token),
            $result instanceof Completed => $this->replay($result->response, $settings),
            $result instanceof InProgress => abort(409, 'A request with this idempotency key is in progress.'),
            $result instanceof Mismatch => abort(422, 'This idempotency key was used with a different request.'),
            default => throw new LogicException('Unknown begin result '.$result::class),
        };
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    private function execute(Request $request, Closure $next, MiddlewareOptions $settings, string $recordKey, string $token): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->store->release($recordKey, $token);

            throw $e;
        }

        if (! StoredResponse::canCapture($response) || ! $settings->shouldStore($response->getStatusCode())) {
            $this->store->release($recordKey, $token);

            return $response;
        }

        $this->store->complete(
            $recordKey,
            $token,
            StoredResponse::fromResponse($response, $settings->replayHeaders),
            $settings->ttl,
        );

        return $response;
    }

    private function replay(StoredResponse $stored, MiddlewareOptions $settings): Response
    {
        $response = $stored->toResponse();
        $response->headers->set($settings->replayHeader, 'true');

        return $response;
    }

    /**
     * Fixed-length storage key: owner scope + method + route template + client key.
     */
    private function recordKey(Request $request, IdempotencyKey $key): string
    {
        $route = $request->route();
        $template = $route instanceof Route
            ? ($route->getDomain() ?? '').'/'.ltrim($route->uri(), '/')
            : $request->getPathInfo();

        return hash('sha256', serialize([
            $this->scopes->resolve($request),
            $request->getMethod(),
            $template,
            $key->value,
        ]));
    }
}
