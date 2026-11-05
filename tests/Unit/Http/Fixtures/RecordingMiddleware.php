<?php
declare(strict_types=1);

namespace Tests\Unit\Http\Fixtures;

use Closure;
use Framework\Http\Contracts\Middleware;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;

/**
 * Appends its label to a shared log, before and after delegating.
 *
 * The log is a static on a shared recorder rather than an instance property
 * because the container resolves a fresh middleware instance per request — an
 * instance property would be written to once and read by nobody.
 *
 * The label is supplied through a contextual binding in the test, which is also
 * what proves the pipeline resolves middleware through the container rather than
 * instantiating it directly.
 */
final class RecordingMiddleware implements Middleware
{
    /** @var list<string> */
    public static array $log = [];

    public function __construct(private readonly string $label = 'unlabelled')
    {
    }

    public function handle(Request $request, Closure $next): ResponseInterface
    {
        self::$log[] = $this->label . ':in';

        $response = $next($request);

        self::$log[] = $this->label . ':out';

        return $response;
    }

    public static function reset(): void
    {
        self::$log = [];
    }
}
