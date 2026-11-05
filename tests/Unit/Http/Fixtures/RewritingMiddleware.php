<?php
declare(strict_types=1);

namespace Tests\Unit\Http\Fixtures;

use Closure;
use Framework\Http\Contracts\Middleware;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;

/**
 * Replaces the request before delegating.
 *
 * `Request` is immutable, so a middleware "modifying" the request can only do it
 * by constructing a new one and passing that down — which is exactly what this
 * does. The point of the fixture is that the *destination* sees the new request
 * and the layer outside this one still holds the original.
 */
final class RewritingMiddleware implements Middleware
{
    public function __construct(private readonly string $header = 'x-middleware-tag')
    {
    }

    public function handle(Request $request, Closure $next): ResponseInterface
    {
        $rewritten = new Request(
            query: $request->all(),
            headers: [$this->header => 'rewritten'] + $request->headers()
        );

        $response = $next($rewritten);

        return $response instanceof JsonResponse
            ? $response->with(['status_code' => 202])
            : $response;
    }
}
