<?php
declare(strict_types=1);

namespace Tests\Unit\Http\Fixtures;

use Closure;
use Framework\Http\Contracts\Middleware;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;

/**
 * Marks the response on the way out, which is the half of the onion a
 * deny-only check cannot exercise: the middleware must see the response the
 * *destination* produced, not merely run before it.
 */
final class AddingMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next): ResponseInterface
    {
        $response = $next($request);

        // Only JsonResponse is marked, because that is what the fixture
        // destination returns. Anything else comes back untouched so a test can
        // tell "middleware ran" from "middleware ran and recognised the body".
        if ($response instanceof JsonResponse) {
            return $response->with([
                'headers' => ['X-Middleware' => implode(',', (array) ($response->options()['headers'] ?? [])) . ',outer'],
            ]);
        }

        return $response;
    }
}
