<?php

declare(strict_types=1);

use Azhimov\Idempotency\Contracts\ScopeResolver;
use Azhimov\Idempotency\Scope\UserScopeResolver;
use Azhimov\Idempotency\Tests\Fixtures\Admin;
use Azhimov\Idempotency\Tests\Fixtures\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

function scopeFor(?Authenticatable $user, string $ip = '203.0.113.10'): string
{
    $request = Request::create('/payments', 'POST', server: ['REMOTE_ADDR' => $ip]);
    $request->setUserResolver(fn () => $user);

    return (new UserScopeResolver)->resolve($request);
}

it('scopes authenticated requests by user', function (): void {
    expect(scopeFor(User::withId(7)))->toBe('user:'.User::class.':7')
        ->and(scopeFor(User::withId(7)))->not->toBe(scopeFor(User::withId(8)));
});

it('does not mix up different user models with the same id', function (): void {
    expect(scopeFor(User::withId(1)))->not->toBe(scopeFor(Admin::withId(1)));
});

it('ignores the IP of authenticated users', function (): void {
    expect(scopeFor(User::withId(7), '198.51.100.1'))->toBe(scopeFor(User::withId(7), '198.51.100.2'));
});

it('scopes guests by IP so they never share keys globally', function (): void {
    expect(scopeFor(null, '198.51.100.1'))->toBe('guest:198.51.100.1')
        ->and(scopeFor(null, '198.51.100.1'))->not->toBe(scopeFor(null, '198.51.100.2'));
});

it('is the default resolver and can be swapped in config', function (): void {
    expect(app(ScopeResolver::class))->toBeInstanceOf(UserScopeResolver::class);

    $custom = new class implements ScopeResolver
    {
        public function resolve(Request $request): string
        {
            return 'tenant:acme';
        }
    };
    app()->instance($custom::class, $custom);
    config(['idempotency.scope_resolver' => $custom::class]);
    app()->forgetInstance(ScopeResolver::class);

    expect(app(ScopeResolver::class))->toBe($custom);
});

it('rejects a scope resolver that does not implement the contract', function (): void {
    config(['idempotency.scope_resolver' => stdClass::class]);
    app()->forgetInstance(ScopeResolver::class);

    app(ScopeResolver::class);
})->throws(InvalidArgumentException::class, 'ScopeResolver');
