<?php

declare(strict_types=1);

use Azhimov\Idempotency\Http\Responders\JsonErrorResponder;
use Azhimov\Idempotency\Scope\UserScopeResolver;

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
    'required' => false,

    /*
    | How long (seconds) a stored response is replayed. Per route: 'idempotent:ttl=3600'.
    */
    'ttl' => 86400,

    /*
    | Max time (seconds) a request may hold the lock. Must exceed your slowest handler.
    */
    'lock_timeout' => 30,

    /*
    | Storage backend: 'cache' or 'database'.
    */
    'store' => 'cache',

    /*
    | Cache store name for the 'cache' backend (must support atomic locks). null = default.
    */
    'cache_store' => null,

    /*
    | Header added to replayed responses.
    */
    'replay_header' => 'Idempotent-Replayed',

    /*
    | Inclusive status code ranges whose responses are stored and replayed.
    | 5xx is intentionally excluded: the client must be able to retry.
    */
    'store_status_codes' => [
        [200, 299],
        [400, 499],
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
