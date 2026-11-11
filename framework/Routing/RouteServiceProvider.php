<?php
declare(strict_types=1);

namespace Framework\Routing;

use DevLinkStudios\WPServiceContainer\ServiceProvider;
use Framework\Routing\Adapters\WordPressRouterAdapter;
use Throwable;

/**
 * Wires the router into the REST API and web lifecycles.
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
 *
 * The web lifecycle is native here, not re-implemented per project
 *
 * {@see bootWebRoutes()} publishes `routes/web.php` on the three hooks the web
 * surfaces are reached through. It is a method on this class because getting
 * those hooks wrong is a silent failure that costs a project an afternoon: see
 * that method for the `admin-post.php` ordering rule, which is the reason it
 * exists rather than being left to each project's provider.
 */
class RouteServiceProvider extends ServiceProvider
{
    /**
     * Path to the routes file, relative to the plugin root. Null disables it.
     */
    protected ?string $routesFile = 'routes/api.php';

    /**
     * Path to the web routes file, relative to the plugin root.
     *
     * Null disables web routing, which is what a REST-only plugin wants. Leave it
     * null and {@see bootWebRoutes()} registers no hooks at all.
     */
    protected ?string $webRoutesFile = null;

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
     * Publish `routes/web.php` onto the three hooks the web surfaces use.
     *
     * Call this from a subclass's `boot()`, after `parent::boot()`. It registers
     * nothing when {@see $webRoutesFile} is null, so a provider that never calls
     * it - or a REST-only project - is unaffected either way.
     *
     * Module bindings for the adapters are the project's business, not this
     * class's: it resolves {@see WordPressRouterAdapter} from the container and
     * returns quietly when it is not bound.
     *
     * **Every hook loads and flushes before it dispatches**, and the three are
     * independent because any one may fire first on a request.
     *
     * **Why `admin_init` and not `admin_post` for form submissions.**
     *
     * WordPress's `wp-admin/admin-post.php` resolves a submission like this
     * (`wp-admin/admin-post.php`, core):
     *
     *     $action = ! empty( $_REQUEST['action'] ) ? sanitize_text_field( $_REQUEST['action'] ) : '';
     *
     *     if ( empty( $action ) ) {
     *         do_action( 'admin_post' );                       // generic: action ABSENT
     *     } else {
     *         if ( ! has_action( "admin_post_{$action}" ) ) {
     *             wp_die( '', 400 );                           // no handler registered
     *         }
     *         do_action( "admin_post_{$action}" );             // specific: action PRESENT
     *     }
     *
     * A form always sends `action`, so a listener on the generic `admin_post`
     * **never runs for a form submission**. It runs only for the action-less case
     * WordPress itself uses. Hooking the dispatch there looked reasonable and was
     * unreachable in practice.
     *
     * That left the form handlers unpublished at the moment WordPress asks
     * whether they exist. `admin_post_{action}` is derived from the routes file,
     * and the two hooks that would normally have read it - `admin_menu` and
     * `template_redirect` - do not fire on `admin-post.php` at all. So
     * `has_action()` was false, `wp_die( '', 400 )` fired, and the browser got a
     * blank page with nothing in the debug log: no exception, no route, no trace.
     *
     * `admin_init` is the hook that fixes it. It fires on **every** admin request -
     * `admin-post.php` includes it near the top, before `$action` is resolved - so
     * by the time WordPress asks `has_action( "admin_post_{$action}" )`, the
     * routes file has been read, `flush()` has published the action names, and the
     * handler is registered.
     *
     * The dispatch itself stays here rather than moving to a listener on
     * `admin_post_{action}`, because that name is only known after the routes file
     * is read - and a listener has to be registered before WordPress decides which
     * hook to fire. The request finds the route; the action name is never used as
     * a hook to look up.
     *
     * `admin_menu` and `template_redirect` remain, for the screens and front-end
     * pages that genuinely arrive through them. Loading is idempotent per file and
     * {@see AbstractRouterAdapter::flush()} is guarded, so a request that fires
     * several of these pays for one load.
     */
    protected function bootWebRoutes(): void
    {
        if ($this->webRoutesFile === null || !function_exists('add_action')) {
            return;
        }

        // Priority 5, so routes exist before most other plugins look for them.
        foreach (['admin_menu', 'template_redirect'] as $hook) {
            add_action($hook, function (): void {
                $this->publishWebRoutes();
            }, 5);
        }

        add_action('admin_init', function (): void {
            $this->publishWebRoutes();
            $this->dispatchCurrentAdminAction();
        }, 5);
    }

    /**
     * Load and flush the web routes file, once per process.
     *
     * The web counterpart of {@see loadRoutes()}. A missing file is ordinary - a
     * project with no web surface boots quietly - while a file that throws is
     * reported and contained, for the same reason the REST side is: this runs
     * inside a WordPress action, so letting it escape would turn a routes-file
     * typo into a fatal on every admin request.
     */
    private function publishWebRoutes(): void
    {
        $router = $this->webRouter();

        if ($router === null) {
            return;
        }

        $result = $this->container->resolve(RouteLoader::class)->load($router, $this->webRoutesFile);

        if ($result->failed()) {
            $this->logRouteFailure($result->error ?? new \RuntimeException($result->describe()));

            return;
        }

        $router->flush();
    }

    /**
     * Hand the current `admin-post.php` submission to the routes table.
     *
     * A no-op unless a route's derived action name matches the one WordPress is
     * dispatching. That matters because `admin_init` also fires on ordinary admin
     * screens, where there is no action - and because WordPress fires
     * `admin_post` for its own internal handlers too, whose actions are none of
     * this plugin's business.
     */
    private function dispatchCurrentAdminAction(): void
    {
        $this->webRouter()?->dispatchCurrentAdminAction();
    }

    /**
     * Resolve the web router, or null when it is unavailable.
     *
     * Null covers two cases that need no distinction here: a project that
     * registers no web adapter, and a container that cannot build one. Both mean
     * "no web routes this request", which is the same quiet outcome.
     */
    private function webRouter(): ?WordPressRouterAdapter
    {
        if (!class_exists(WordPressRouterAdapter::class)) {
            return null;
        }

        try {
            $router = $this->container->resolve(WordPressRouterAdapter::class);
        } catch (Throwable $e) {
            $this->logRouteFailure($e);

            return null;
        }

        return $router instanceof WordPressRouterAdapter ? $router : null;
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
