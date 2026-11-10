<?php

declare(strict_types=1);

use Azhimov\Idempotency\Http\Responders\JsonErrorResponder;
use Azhimov\Idempotency\Scope\UserScopeResolver;

// Env values are parsed strictly: a malformed IDEMPOTENCY_TTL or
// IDEMPOTENCY_REQUIRED becomes false/null and the middleware refuses to run
// with a clear config error instead of silently guessing.
return [

    /*
    | Request header that carries the client-generated idempotency key.
    */
    'header' => 'Idempotency-Key',

    /*
    | HTTP methods the middleware protects. Other methods pass through untouched.
    */
    'methods' => ['POST', 'PATCH'],

    /*
    | When true, a protected request without a key is rejected with 400.
    | Can be overridden per route: ->middleware('idempotent:required').
    */
    'required' => filter_var(env('IDEMPOTENCY_REQUIRED', false), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),

    /*
    | How long (seconds) a stored response is replayed. Per route: 'idempotent:ttl=3600'.
    */
    'ttl' => filter_var(env('IDEMPOTENCY_TTL', 86400), FILTER_VALIDATE_INT),

    /*
    | Max time (seconds) a request may hold the lock. Must exceed your slowest handler.
    */
    'lock_timeout' => 30,

    /*
    | Storage backend: 'cache', 'database' or a class implementing
    | Azhimov\Idempotency\Contracts\IdempotencyStore.
    | Prefer 'database' where a lost key is unacceptable: cache entries can be
    | evicted under memory pressure. Give it its own connection (see README).
    */
    'store' => env('IDEMPOTENCY_STORE', 'cache'),

    /*
    | Cache store name for the 'cache' backend (must support atomic locks). null = default.
    */
    'cache_store' => null,

    /*
    | Connection (null = default) and table for the 'database' backend.
    | Publish the migration: php artisan vendor:publish --tag=idempotency-migrations
    */
    'database' => [
        'connection' => null,
        'table' => 'idempotency_keys',
    ],

    /*
    | Header added to replayed responses.
    */
    'replay_header' => 'Idempotent-Replayed',

    /*
    | Inclusive status code ranges whose responses are stored and replayed.
    | 3xx is included: a retried POST that answered with a redirect must not
    | run again. 5xx is never stored (rejected here): the client must be able to retry.
    */
    'store_status_codes' => [
        [200, 499],
    ],

    /*
    | Response headers persisted and sent back on replay.
    */
    'replay_headers' => ['Content-Type', 'Location'],

    /*
    | Resolves who "owns" a key (user, API client, tenant...).
    | Must implement Azhimov\Idempotency\Contracts\ScopeResolver.
    */
    'scope_resolver' => UserScopeResolver::class,

    /*
    | Renders 400/409/422 errors. Must implement Azhimov\Idempotency\Contracts\ErrorResponder.
    | Default body: {"error": {"code": "...", "message": "..."}}
    */
    'error_responder' => JsonErrorResponder::class,

    'max_key_length' => 255,

];
