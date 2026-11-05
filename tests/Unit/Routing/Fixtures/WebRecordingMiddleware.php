<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Closure;
use Framework\Http\Contracts\Middleware;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;

/**
 * An HTTP-contract middleware that records its entry and exit.
 *
 * Both halves are recorded because the onion is the property worth proving: a
 * middleware that ran only before the destination would satisfy "the middleware
 * ran" while being unable to decorate the response, which is the capability that
 * distinguishes the HTTP pipeline from the REST permission seam.
 *
 * The log is static because the pipeline resolves a fresh instance per request.
 */
final class WebRecordingMiddleware implements Middleware
{
    /** @var list<string> */
    public static array $log = [];

    public function handle(Request $request, Closure $next): ResponseInterface
    {
        self::$log[] = 'in';

        $response = $next($request);

        self::$log[] = 'out';

        return $response;
    }
}
