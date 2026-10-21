<?php

namespace Folklore\Mediatheque\Dashboard;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through when the user passes the dashboard's gate: `view`
 * for every route, and `operate` as well for actions.
 */
final class Authorize
{
    public function __construct(private readonly Gates $gates) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $ability = 'view'): Response
    {
        if (! $this->gates->allows($request, $ability === 'operate' ? Gates::OPERATE : Gates::VIEW)) {
            return $request->expectsJson()
                ? Problem::response(403, 'This action is unauthorized.')
                : new Response('Forbidden', 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return $next($request);
    }
}
