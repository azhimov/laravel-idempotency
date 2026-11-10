<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Contracts;

use Illuminate\Http\Request;

/**
 * Decides who owns an idempotency key: the same key from two different
 * owners never collides. The middleware adds the HTTP method and route
 * template on top of this value.
 */
interface ScopeResolver
{
    public function resolve(Request $request): string;
}
