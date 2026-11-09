<?php
declare(strict_types=1);

namespace Framework\Routing;

use Framework\Routing\Contracts\RouterInterface;
use Framework\Support\Facades\Route as RouteFacade;
use Throwable;

/**
 * Reads a routes file into a router.
 *
 * Why this is a class and not three lines in the service provider
 *
 * A routes file is normally loaded from inside a WordPress action, which is fine
 * while the running request is the one being routed. `wp wp_mini route:list` is
 * the case that breaks: WP-CLI fires none of `rest_api_init`, `admin_menu`,
 * `admin_post` or `template_redirect`, so a command that wants to list the routes
 * would find two empty tables and report "no routes" - a wrong answer, not an
 * error, which is the worst kind.
 *
 * So the load has to be callable *outside* the hooks, and the caller that does it
 * must not be the service provider's own closures. This class is that single
 * entry point: both the provider and the command go through it, which is also
 * what keeps the two from disagreeing about what loading a routes file means.
 *
 * It owns which router a file writes into
 *
 * A routes file calls `Route::get(...)` - a static facade with no idea which of
 * the two routers is loading it, because the same call appears in `web.php` and
 * `api.php` and must land in different tables. Resolving that is this class's job:
 * each load aims {@see RouteFacade} at the router it was handed, restores the
 * previous target afterwards, and so lets a file be domain-blind without a
 * parameter to carry the domain.
 *
 * Before the facade existed that context travelled as the closure's argument
 * (`$router`), and the file had to declare its type. Now it travels as loader
 * state, which is why this class is the only place allowed to set it.
 *
 * Loading twice is the failure to avoid
 *
 * The routers are singletons holding their route tables, so loading the same
 * file twice adds every route a second time. For REST that trips the duplicate
 * guard and throws; for the web table it would produce a doubled listing. The
 * `$loaded` map below is therefore keyed by **file path and router**, and lives on
 * the loader - which is itself bound as a singleton, so the record survives from
 * the CLI entry point through to whatever else asks.
 *
 * Failures are reported, never thrown
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
     * Keyed by absolute path plus the router class it was loaded into, because the
     * same file must not load into a router twice *and* one file must not load
     * into two routers at all - the second would put web routes in the API table.
     * Path keying expresses the first; the router class is what lets two domains
     * each read their own file through this one loader.
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

        // Keyed by path *and* router class. One file must not load twice into one
        // router - that is the duplicate-registration failure this map exists for -
        // but two routers asking for the same file is a different situation and not
        // an error: `route:list` reads both domains through this one loader, and
        // each domain's table has to be filled for the command to have anything to
        // print. Keying on the path alone would leave whichever table was listed
        // second empty, and an empty table reads as "no routes", not as a bug.
        $key = $path . '|' . $router::class;

        if (isset($this->loaded[$key])) {
            return RouteLoadResult::alreadyLoaded($path);
        }

        // Marked before the file runs, not after. A routes file that throws
        // half-way has still executed its top-level statements, and re-running it
        // on the next hook would re-apply them - for a file that registers routes
        // before failing, that means a duplicate-guard throw from inside an action
        // the next time round, which is a far more confusing symptom than the
        // original mistake.
        $this->loaded[$key] = true;

        try {
            /**
             * The facade is aimed at *this* router for the duration of the
             * `require`, which is what lets a routes file call `Route::get(...)`
             * without knowing - or being able to know - which router is loading
             * it. See {@see \Framework\Support\Facades\Route}.
             *
             * @var mixed $routes
             */
            $routes = RouteFacade::withRegistrar($router->registrar(), static fn () => require $path);
        } catch (Throwable $e) {
            return RouteLoadResult::failure($path, $e);
        }

        // Two shapes are accepted, and the reason is that the second one arrived
        // first: a routes file used to *return* a closure that received the
        // registrar, and every routes file written before the facade existed
        // still does.
        //
        //   // current
        //   Route::get('leads', [LeadController::class, 'index']);
        //   return Route::get('leads', [...]);        // the Route, not callable
        //
        //   // still supported
        //   return function (RouteRegistrar $router): void { ... };
        //
        // A file that ends in a facade call returns a `Route` object, which is not
        // callable, so it falls through as already-registered. Dispatching on
        // `is_callable` rather than on the return being null is what keeps both
        // working: `null` from a file whose last statement is a `group()` call and
        // `null` from an empty file are indistinguishable, and neither is an error.
        if (is_callable($routes)) {
            try {
                $routes($router->registrar());
            } catch (Throwable $e) {
                // Registration errors arrive here: an unsupported verb, a duplicate
                // path, or a handler-less route the file declared but never finished.
                // Reported with the file named, because the mistake is in the file.
                return RouteLoadResult::failure($path, $e);
            }
        }

        return RouteLoadResult::loaded($path, count($router->getRoutes()));
    }

    /**
     * Whether a file has already been loaded.
     *
     * Answers about *any* router, which is what this predicate always meant to
     * callers asking "has this file run yet" - the second half of the load key is
     * about de-duplication, not about identity.
     */
    public function hasLoaded(string $file): bool
    {
        $path = $this->absolute($file);

        foreach ($this->loaded as $key => $_) {
            if (str_starts_with($key, $path . '|')) {
                return true;
            }
        }

        return false;
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
        // A leading slash or a drive letter means the caller gave a full path -
        // `base_path()` would then prepend the base again and never find it.
        if (str_starts_with($file, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $file) === 1) {
            return $file;
        }

        return rtrim($this->basePath, '/\\') . DIRECTORY_SEPARATOR . ltrim($file, '/\\');
    }
}
