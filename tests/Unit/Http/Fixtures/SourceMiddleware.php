<?php
declare(strict_types=1);

namespace Tests\Unit\Http\Fixtures;

use Closure;
use Framework\Http\Contracts\Middleware;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;

/**
 * Delegates to a *required* constructor dependency, which is what makes it
 * evidence that the container built it.
 *
 * The parameter is typed and has no default, so `new SourceMiddleware()` inside
 * the pipeline would be a fatal. Whatever the injected object appends to the log
 * therefore came from the container.
 */
final class SourceMiddleware implements Middleware
{
    public function __construct(private readonly RecordingMiddleware $inner)
    {
    }

    public function handle(Request $request, Closure $next): ResponseInterface
    {
        // Delegated to rather than a separate layer: the point is that this
        // object exists at all, and its log entry is the proof of where it came
        // from.
        return $this->inner->handle($request, $next);
    }
}
