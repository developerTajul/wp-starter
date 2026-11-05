<?php
declare(strict_types=1);

namespace App\Providers;

use Framework\Routing\Adapters\RestApiRouterAdapter;
use Framework\Routing\Adapters\WordPressRouterAdapter;
use Framework\Routing\CaptureWebRequestFactory;
use Framework\Routing\Contracts\RouterInterface;
use Framework\Routing\RouteLoader;
use Framework\Routing\RouteResolver;
use Framework\Routing\RouteServiceProvider as BaseRouteServiceProvider;
use Framework\Routing\WebRequestFactory;
use Throwable;

/**
 * Wires both routers into their WordPress lifecycles.
 *
 * ## One file per domain, one adapter per file
 *
 * `routes/api.php` is loaded against {@see RestApiRouterAdapter} and published on
 * `rest_api_init`; `routes/web.php` is loaded against
 * {@see WordPressRouterAdapter} and published on `admin_menu`, `admin_post` and
 * `template_redirect`. The two never merge, which is the property that keeps a
 * web route from being registered as a REST endpoint and 404ing as a page with
 * nothing raised — see {@see \Framework\Routing\Adapters\AbstractRouterAdapter}
 * for why each adapter owns its own route table.
 *
 * ## Both adapters are singletons, and that is load-bearing
 *
 * A routes file is `require`d and handed a registrar; the registrar writes into
 * whichever adapter it was built from. If the adapter were re-resolved later —
 * at flush time, say — it would be a *different* object holding an empty table,
 * and every route would vanish with no error anywhere. `singleton()` is what
 * makes "the adapter the routes file wrote into" and "the adapter that publishes"
 * the same instance. The same reasoning applies to {@see RouteLoader}, whose
 * loaded-file record has to survive from the first hook to the last.
 *
 * ## The REST path is untouched
 *
 * {@see BaseRouteServiceProvider} already does the REST wiring and is in use by
 * this project and by anything built from it, so `parent::register()` and
 * `parent::boot()` run first and unchanged: the namespace, the `rest_api_init`
 * hook, the `routesLoaded` guard and the error handling all stay byte-for-byte.
 * The `Router` it binds stays bound too, so a project reaching for it by type
 * keeps working.
 *
 * **`routes/api.php` is therefore owned by the parent, and this class must not
 * load it a second time.** It did, briefly, and the symptom was precise: every
 * REST endpoint registered twice in one request. Two loaders reading one file
 * into two tables is not a merge — WordPress sees each registration
 * independently, so `ping` became two endpoints on the same path with WordPress
 * taking whichever it matched first. The only REST thing this class adds is
 * *binding* {@see RestApiRouterAdapter}, so it can be requested by type and listed
 * by `route:list`; the adapter stays empty until a project routes through it
 * directly, which is the migration `routes/api.php`'s own docblock describes.
 *
 * ## Why `flush()` is hooked where it is
 *
 * Each adapter publishes on the action its own domain fires. The hook is
 * registered here rather than inside the adapter, because when to publish is a
 * wiring decision and this is the wiring layer. The idempotent `flush()` guard in
 * the adapter is what makes hooking one flush onto several actions safe: the
 * first action to fire wins and the rest no-op.
 */
class RouteServiceProvider extends BaseRouteServiceProvider
{
    /**
     * Path to the web routes file, relative to the plugin root.
     *
     * Null disables web routing entirely — a plugin with only a REST surface sets
     * this and nothing else changes.
     */
    protected ?string $webRoutesFile = 'routes/web.php';

    public function register(): void
    {
        // The REST wiring first and unchanged: these are the bindings an existing
        // project resolves by type.
        parent::register();

        // Bound here rather than relying on the parent's, because the parent
        // resolves a resolver into the legacy Router and this one is shared by
        // both adapters. `singleton()` on an already-bound abstract replaces the
        // binding, which is the intent: one resolver for the whole routing layer.
        $this->container->singleton(RouteResolver::class);
        $this->container->singleton(WebRequestFactory::class, static fn (): WebRequestFactory => new CaptureWebRequestFactory());

        $this->container->singleton(
            RouteLoader::class,
            static fn (): RouteLoader => new RouteLoader(base_path())
        );

        $this->container->singleton(RestApiRouterAdapter::class, fn ($container) => new RestApiRouterAdapter(
            $container,
            $container->resolve(RouteResolver::class)
        ));

        $this->container->singleton(WordPressRouterAdapter::class, fn ($container) => new WordPressRouterAdapter(
            $container,
            $container->resolve(RouteResolver::class),
            $container->resolve(WebRequestFactory::class),
            // Not resolved from the container: Pipeline is bound *not shared*, so
            // resolving it here would capture one instance in the adapter's
            // constructor and reuse it across every dispatch — exactly the
            // shared-state problem the non-shared binding exists to prevent. Left
            // null, the adapter resolves a fresh pipeline per dispatch.
            null
        ));

        // String keys, because two adapters share `RouterInterface` and a
        // type-hint on it would have no single correct answer — a silent choice
        // between them is a route registered into the wrong domain. A caller that
        // wants one names it. The concrete-class bindings above are what most
        // callers should type-hint.
        $this->container->singleton(
            'router.api',
            fn ($container): RouterInterface => $container->resolve(RestApiRouterAdapter::class)
        );

        $this->container->singleton(
            'router.web',
            fn ($container): RouterInterface => $container->resolve(WordPressRouterAdapter::class)
        );
    }

