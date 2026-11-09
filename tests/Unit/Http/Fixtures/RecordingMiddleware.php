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
 *
 * ## Why this is not `final`
 *
 * It is the base the pipeline tests extend to get two distinct class names for
 * two stack positions — {@see OuterMiddleware} and {@see InnerMiddleware} — and
 * distinct names are required because the pipeline resolves middleware *by
 * class*, so two entries of one class are two resolutions of one binding and
 * cannot carry different labels. A `final` here would make those subclasses
 * unloadable: PHP fatals at class *declaration*, which is not catchable, so any
 * `class_exists()` or container warm-up that reached one would kill the process
 * rather than fail a test. That is also why the three subclasses must be
 * genuinely loadable rather than merely never resolved — the short-circuit test
 * happens to never load {@see BehindMiddleware}, but that is a property of the
 * test, not a guarantee the class may rely on.
 *
 * Subclassing to configure a base middleware is the documented way to do it, so
 * this mirrors the real shape rather than working around it.
 */
class RecordingMiddleware implements Middleware
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
