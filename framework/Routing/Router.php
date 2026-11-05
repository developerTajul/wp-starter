<?php
declare(strict_types=1);

namespace Framework\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Http\Request;
use Framework\Routing\Contracts\Middleware;
use Framework\Routing\Contracts\RouterInterface;
use Framework\Routing\Exceptions\RouteRegistrationException;
use Framework\Routing\Exceptions\RouteResolutionException;
use Throwable;
use WP_Error;
use WP_REST_Request;

/**
 * Collects routes and publishes them to the WordPress REST API.
 *
 * The split matters: collecting happens at plugin load (a routes file is just a
 * PHP file returning a closure) while publishing happens on `rest_api_init`,
 * which fires well after every plugin has loaded. Keeping those apart is what
 * lets one plugin's routes coexist with another's.
 *
 * ## This is the REST adapter, under its original name
 *
 * It predates {@see RouterInterface} and the two web adapters, and it keeps its
 * own spelling — `add()` rather than `addRoute()`, `registerRoutes()` rather than
 * `flush()` — because both are load-bearing for this repository's own provider
 * and tests. The interface is implemented *additively*: the new names are thin
 * aliases over the old ones, so a caller written against either spelling drives
 * exactly the same route table. Renaming the originals would be a breaking change
 * for every project already using them, for no gain.
 *
 * For new code, prefer {@see Adapters\RestApiRouterAdapter}, which is this
 * behaviour behind the uniform interface.
 */
class Router implements RouterInterface
{
    /**
     * Registered routes, keyed by "METHOD /path" for duplicate detection.
     *
     * @var array<string, Route>
     */
    private array $routes = [];

    /**
     * Namespaces already handed to WordPress, so a second `rest_api_init`
     * firing does not try to register the same routes twice.
     *
     * @var array<string, true>
     */
    private array $registeredNamespaces = [];

    public function __construct(
        private readonly Container $container,
        private readonly RouteResolver $resolver
    ) {
    }

    /**
     * The fluent surface for a routes file.
     */
    public function registrar(): RouteRegistrar
    {
        return new RouteRegistrar($this);
    }

    /**
     * @throws RouteRegistrationException
     */
    public function add(Route $route): void
    {
        // Deliberately not validated for a handler here. A route is stored as
        // soon as it is created so the caller can keep configuring it fluently,
        // which means it is legitimately handler-less at this moment. The check
        // happens in registerRoutes(), by which point every route has been
        // handed back and no further configuration is possible.
        foreach ($route->getMethods() as $method) {
            $key = $method . ' ' . $route->getPath();

            if (isset($this->routes[$key])) {
                throw RouteRegistrationException::duplicate($method, $route->getDisplayPath());
            }

            $this->routes[$key] = $route;
        }
    }

    /**
     * @return list<Route>
     */
    public function getRoutes(): array
    {
        return array_values(array_unique($this->routes, SORT_REGULAR));
    }

    /**
     * The domain this router publishes into. Always "api" here.
     *
     * @see \Framework\Routing\RouteDomain
     */
    public function domain(): string
    {
        return RouteDomain::Api->value;
    }

    /**
     * {@see add()}, under the name {@see RouterInterface} declares.
     *
     * The two are the same operation; `add()` is kept because the existing
     * provider and tests call it by that name.
     *
     * @throws RouteRegistrationException
     */
    public function addRoute(Route $route): Route
    {
        $this->add($route);

        // Handed back so the fluent shorthand composes — see the contract. `add()`
        // stores this exact instance, so configuring what comes back configures
        // what will be published.
        return $route;
    }

    /**
     * Register one GET route on this router.
     *
     * Convenience for a caller holding the router rather than its registrar —
     * a provider wiring a route in code, or a test. The registrar is the
     * preferred surface inside a routes file, because it carries the group
     * prefix and middleware.
     *
     * @throws RouteRegistrationException
     */
    public function get(string $path, ?string $controller = null, ?string $action = null): Route
    {
        return $this->registrar()->get($path, $controller, $action);
    }

    /**
     * @throws RouteRegistrationException
     */
    public function post(string $path, ?string $controller = null, ?string $action = null): Route
    {
        return $this->registrar()->post($path, $controller, $action);
    }

    /**
     * @throws RouteRegistrationException
     */
    public function put(string $path, ?string $controller = null, ?string $action = null): Route
    {
        return $this->registrar()->put($path, $controller, $action);
    }

    /**
     * @throws RouteRegistrationException
     */
    public function delete(string $path, ?string $controller = null, ?string $action = null): Route
    {
        return $this->registrar()->delete($path, $controller, $action);
    }

