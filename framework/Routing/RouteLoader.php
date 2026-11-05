<?php
declare(strict_types=1);

namespace Framework\Routing;

use Framework\Routing\Contracts\RouterInterface;
use Throwable;

/**
 * Reads a routes file into a router.
 *
 * ## Why this is a class and not three lines in the service provider
 *
 * A routes file is normally loaded from inside a WordPress action, which is fine
 * while the running request is the one being routed. `wp wp_mini route:list` is
 * the case that breaks: WP-CLI fires none of `rest_api_init`, `admin_menu`,
 * `admin_post` or `template_redirect`, so a command that wants to list the routes
 * would find two empty tables and report "no routes" — a wrong answer, not an
 * error, which is the worst kind.
 *
 * So the load has to be callable *outside* the hooks, and the caller that does it
 * must not be the service provider's own closures. This class is that single
 * entry point: both the provider and the command go through it, which is also
 * what keeps the two from disagreeing about what loading a routes file means.
 *
 * ## Loading twice is the failure to avoid
 *
 * The routers are singletons holding their route tables, so loading the same
 * file twice adds every route a second time. For REST that trips the duplicate
 * guard and throws; for the web table it would produce a doubled listing. The
 * `$loaded` map below is therefore keyed by **file path**, not by router, and
 * lives on the loader — which is itself bound as a singleton, so the record
 * survives from the CLI entry point through to whatever else asks.
 *
 * ## Failures are reported, never thrown
 *
 * A broken routes file must not take down the request that discovered it. Every
 * entry point here returns a result instead of throwing: the provider logs, the
 * command prints the problem as a warning and still lists what it has. A
 * deployment typo should cost a log line and a confusing page, not a white screen
 * with no way to reach the screen that would have explained it.
 */
final class RouteLoader
{
    /**
     * Files already loaded, so a second load is a no-op.
     *
     * Keyed by absolute path rather than by router, because the same file must
     * not load into a router twice *and* one file must not load into two routers
     * at all — the second would put web routes in the API table. Path keying
     * expresses both.
     *
     * @var array<string, bool>
     */
    private array $loaded = [];

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * Read a routes file into a router.
     *
     * @param string|null $file Path relative to the base path. Null loads nothing.
     *
     * @return RouteLoadResult What happened, for the caller to report.
     */
    public function load(RouterInterface $router, ?string $file): RouteLoadResult
    {
        if ($file === null) {
            return RouteLoadResult::skipped('No routes file is configured.');
        }

        $path = $this->absolute($file);

        if (!is_file($path)) {
            // Ordinary, not a failure: a project with no web routes is a project
            // with no routes/web.php, and it should boot quietly.
            return RouteLoadResult::skipped(sprintf('No routes file at [%s].', $path));
        }

        if (isset($this->loaded[$path])) {
            return RouteLoadResult::alreadyLoaded($path);
        }

        // Marked before the file runs, not after. A routes file that throws
        // half-way has still executed its top-level statements, and re-running it
        // on the next hook would re-apply them — for a file that registers routes
        // before failing, that means a duplicate-guard throw from inside an action
        // the next time round, which is a far more confusing symptom than the
        // original mistake.
        $this->loaded[$path] = true;

        try {
            /** @var mixed $routes */
            $routes = require $path;
        } catch (Throwable $e) {
            return RouteLoadResult::failure($path, $e);
        }

        if (!is_callable($routes)) {
            return RouteLoadResult::failure($path, new \RuntimeException(sprintf(
                'A routes file must return a callable that receives the route registrar. '
                . '[%s] returned [%s].',
                $path,
                get_debug_type($routes)
            )));
        }

        try {
            $routes($router->registrar());
        } catch (Throwable $e) {
            // Registration errors arrive here: an unsupported verb, a duplicate
            // path, or a handler-less route the file declared but never finished.
            // Reported with the file named, because the mistake is in the file.
            return RouteLoadResult::failure($path, $e);
        }

        return RouteLoadResult::loaded($path, count($router->getRoutes()));
    }

    /**
     * Whether a file has already been loaded.
     */
    public function hasLoaded(string $file): bool
    {
        return isset($this->loaded[$this->absolute($file)]);
    }

    /**
     * Forget every loaded file. For tests, which run several scenarios in one
     * process against a fresh container.
     */
    public function reset(): void
    {
        $this->loaded = [];
    }

    private function absolute(string $file): string
    {
        // A leading slash or a drive letter means the caller gave a full path —
        // `base_path()` would then prepend the base again and never find it.
        if (str_starts_with($file, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $file) === 1) {
            return $file;
        }

        return rtrim($this->basePath, '/\\') . DIRECTORY_SEPARATOR . ltrim($file, '/\\');
    }
}
