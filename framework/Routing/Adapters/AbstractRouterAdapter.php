<?php
declare(strict_types=1);

namespace Framework\Routing\Adapters;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Http\Request;
use Framework\Routing\Contracts\Middleware;
use Framework\Routing\Contracts\RouterInterface;
use Framework\Routing\Exceptions\RouteRegistrationException;
use Framework\Routing\Exceptions\RouteResolutionException;
use Framework\Routing\NamedRoutes;
use Framework\Routing\Route;
use Framework\Routing\RouteRegistrar;
use Framework\Routing\RouteResolver;
use Throwable;

/**
 * Route collection, shared by every adapter.
 *
 * The split this class draws is: **what happens to a route before flush() is the
 * same everywhere; what happens during it is not.** Collection, duplicate
 * detection, the fluent verbs, controller resolution and the scoped `Request`
 * binding are identical whether the route ends up on `admin_menu` or
 * `register_rest_route()` — only the publication step differs. Two adapters each
 * reimplementing the collection half would be two places for the duplicate guard
 * to drift.
 *
 * Subclasses implement {@see publish()} and {@see domain()}, and get everything
 * else.
 *
 * ## Why the routes live here rather than in a shared collection object
 *
 * A route table shared between adapters would make "which router owns this route"
 * unanswerable, and the answer is what decides the hook, the URI shape and the
 * response type. Each adapter owning its own table is what keeps `routes/web.php`
 * and `routes/api.php` from silently merging — a web route registered into the
 * API adapter would be published as a REST endpoint and 404 as a page, with
 * nothing raised at registration.
 *
 * ## Controllers are not built until dispatch
 *
 * Resolution is deferred to the moment a route is dispatched rather than done at
 * flush() time. A controller resolved during flush would be constructed on every
 * request that merely *loads* the hooks — including the admin pages that will
 * never touch the route — and would carry request-scoped state into the next
 * request under a persistent worker.
 */
abstract class AbstractRouterAdapter implements RouterInterface
{
    /**
     * Collected routes, keyed by "METHOD /path" so a duplicate is caught here
     * rather than by WordPress accepting an ambiguous registration.
     *
     * @var array<string, Route>
     */
    protected array $routes = [];

    /**
     * Whether flush() has already published.
     *
     * Per-instance and checked before touching WordPress, because several of the
     * hooks these adapters bind — `admin_menu`, `rest_api_init` — can fire more
     * than once in a process. A second pass would re-register every route, and
     * `register_rest_route()` would collide with the first while `add_menu_page()`
     * would silently add a duplicate screen.
     */
    protected bool $flushed = false;

    public function __construct(
        protected readonly Container $container,
        protected readonly RouteResolver $resolver,
        /**
         * Where named routes are recorded for URL generation.
         *
         * Optional so the dozens of existing constructions — tests, probes, a
         * project building an adapter by hand — keep working. When it is omitted a
         * private registry is used, which means that adapter's names are
         * unreachable from `route()`: correct for a caller that never generates a
         * URL, and the reason the service providers always pass the shared one.
         */
        protected readonly NamedRoutes $namedRoutes = new NamedRoutes()
    ) {
    }

    /**
     * The lifecycle this adapter publishes into.
     *
     * @see \Framework\Routing\RouteDomain
     */
    abstract public function domain(): string;

    /**
     * Hand every collected route to WordPress.
     *
     * Implementations may assume they are called at most once per instance —
     * the guard is {@see flush()}'s job, not theirs.
     */
    abstract protected function publish(): void;

    // ------------------------------------------------------------- collection

    public function registrar(): RouteRegistrar
    {
        return new RouteRegistrar($this);
    }

    /**
     * @throws RouteRegistrationException
     */
    public function addRoute(Route $route): Route
    {
        // Handlers are deliberately not validated here. A route is stored the
        // moment it is created so the caller can keep configuring it fluently —
        // `$router->get('leads')->middleware(...)` holds this object — which means
        // it is legitimately handler-less right now. The check belongs in
        // publish(), by which point every route has been handed back.
        foreach ($route->getMethods() as $method) {
            $key = $this->routeKey($method, $route);

            if (isset($this->routes[$key])) {
                throw RouteRegistrationException::duplicate($method, $route->getDisplayPath());
            }

            $this->routes[$key] = $route;
        }

        // The same instance that was stored, so fluent configuration lands on the
        // route the router is actually holding. Returning a copy would silently
        // configure an object nobody publishes — the route would register with no
        // middleware and no name, and nothing would report it.
        return $route;
    }

