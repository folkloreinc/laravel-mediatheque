<?php

namespace Folklore\Mediatheque\Dashboard;

use Illuminate\Http\JsonResponse;

/**
 * An `application/problem+json` response (RFC 9457), the shape of every
 * error of the dashboard's endpoints (docs/decisions/0008, 0017).
 */
final class Problem
{
    /**
     * @param  array<string, mixed>  $extra  Members such as `reason` or `errors`
     */
    public static function response(int $status, string $title, ?string $detail = null, array $extra = []): JsonResponse
    {
        return new JsonResponse(array_filter([
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            ...$extra,
        ], fn (mixed $value) => $value !== null), $status, ['Content-Type' => 'application/problem+json']);
    }
}