    /**
     * Empty the route table, leaving the router reusable.
     *
     * Deliberately **not** the interface's `flush()`. In this class `flush()`
     * would have to mean "publish", to match {@see registerRoutes()} and the
     * adapters — but "flush" reads to most callers as "discard", and a method
     * whose name suggests clearing and whose effect is publishing is a trap. So
     * the destructive operation is named for what it does and the publishing one
     * keeps the name it has always had; the interface's `flush()` is bound to
     * `registerRoutes()` instead, which is why it takes the namespace.
     */
    public function clearRoutes(): void
    {
        $this->routes = [];
        $this->registeredNamespaces = [];
    }

    /**
     * Publish, as {@see RouterInterface} declares it.
     *
     * The namespace is not part of the interface — it is this mechanism's own
     * concern — so `flush()` is a thin wrapper that resolves it from config and
     * defers to {@see registerRoutes()}. A project that wants a different
     * namespace per call still calls `registerRoutes()` directly, exactly as the
     * framework's own provider does.
     *
     * @throws RouteRegistrationException
     */
    public function flush(): void
    {
        $this->registerRoutes((string) config('app.rest_namespace', 'wp-mini-laravel/v1'));
    }

    /**
     * Hand every route to WordPress. Hooked to `rest_api_init`.
     *
     * Idempotent: WordPress can fire `rest_api_init` more than once in a
     * process (a reset REST server, a long-running worker), and a second
     * registration pass would otherwise collide with the first and throw out of
     * the action, fataling every REST request.
     *
     * @throws RouteRegistrationException
     */
    public function registerRoutes(string $namespace): void
    {
        if (!function_exists('register_rest_route')) {
            return;
        }

        if (isset($this->registeredNamespaces[$namespace])) {
            return;
        }

        $ordered = RouteCompiler::sortBySpecificity($this->routes);

        // Validated in full before anything is handed to WordPress. Registration
        // is the last moment a route can still be fixed, so a handler that never
        // arrived is reported here — naming the path, which is what a developer
        // needs to find the mistake.
        //
        // Validating up front rather than inside the loop is what keeps a single
        // bad route from leaving the set half-registered: WordPress has already
        // accepted the earlier routes by then, the exception escapes
        // `rest_api_init`, and every later route is lost. Worse, the namespace is
        // not yet flagged, so a retry would re-register the ones that did land and
        // collide with them.
        foreach ($ordered as $route) {
            if ($route->getController() === null && $route->getHandler() === null) {
                throw RouteRegistrationException::missingHandler($route->getDisplayPath());
            }
        }

        foreach ($ordered as $route) {
            register_rest_route($namespace, self::toWordPressRoute($route->getPath()), [
                'methods' => $route->getMethods(),
                'callback' => fn (WP_REST_Request $request) => $this->dispatch($route, $request),
                'permission_callback' => $this->buildPermissionCallback($route),
            ]);
        }

        // Flagged only once every route is registered, so a throw above leaves the
        // namespace unregistered and a retry can still succeed.
        $this->registeredNamespaces[$namespace] = true;
    }

    /**
     * Resolve the route's handler and invoke it.
     *
     * @return mixed
     */
    private function dispatch(Route $route, WP_REST_Request $request): mixed
    {
        $this->pinRouteParameters($route, $request);

        // Everything below runs with Framework\Http\Request resolving to the
        // request WordPress just handed us, rather than to the instance captured
        // from the superglobals at plugin load. Bound *before* the controller is
        // resolved, because a constructor may type-hint it too — resolving first
        // and binding afterwards would leave the constructor holding the wrong one.
        return $this->withHttpRequest($request, function () use ($route, $request): mixed {
            return $this->dispatchTo($route, $request);
        });
    }

