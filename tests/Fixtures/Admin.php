<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class Admin extends Authenticatable
{
    protected $guarded = [];

    public static function withId(int $id): self
    {
        return (new self)->forceFill(['id' => $id]);
    }
}
