<?php
declare(strict_types=1);

namespace Tests\Unit\Http\Fixtures;

use Closure;
use Framework\Http\Contracts\Middleware;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;

/**
 * Records the parameters the pipeline handed it, and passes the request on.
 *
 * The log is a static on a shared recorder rather than an instance property
 * because the container resolves a fresh middleware instance per request — an
 * instance property would be written to once and read by nobody.
 */
final class ParameterizedMiddleware implements Middleware
{
    /**
     * One entry per invocation, each the parameter list it received.
     *
     * @var list<list<string>>
     */
    public static array $received = [];

    /**
     * @param string ...$arguments The parameters from the stack entry.
     */
    public function handle(Request $request, Closure $next, string ...$arguments): ResponseInterface
    {
        self::$received[] = array_values($arguments);

        return $next($request);
    }

    public static function reset(): void
    {
        self::$received = [];
    }
}