    /**
     * Run a callback with `Framework\Http\Request` bound to this REST request.
     *
     * ## Why the binding has to be scoped
     *
     * `Container::instance()` is permanent — it sets a shared instance and does
     * not offer an unset — so binding and walking away would leave the REST
     * request in the container for the rest of the process. That is harmless
     * under a classic one-request-per-process PHP setup and wrong everywhere
     * else: under a persistent worker, or when something dispatches a second
     * request, the next controller to type-hint `Request` would silently receive
     * the *previous* request's body. Restoring the previous binding in a
     * `finally` keeps the binding confined to the dispatch, which is what the
     * "scoped to the current request lifecycle" rule asks for.
     *
     * ## Why a framework Request at all
     *
     * A controller type-hinting the native `WP_REST_Request` keeps working
     * untouched — {@see RouteResolver} still hands that one over for a parameter
     * typed as such. This binding is only what makes the *framework's* Request
     * mean the REST request instead of a stale superglobal capture, and it
     * carries two things a capture cannot: the URL-matched parameters, and a body
     * decoded with WordPress's own parameter precedence already applied.
     *
     * @param callable(): mixed $callback
     *
     * @return mixed
     */
    private function withHttpRequest(WP_REST_Request $request, callable $callback): mixed
    {
        // Nothing to bind against without the framework's HTTP layer loaded — a
        // consuming project may have dropped HttpServiceProvider from its
        // providers, in which case routing must keep working exactly as before.
        if (!class_exists(Request::class)) {
            return $callback();
        }

        $hadPrevious = $this->container->bound(Request::class);
        $previous = $hadPrevious ? $this->container->resolve(Request::class) : null;

        $this->container->instance(Request::class, Request::fromRestRequest($request));

        try {
            return $callback();
        } finally {
            // `instance()` only ever sets, so restoring means either putting the
            // old instance back or forgetting the binding entirely when there was
            // none — leaving it bound would be a permanent leak of this request.
            if ($hadPrevious) {
                $this->container->instance(Request::class, $previous);
            } else {
                $this->container->forget(Request::class);
            }
        }
    }

    private function dispatchTo(Route $route, WP_REST_Request $request): mixed
    {
        $controller = $route->getController();

        if ($controller !== null) {
            $action = $route->getAction() ?? 'index';

            // Built before the try: a controller that cannot be constructed is a
            // deployment error, so it gets the 500 below rather than the 400 that
            // a malformed request earns.
            try {
                // Resolved per request rather than cached: a controller resolved
                // once and reused would carry request-scoped state into the next
                // request under a persistent-object-cache or long-running setup.
                $instance = $this->container->resolve($controller);
            } catch (Throwable $e) {
                // Catches everything the container throws — EntryNotFoundException
                // for a class that does not exist, BindingResolutionException when
                // a constructor dependency cannot be built — plus anything the
                // controller's own constructor raises. None of these is a caller
                // error, and none was caught here before: a renamed or mistyped
                // controller escaped the REST callback as an unhandled throwable,
                // which WordPress renders as an opaque 500 naming nothing.
                return new WP_Error(
                    'wp_mini_laravel_unresolvable_controller',
                    sprintf(
                        'Route [%s] could not build its controller [%s]: %s',
                        $route->getDisplayPath(),
                        $controller,
                        $e->getMessage()
                    ),
                    ['status' => 500]
                );
            }

            try {
                return $this->resolver->call($instance, $action, $request);
            } catch (RouteRegistrationException | RouteResolutionException $e) {
                // A bad request must not surface as an uncaught throwable out of
                // the REST callback, which WordPress renders as an opaque 500.
                // These two are caller errors, so they get a 400 naming the route.
                return new WP_Error('wp_mini_laravel_invalid_request', $e->getMessage(), ['status' => 400]);
            }
        }

        $handler = $route->getHandler();

        if ($handler === null) {
            return new WP_Error(
                'wp_mini_laravel_no_handler',
                __('This route has no handler.', 'wp-mini-laravel'),
                ['status' => 500]
            );
        }

        return ($handler)($request);
    }

    /**
     * Re-assert the URL-matched placeholders on the request.
     *
     * WordPress resolves `get_param()` by source priority — JSON, body, query,
     * then URL last — so a POST body carrying `{"id": 999}` outranks the `123`
     * the URL matched against `posts/{id}`. A controller reading
     * `$request->get_param('id')` would then act on a value the client chose
     * rather than the one the route matched, and an ownership check written
     * against the URL would be bypassable.
     *
     * Writing the URL params back through set_param() puts them at the front of
     * that priority order, so the matched value is what the action sees however
     * it reads the request.
     */
    private function pinRouteParameters(Route $route, WP_REST_Request $request): void
    {
        $urlParams = $request->get_url_params();

        foreach ($route->getParameterNames() as $name) {
            if (array_key_exists($name, $urlParams)) {
                $request->set_param($name, $urlParams[$name]);
            }
        }
    }