    /**
     * @return list<Route>
     */
    public function getRoutes(): array
    {
        // Keyed by method+path, so a route registered for several verbs would
        // otherwise appear once per verb in any listing.
        return array_values(array_unique($this->routes, SORT_REGULAR));
    }

    /**
     * The fluent verbs, as the router itself exposes them.
     *
     * Each one delegates to a fresh registrar, so a caller holding an adapter gets
     * exactly the routes a routes file would — including the domain's surface
     * decision, since a web adapter's registrar is a {@see \Framework\Routing\WebRouteRegistrar}.
     * A REST router's `get()` is therefore an endpoint and a web router's is an
     * admin screen, which is the one place the two domains' `get()` differ.
     *
     * The action takes either spelling: a controller class name with its action
     * alongside, or a `[Controller::class, 'method']` pair.
     *
     * @param string|array{0: string, 1: string}|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    public function get(string $path, string|array|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->registrar()->get($path, $controller, $action);
    }

    /**
     * @param string|array{0: string, 1: string}|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    public function post(string $path, string|array|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->registrar()->post($path, $controller, $action);
    }

    /**
     * @param string|array{0: string, 1: string}|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    public function put(string $path, string|array|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->registrar()->put($path, $controller, $action);
    }

    /**
     * @param string|array{0: string, 1: string}|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    public function delete(string $path, string|array|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->registrar()->delete($path, $controller, $action);
    }

    // ------------------------------------------------------------ publication

    /**
     * Publish once, then no-op.
     *
     * The guard lives here rather than in each subclass because "a hook fired
     * twice" is a property of WordPress, not of the mechanism — every adapter
     * needs it, and forgetting it is a silent duplicate registration rather than
     * an error.
     *
     * ## Why the named-route registry is fed from here
     *
     * `publish()` is where the adapter decides the things a URL needs and writes
     * them back onto the routes — the `admin_post_{action}` name in particular,
     * which is derived from the route and recorded as an option. Registering with
     * {@see NamedRoutes} *after* that means the generator reads the same final
     * names WordPress was hooked on, rather than re-deriving them and risking a
     * second rule that drifts from the first.
     *
     * It is also the earliest correct moment: before a flush the adapter's routes
     * are registered but not published, so their surfaces and action names are not
     * settled. A generator asked for a URL at that point is asking about a route
     * that does not exist yet, and {@see UrlGenerator::route()} says so by name.
     */
    public function flush(): void
    {
        if ($this->flushed) {
            return;
        }

        $this->flushed = true;

        $this->publish();

        $this->namedRoutes->add($this);
    }

    public function hasFlushed(): bool
    {
        return $this->flushed;
    }

    // --------------------------------------------------------------- dispatch

    /**
     * Resolve the route's handler and invoke it.
     *
     * The framework `Request` is bound for the duration of the call, so a
     * controller type-hinting it receives the request being dispatched rather
     * than the superglobal capture taken at plugin load. The binding is scoped —
     * installed before the controller is resolved, restored in a `finally` —
     * because `Container::instance()` has no unset, and leaving it behind would
     * hand the *finished* request to whatever asks next. Under a persistent
     * worker that is routine rather than hypothetical.
     *
     * @param callable(): mixed $destination The controller call itself, so the
     *                                      binding is installed before anything
     *                                      inside it resolves.
     *
     * @return mixed
     */
    protected function dispatch(Route $route, Request $request, callable $destination): mixed
    {
        return $this->withHttpRequest($request, function () use ($route, $destination): mixed {
            return $this->dispatchTo($route, $destination);
        });
    }

    /**
     * @param callable(): mixed $destination
     *
     * @return mixed
     */
    private function dispatchTo(Route $route, callable $destination): mixed
    {
        try {
            return $destination();
        } catch (RouteRegistrationException | RouteResolutionException $e) {
            // A bad route definition is a programmer error, but it must not
            // escape into a WordPress hook: WordPress calls these callbacks with
            // no error handling of its own, so an uncaught throwable here is an
            // opaque fatal rather than a message naming the route.
            return $this->failed($route, $e->getMessage());
        }
    }

