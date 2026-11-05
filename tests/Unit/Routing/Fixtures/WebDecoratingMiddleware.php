<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Closure;
use Framework\Http\Contracts\Middleware;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;
use Framework\Http\Responses\HtmlResponse;

/**
 * An HTTP middleware that rewrites the response on the way out.
 *
 * Proves the pipeline hands the controller's response *back* through the stack.
 * A middleware that only ran before the destination would still satisfy "the
 * middleware ran", so the recorded status is what distinguishes the two — 202
 * where the controller returned 201.
 */
final class WebDecoratingMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next): ResponseInterface
    {
        $response = $next($request);

        return $response instanceof HtmlResponse
            ? $response->with([], 202)
            : $response;
    }
}