    /**
     * Build the permission callback WordPress will call.
     *
     * Every middleware for the route runs in registration order and the first
     * to return a WP_Error wins. An explicit permissionCallback() is consulted
     * before middleware, since a route that sets one has opted into WordPress's
     * own mechanism deliberately.
     *
     * @return callable(WP_REST_Request): (WP_Error|bool|null)
     */
    private function buildPermissionCallback(Route $route): callable
    {
        return function (WP_REST_Request $request) use ($route): WP_Error|bool|null {
            $explicit = $route->getPermissionCallback();

            if ($explicit !== null) {
                $result = $this->runPermissionCallback($route, $explicit, $request);

                // `false` and `null` both mean "deny" to WordPress, which is
                // the whole reason `return $allowed ? true : null;` is such a
                // common idiom in permission callbacks. Treating only `false`
                // as a denial would let that idiom fall through to the
                // middleware loop and then be reported as allowed — turning a
                // deliberately protected route into a public one.
                //
                // A WP_Error is returned as-is rather than converted, so a
                // callback's own status and message survive.
                if ($result instanceof WP_Error || $result === false || $result === null) {
                    return $result;
                }
            }

            foreach ($route->getMiddleware() as $middlewareClass) {
                $result = $this->runMiddleware($middlewareClass, $request);

                if ($result !== null) {
                    return $result;
                }
            }

            return true;
        };
    }

    /**
     * Invoke the route's explicit permission callback, failing closed.
     *
     * The callback is named by string, in one of the three forms the container's
     * `call()` understands:
     *
     *  - a plain function name, `my_permission_check` — the common case;
     *  - a static method, `MyChecks::canEdit`;
     *  - an instance method on a resolvable class, `MyChecks@canEdit`, which the
     *    container constructs so its own dependencies are injected.
     *
     * A bare method name such as `canEdit` is **not** one of them: nothing ties
     * it to the route's controller, so the container reflects it as a plain
     * function, does not find one, and throws. A name that resolves to nothing
     * used to escape as a ReflectionException, and WordPress calls this function
     * with no error handling of its own, so it surfaced as an unhandled fatal on
     * a route any anonymous client can reach.
     *
     * Denial is the only safe outcome: a check that never ran has authorised
     * nothing. The status is `rest_authorization_required_code()` so an anonymous
     * caller still gets 401 and a logged-in one 403, exactly as a callback that
     * returned false would produce. Because that is indistinguishable from a real
     * authorization failure, the message names the callback and carries the
     * underlying reason — an unresolvable or misspelled callback is a wiring bug,
     * and the operator should be able to tell it apart from a locked-down route.
     */
    private function runPermissionCallback(Route $route, string $callback, WP_REST_Request $request): mixed
    {
        try {
            return $this->resolver->callPermission($callback, $request);
        } catch (Throwable $e) {
            return new WP_Error(
                'wp_mini_laravel_permission_callback_failed',
                sprintf(
                    'Route [%s] could not run its permission callback [%s]: %s',
                    $route->getDisplayPath(),
                    $callback,
                    $e->getMessage()
                ),
                ['status' => rest_authorization_required_code()]
            );
        }
    }

    /**
     * Run one middleware, converting any failure into a denial.
     *
     * WordPress calls the permission callback with no error handling of its own
     * (`class-wp-rest-server.php`), so a middleware that throws — a database
     * blip, a null dereference, a third-party call — would surface as an
     * unhandled fatal rather than a response, and any unauthenticated client
     * could trigger it on demand.
     *
     * Failing closed is the only safe direction: an authorization check that
     * could not run has not authorised anything.
     */
    private function runMiddleware(string $middlewareClass, WP_REST_Request $request): ?WP_Error
    {
        try {
            /** @var Middleware $middleware */
            $middleware = $this->container->resolve($middlewareClass);
            $result = $middleware->handle($request);
        } catch (\Throwable $e) {
            return new WP_Error(
                'wp_mini_laravel_middleware_failed',
                __('The request could not be authorized.', 'wp-mini-laravel'),
                ['status' => rest_authorization_required_code()]
            );
        }

        // A middleware may only answer with a WP_Error (deny) or null (no
        // opinion). Anything else — a bool, a string, an array — is a contract
        // violation, and a truthy value would otherwise be indistinguishable
        // from the final "allowed" return below.
        if ($result !== null && !$result instanceof WP_Error) {
            return new WP_Error(
                'wp_mini_laravel_middleware_contract',
                sprintf(
                    'Middleware [%s] must return a WP_Error to deny or null to allow; [%s] returned.',
                    $middlewareClass,
                    get_debug_type($result)
                ),
                ['status' => 500]
            );
        }

        return $result;
    }

    /**
     * Translate a route path into the regex WordPress matches request paths
     * against.
     *
     * Delegates to {@see RouteCompiler}, which owns the parser. This method is
     * kept as the historical entry point — the tests and the REST adapter both
     * call it — and it is the REST spelling of `RouteCompiler::toPattern()`.
     */
    public static function toWordPressRoute(string $path): string
    {
        return RouteCompiler::toPattern($path);
    }
}
