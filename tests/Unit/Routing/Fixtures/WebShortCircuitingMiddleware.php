<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Closure;
use Framework\Http\Contracts\Middleware;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;
use Framework\Http\Responses\HtmlResponse;

/**
 * An HTTP-contract middleware that refuses without calling the destination.
 *
 * This is the shape a web route's authorization takes. Unlike the REST seam —
 * where a middleware can only deny and must let WordPress render the error — here
 * the middleware owns the response and can send a login redirect, a 403 page, or
 * anything else it decides the caller should see.
 *
 * The interface requires `$next` even though this never calls it: PHP rejects an
 * implementation whose signature omits a parameter the interface declares, at
 * class-load time.
 */
final class WebShortCircuitingMiddleware implements Middleware
{
    public static bool $called = false;

    public function handle(Request $request, Closure $next): ResponseInterface
    {
        self::$called = true;

        return new HtmlResponse('<p>refused</p>', 403);
    }
}
