<?php
declare(strict_types=1);

namespace Framework\Routing;

use DevLinkStudios\WPServiceContainer\ServiceProvider;
use Throwable;

/**
 * Wires the router into the REST API lifecycle.
 *
 * The routes file is read through RouteLoader, not `require`d
 *
 * {@see RouteLoader} aims the `Route` facade at this provider's router for the
 * duration of the load, which is what lets `routes/api.php` call
 * `Route::get(...)` - and the same file shape as `routes/web.php`, despite the
 * two writing into entirely different tables.
 *
 * This used to `require` the file directly and demand a callable back. That
 * worked only while every routes file returned a closure; the moment one was
 * written against the facade, the direct `require` threw "the facade was called
 * outside a routes file" and the whole REST table registered **nothing**, a
 * silent empty API rather than a visible failure, which is why the load now goes
 * through the same door `route:list` uses.
 *
 * It follows the register/boot split the container depends on
 *
 * register() declares bindings only, boot() touches WordPress hooks. Registering
 * routes themselves is deferred to the `rest_api_init` action rather than done in
 * boot(), because `rest_api_init` fires per REST request and a route registered
 * while merely loading a page would be collected for a request that never
 * dispatches.
 */
class RouteServiceProvider extends ServiceProvider
{
    /**
     * Path to the routes file, relative to the plugin root. Null disables it.
     */
    protected ?string $routesFile = 'routes/api.php';

    /**
     * Guards against loading the routes file on a second `rest_api_init`.
     */
    private bool $routesLoaded = false;

    /**
     * The REST namespace every route in this plugin is published under.
     */
    protected function getNamespace(): string
    {
        return (string) config('app.rest_namespace', 'wp-mini-laravel/v1');
    }

    public function register(): void
    {
        // The resolver is stateless, and the router must be a single shared
        // instance - every routes file and every controller registers into the
        // same collection, so a per-resolve router would silently drop routes.
        $this->container->singleton(RouteResolver::class);

        // One registry for every router in the process, so `route()` can find a
        // name whichever domain declared it. Bound before the router below, which
        // resolves it.
        $this->container->singleton(NamedRoutes::class);

        $this->container->singleton(Router::class, fn ($container) => new Router(
            $container,
            $container->resolve(RouteResolver::class),
            $container->resolve(NamedRoutes::class)
        ));

        // The loader reads routes files and owns which router the `Route` facade
        // writes into while one is running. Bound here as well as in the app's
        // provider because this class is the one that loads a file, and it must not
        // depend on a subclass having bound the loader first. `singleton()`
        // replaces, so if the app provider also binds it there is still exactly one
        // instance - and one loaded-file record, which is the point.
        $this->container->singleton(
            RouteLoader::class,
            static fn (): RouteLoader => new RouteLoader(base_path())
        );
    }

    public function boot(): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        add_action('rest_api_init', function (): void {
            /** @var Router $router */
            $router = $this->container->resolve(Router::class);

            // `rest_api_init` can fire more than once in a process - a reset
            // REST server, a long-running worker. Loading the routes file again
            // would `require` it a second time and re-add every route to the
            // shared router, where the duplicate guard would throw out of the
            // action. One load per process is what the routes file expects.
            if ($this->routesLoaded) {
                return;
            }

            $this->routesLoaded = true;

            $this->loadRoutes($router);
            $router->registerRoutes($this->getNamespace());
        });
    }

    /**
     * Load the routes file, if one is configured and present.
     *
     * Through {@see RouteLoader} rather than a bare `require`, which is what makes
     * a file written as `Route::get(...)` work: the facade has no router of its own
     * and resolves its target from loader state, so `require`-ing the file here
     * would throw "the facade was called outside a routes file" and register
     * nothing at all. The loader is also the single entry point `route:list` uses,
     * so the two cannot disagree about what loading a routes file means.
     *
     * Both file shapes are accepted, and the loader is what accepts them:
     *
     *     Route::get('ping', fn () => ['pong' => true]);   // facade
     *
     *     return function (RouteRegistrar $router): void { ... };   // legacy
     *
     * A missing file is normal - a plugin with no REST surface still boots the
     * provider - but a file that throws is not: it means the route table is
     * incomplete, and a half-registered REST API is worse than a loud failure.
     */
    protected function loadRoutes(Router $router): void
    {
        if ($this->routesFile === null) {
            return;
        }

        $result = $this->container->resolve(RouteLoader::class)->load($router, $this->routesFile);

        if ($result->failed()) {
            // Reported through the WordPress error log rather than rethrown:
            // `rest_api_init` runs inside WordPress's REST dispatch, so letting
            // this escape would turn a broken routes file into an opaque 500 on
            // every endpoint instead of one actionable log line.
            $this->logRouteFailure($result->error ?? new \RuntimeException($result->describe()));
        }
    }

    private function logRouteFailure(Throwable $e): void
    {
        // php_errorlog is PHP's own directive and is present on every real
        // install; error_log() is not in the disabled-functions list by
        // default but can be, and an undefined function here would re-throw
        // out of the very catch block that exists to contain the failure.
        if (function_exists('error_log')) {
            error_log(sprintf(
                'WP Mini Laravel: failed to load routes from [%s]: %s',
                (string) $this->routesFile,
                $e->getMessage()
            ));

            return;
        }

        if (function_exists('trigger_error')) {
            trigger_error(sprintf(
                'WP Mini Laravel: failed to load routes from [%s]: %s',
                (string) $this->routesFile,
                $e->getMessage()
            ), E_USER_WARNING);
        }
    }
}
