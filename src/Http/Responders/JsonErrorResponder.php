<?php

declare(strict_types=1);

namespace Azhimov\Idempotency\Http\Responders;

use Azhimov\Idempotency\Contracts\ErrorResponder;
use Azhimov\Idempotency\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * {"error": {"code": "idempotency_key_reused", "message": "..."}}
 */
final class JsonErrorResponder implements ErrorResponder
{
    public function respond(Request $request, ErrorCode $code, string $message): Response
    {
        return new JsonResponse(
            ['error' => ['code' => $code->value, 'message' => $message]],
            $code->status(),
        );
    }
}