    /**
     * Run a callback with `Framework\Http\Request` bound to $request.
     *
     * Public because a subclass's publish() may need to establish the binding
     * around code of its own — the web adapter does, to match a path — and
     * because the tests assert the restore behaviour directly.
     *
     * @param callable(): mixed $callback
     *
     * @return mixed
     */
    protected function withHttpRequest(Request $request, callable $callback): mixed
    {
        // Nothing to bind without the framework's HTTP layer: a consuming project
        // may have dropped HttpServiceProvider, and routing must keep working.
        if (!class_exists(Request::class)) {
            return $callback();
        }

        $hadPrevious = $this->container->bound(Request::class);
        $previous = $hadPrevious ? $this->container->resolve(Request::class) : null;

        $this->container->instance(Request::class, $request);

        try {
            return $callback();
        } finally {
            // `instance()` only ever sets, so restoring means putting the old one
            // back or forgetting the binding entirely when there was none.
            if ($hadPrevious) {
                $this->container->instance(Request::class, $previous);
            } else {
                $this->container->forget(Request::class);
            }
        }
    }

    /**
     * Resolve a controller and call one action on it.
     *
     * The call goes through {@see ParameterBinder} against the framework
     * `Request`, which is what makes the same method work for both domains. It
     * deliberately does *not* go through {@see RouteResolver::call()}, whose
     * signature is `WP_REST_Request`-shaped: a web route dispatched through that
     * would raise a `TypeError` at the moment it was reached, naming a type
     * mismatch rather than the route that caused it.
     *
     * Returns whatever the action returns, so each adapter decides how to render
     * it — the REST adapter hands it back to WordPress, the web adapter turns it
     * into a response and sends it.
     *
     * @param array<string, mixed> $routeParameters Placeholders matched out of
     *                                              the route path, so an action
     *                                              parameter named for one
     *                                              receives it.
     *
     * @return mixed
     */
    protected function callController(
        string $controller,
        string $action,
        Request $request,
        array $routeParameters = []
    ): mixed {
        try {
            $instance = $this->container->resolve($controller);
        } catch (Throwable $e) {
            // Catches EntryNotFoundException for a class that does not exist and
            // BindingResolutionException for a constructor that cannot be built,
            // plus anything the controller's own constructor raises. All are
            // deployment errors, and each message names the class, which is the
            // one fact needed to fix it.
            return $this->failed($controller, sprintf(
                'Could not build controller [%s] for action [%s]: %s',
                $controller,
                $action,
                $e->getMessage()
            ));
        }

        return $this->resolver->callWithHttpRequest($instance, $action, $request, $routeParameters);
    }

    /**
     * Invoke a route's closure handler.
     *
     * The handler receives the framework `Request` when it asks for one and
     * nothing otherwise, so both `fn () => ...` and `fn (Request $r) => ...` are
     * valid — a closure registered on a route that has no parameters is the
     * common case and should not have to declare an unused argument.
     *
     * Routed through the binder rather than the container directly so a closure
     * naming a route placeholder receives it, exactly as a controller action
     * would. The container alone matches overrides by parameter name and knows
     * nothing about the route.
     *
     * @param array<string, mixed> $routeParameters
     *
     * @return mixed
     */
    protected function callHandler(callable $handler, Request $request, array $routeParameters = []): mixed
    {
        return $this->resolver->binder()->callCallable(
            $handler,
            $request,
            new \Framework\Routing\Sources\HttpParameterSource($request, $routeParameters),
            Request::class
        );
    }

    /**
     * Report a route failure in the shape the domain can render.
     *
     * Each adapter overrides this: a REST route answers with a `WP_Error` so
     * WordPress produces a JSON error, while a web route has to send a response
     * itself. The default is deliberately blunt — a subclass that forgets to
     * override it gets a thrown exception rather than a silently empty page.
     *
     * @return mixed
     */
    protected function failed(Route $route, string $message): mixed
    {
        throw new RouteResolutionException(sprintf(
            'Route [%s] failed: %s',
            $route->getDisplayPath(),
            $message
        ));
    }

    /**
     * The key a route is stored and de-duplicated under.
     *
     * The path is included, not just the verb, so two GET routes on different
     * paths coexist and a same-path duplicate is refused. `getPath()` rather than
     * the display path, because the leading slash is cosmetic and `/leads` and
     * `leads` are the same route.
     */
    private function routeKey(string $method, Route $route): string
    {
        return strtoupper($method) . ' ' . $route->getPath();
    }

    /**
     * Whether a middleware class is declared for the route, for diagnostics.
     *
     * @return list<class-string<Middleware>>
     */
    protected function middlewareFor(Route $route): array
    {
        return $route->getMiddleware();
    }
}
