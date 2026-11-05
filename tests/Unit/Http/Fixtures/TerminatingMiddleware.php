<?php
declare(strict_types=1);

namespace Tests\Unit\Http\Fixtures;

use Closure;
use Framework\Http\Contracts\Middleware;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;

/**
 * Answers the request without ever delegating.
 *
 * `$next` is still declared and still unused, because PHP rejects an
 * implementation that drops a parameter the interface requires — declaring
 * `handle(Request $request)` alone is a fatal at class-load, not a runtime
 * surprise. That is why the interface requires it and why this fixture exists:
 * it is the proof that a terminating middleware's shape is the one the framework
 * documents, so the constraint cannot silently drift.
 *
 * The request value it reads back out is what proves the pipeline handed the
 * *sent* request to the middleware rather than a freshly built one.
 */
final class TerminatingMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next): ResponseInterface
    {
        return new JsonResponse(['terminated' => $request->method()]);
    }
}
