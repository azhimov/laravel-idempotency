<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Contracts;

use Azhimov\Idempotency\Http\ErrorCode;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the middleware's own error responses (400, 409, 422).
 * The middleware adds Retry-After itself where it applies.
 */
interface ErrorResponder
{
    public function respond(Request $request, ErrorCode $code, string $message): Response;
}
