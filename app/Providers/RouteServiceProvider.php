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

        // The web lifecycle is the framework's, not this project's: the parent
        // publishes `routes/web.php` on `admin_menu`, `admin_init` and
        // `template_redirect` at priority 5, and dispatches an `admin-post.php`
        // submission from `admin_init`. It is a framework method because the
        // hook choice is the whole difficulty - see
        // {@see BaseRouteServiceProvider::bootWebRoutes()} for why the generic
        // `admin_post` never fires for a form and `admin_init` is what does.
        $this->bootWebRoutes();
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
