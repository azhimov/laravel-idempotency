<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Scope;

use Azhimov\Idempotency\Contracts\ScopeResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Authenticated requests are scoped by user model and id; guests by client IP.
 *
 * Put the auth middleware before `idempotent`, otherwise every request looks
 * like a guest. Guests behind one NAT share an IP — prefer `required` keys
 * on authenticated routes only.
 */
final class UserScopeResolver implements ScopeResolver
{
    public function resolve(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof Authenticatable) {
            $id = $user->getAuthIdentifier();

            return 'user:'.$user::class.':'.(is_scalar($id) ? (string) $id : '');
        }

        return 'guest:'.($request->ip() ?? 'unknown');
    }
}
