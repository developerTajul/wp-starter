<?php

declare(strict_types=1);

namespace App\Providers;

use Framework\Routing\Adapters\RestApiRouterAdapter;
use Framework\Routing\Adapters\WordPressRouterAdapter;
use Framework\Routing\CaptureWebRequestFactory;
use Framework\Routing\Contracts\RouterInterface;
use Framework\Routing\NamedRoutes;
use Framework\Routing\RouteLoader;
use Framework\Routing\RouteResolver;
use Framework\Routing\RouteServiceProvider as BaseRouteServiceProvider;
use Framework\Routing\UrlGenerator;
use Framework\Routing\WebRequestFactory;
use Framework\Support\Facades\Route as RouteFacade;
use Throwable;

/**
 * Wires the REST and web routers into their WordPress lifecycles.
 *
 * Both adapters are singletons: a routes file is `require`d into whichever
 * instance the `Route` facade resolves, and a later re-resolve would be a
 * different object with an empty table, silently losing every route.
 * `RouteLoader` is shared for the same reason, since its record of loaded
 * files must span the first and last publishing hook.
 *
 * `routes/api.php` is loaded by the parent class. This class must not load
 * it again: two loaders reading one file register every endpoint twice.
 */
class RouteServiceProvider extends BaseRouteServiceProvider
{
    /**
     * Path to the web routes file, relative to the plugin root. Null disables
     * web routing.
     */
    protected ?string $webRoutesFile = 'routes/web.php';

    public function register(): void
    {
        // REST wiring from the parent runs first and unchanged.
        parent::register();

        // Shared by both adapters, so it is a singleton: one resolver for the
        // whole routing layer.
        $this->container->singleton(RouteResolver::class);
        $this->container->singleton(WebRequestFactory::class, static fn (): WebRequestFactory => new CaptureWebRequestFactory());

        $this->container->singleton(
            RouteLoader::class,
            static fn (): RouteLoader => new RouteLoader(base_path())
        );

        $this->container->singleton(RestApiRouterAdapter::class, fn ($container) => new RestApiRouterAdapter(
            $container,
            $container->resolve(RouteResolver::class),
            $container->resolve(NamedRoutes::class)
        ));

        $this->container->singleton(WordPressRouterAdapter::class, fn ($container) => new WordPressRouterAdapter(
            $container,
            $container->resolve(RouteResolver::class),
            $container->resolve(WebRequestFactory::class),
            // Pipeline is bound non-shared, so resolving it here would capture
            // one instance for every dispatch. Null makes the adapter resolve
            // a fresh pipeline per dispatch instead.
            null,
            null,
            $container->resolve(NamedRoutes::class)
        ));

        // One registry shared by both adapters, so `route()` can resolve names
        // from either domain. Two registries would each only find their own.
        $this->container->singleton(NamedRoutes::class);

        // The `route()` helper's generator. It holds the registry rather than a
        // copy of the routes, so routes registered later are still found.
        $this->container->singleton(
            'url',
            fn ($container): UrlGenerator => new UrlGenerator(
                $container->resolve(NamedRoutes::class),
                (string) config('app.rest_namespace', 'wp-mini-laravel/v1')
            )
        );

        // Bound to null so `Facade::resolveFacadeInstance()` reports "bound to
        // nothing" rather than "unbound". The facade target is set per routes
        // file by RouteLoader; callers wanting a router use `router.web` or
        // `router.api`.
        $this->container->instance(RouteFacade::ACCESSOR, null);

        // String keys, because both adapters satisfy `RouterInterface` and a
        // type-hint on it would silently pick one. Callers naming a domain use
        // these keys.
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
     * Each surface is reached at a different moment: `admin_menu` for admin
     * screens, `admin_post` for form submissions, `template_redirect` for
     * front-end page routes. Any one may fire first on a request, so each
     * loads the routes file and flushes before dispatching.
     */
    private function bootWebRouter(): void
    {
        foreach (['admin_menu', 'template_redirect'] as $hook) {
            // Priority 5, so routes exist before most other plugins look for
            // them.
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

            // Dispatched here rather than from `admin_post_{action}`: the
            // action name only becomes known by reading the routes file at
            // this point, and WordPress resolves and fires that hook in an
            // already-running pass. The request finds the route, not the name.
            $router->dispatchCurrentAdminAction();
        }, 5);
    }

    /**
     * Resolve the web router, or null when it cannot be built.
     *
     * A container failure here runs inside a WordPress action, so throwing
     * would take down every admin screen, not just this plugin's routes.
     * Returning null degrades to "no web routes this request".
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
     * Load a routes file into a router.
     *
     * Loading and failure handling live in {@see RouteLoader}, so this
     * provider and `route:list` agree on what loading means. This only adds
     * where the failure is reported.
     */
    private function loadRoutesInto(RouterInterface $router, ?string $file): void
    {
        $result = $this->container->resolve(RouteLoader::class)->load($router, $file);

        if ($result->failed()) {
            $this->logRouteFailure($result->error, $result->path);
        }
    }

    /**
     * Report a routes-file problem to the error log.
     *
     * Every caller runs inside a WordPress action, so a throw here would turn
     * a broken routes file into a fatal on every request.
     */
    private function logRouteFailure(?Throwable $error, ?string $file): void
    {
        $message = sprintf(
            'WP Mini Laravel: failed to load routes from [%s]: %s',
            (string) $file,
            $error?->getMessage() ?? 'unknown error'
        );

        // error_log() may be disabled, and calling it here would re-throw out
        // of the code that exists to contain the failure.
        if (function_exists('error_log')) {
            error_log($message);

            return;
        }

        if (function_exists('trigger_error')) {
            trigger_error($message, E_USER_WARNING);
        }
    }
}