    public function boot(): void
    {
        // The parent's REST hooks, unchanged.
        parent::boot();

        if (!function_exists('add_action')) {
            return;
        }

        $this->bootWebRouter();
    }

    /**
     * Hook the web router's publishing points.
     *
     * Three actions, because the three web surfaces are reached at different
     * moments and no single one of them fires on every kind of request:
     *
     *  - **`admin_menu`** — fires as wp-admin builds its menu. Where an admin
     *    screen is published, and the earliest of the three on an admin request.
     *  - **`admin_post`** — fires on a form submission, before WordPress has
     *    decided whether the caller is logged in. Where a form handler is
     *    dispatched.
     *  - **`template_redirect`** — fires on a front-end request once the query is
     *    resolved. Where a page route is matched.
     *
     * Any of the three may be the first to fire on a given request, and each one
     * loads the routes file and flushes, so the adapter is always populated before
     * it is asked to do anything. Loading is idempotent per file and `flush()` is
     * guarded, so the repeats cost nothing.
     */
    private function bootWebRouter(): void
    {
        foreach (['admin_menu', 'template_redirect'] as $hook) {
            // Priority 5: ahead of the default 10, so the routes exist before
            // most plugins — and before WordPress renders the screen or the
            // template — look for them.
            add_action($hook, function (): void {
                $router = $this->webRouter();

                if ($router === null) {
                    return;
                }

                $this->loadRoutesInto($router, $this->webRoutesFile);
                $router->flush();
            }, 5);
        }

        add_action('admin_post', function (): void {
            $router = $this->webRouter();

            if ($router === null) {
                return;
            }

            $this->loadRoutesInto($router, $this->webRoutesFile);
            $router->flush();

            // Dispatched from here rather than from a hook literally named
            // `admin_post_{action}`, and the reason is ordering: the action name
            // comes from the routes file, which is only read at this point, and
            // WordPress resolves and fires `admin_post_{action}` in a single pass
            // that is already under way. So the name is not used to find the
            // route — the request is. See
            // {@see WordPressRouterAdapter::dispatchCurrentAdminAction()}.
            $router->dispatchCurrentAdminAction();
        }, 5);
    }

    /**
     * Resolve the web router, or null when it cannot be built.
     *
     * A try/catch rather than a straight resolve because this runs inside a
     * WordPress action: a container failure here would escape as an unhandled
     * throwable and take out the whole request — every admin screen, not just the
     * plugin's routes. Returning null degrades to "no web routes this request",
     * which is a working site with missing pages rather than a fatal.
     */
    private function webRouter(): ?WordPressRouterAdapter
    {
        try {
            $router = $this->container->resolve(WordPressRouterAdapter::class);
        } catch (Throwable $e) {
            $this->logRouteFailure($e, $this->webRoutesFile);

            return null;
        }

        return $router instanceof WordPressRouterAdapter ? $router : null;
    }

    /**
     * Read a routes file into a router, reporting failures without throwing.
     *
     * Loading and the failure handling both live in {@see RouteLoader}, so this
     * provider and `route:list` — which has to load the same files outside any
     * WordPress action — cannot disagree about what loading a routes file means.
     * All this adds is where the report goes.
     */
    private function loadRoutesInto(RouterInterface $router, ?string $file): void
    {
        $result = $this->container->resolve(RouteLoader::class)->load($router, $file);

        if ($result->failed()) {
            $this->logRouteFailure($result->error, $result->path);
        }
    }

    /**
     * Report a routes-file problem.
     *
     * Through the error log rather than by rethrowing, because every call site is
     * inside a WordPress action: letting a broken routes file escape would turn it
     * into an opaque fatal on every request instead of one actionable log line.
     */
    private function logRouteFailure(?Throwable $error, ?string $file): void
    {
        $message = sprintf(
            'WP Mini Laravel: failed to load routes from [%s]: %s',
            (string) $file,
            $error?->getMessage() ?? 'unknown error'
        );

        // php_errorlog is PHP's own directive and is present on every real
        // install; error_log() is not in the disabled-functions list by default
        // but can be, and an undefined function here would re-throw out of the
        // very code that exists to contain the failure.
        if (function_exists('error_log')) {
            error_log($message);

            return;
        }

        if (function_exists('trigger_error')) {
            trigger_error($message, E_USER_WARNING);
        }
    }
}
