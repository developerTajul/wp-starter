<?php
declare(strict_types=1);

namespace Framework\Routing;

use Framework\Routing\Contracts\RouterInterface;
use Framework\Routing\Exceptions\RouteRegistrationException;

/**
 * The fluent route-registration surface handed to a routes file.
 *
 * This is what `$router` is inside `routes/api.php`, and the base of what
 * `routes/web.php` receives — see {@see WebRouteRegistrar}, which adds the web
 * surfaces and the verb-to-surface mapping on top of everything here.
 *
 * Group state (prefix, shared middleware) is stored per registrar instance and
 * applied to each route as it is created, so a group cannot leak into routes
 * declared outside it.
 *
 * ## Two action spellings
 *
 * Every verb method and `controller()` accepts the action either way:
 *
 *     $router->get('leads', LeadController::class, 'index');   // class + method
 *     $router->get('leads', [LeadController::class, 'index']); // Laravel pair
 *
 * The pair is the Laravel spelling and the one to prefer in a web routes file,
 * where it reads as a single argument. The three-argument form stays because it
 * is what `controller()`, the `#[Route]` attribute and every existing route in
 * this codebase use.
 *
 * Not to be confused with {@see Router}, which owns the collected routes and
 * talks to WordPress.
 */
class RouteRegistrar
{
    public const SUPPORTED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private string $prefix = '';

    /**
     * The `as` prefix every named route inside the current group takes.
     *
     * Kept separate from {@see $prefix}, which is a *path* prefix: `['prefix' =>
     * 'leads', 'as' => 'leads.']` is a normal pairing and the two must not be
     * derived from one another, since a project may reasonably group names without
     * grouping paths (`['as' => 'admin.']`) or the reverse.
     */
    private string $namePrefix = '';

    /** @var list<class-string<Contracts\Middleware>> */
    private array $middleware = [];

    /**
     * Typed against the interface rather than {@see Router}, so the same fluent
     * surface — and every group, prefix and middleware rule below — serves the
     * REST router and both web adapters. `Router` implements it, so a routes file
     * that types its parameter as `RouteRegistrar` is unchanged.
     */
    public function __construct(private readonly RouterInterface $router)
    {
    }

    /**
     * Group routes under a shared path prefix, name prefix and/or middleware.
     *
     *     Route::group(['as' => 'leads.'], function (): void {
     *         Route::get('leads', [LeadController::class, 'index'])->name('index');
     *     });
     *     // -> the route is named "leads.index"
     *
     * ## `as` is a *name* prefix, and it composes
     *
     * `['prefix' => 'leads', 'as' => 'leads.']` is the ordinary pairing, and the
     * two are deliberately independent: a project may group names without grouping
     * paths, or the reverse. Either may be omitted.
     *
     * The prefix is applied through the callback's own `->name()` calls rather than
     * after the fact, because {@see Route::name()} *replaces* the name and the
     * route is not yet named at the moment {@see route()} returns it. So the group
     * has to be in scope when the author names the route — which is what makes
     * names compose across nesting: `['as' => 'admin.']` around `['as' =>
     * 'leads.']` yields `admin.leads.index`, the same way nested path prefixes
     * concatenate.
     *
     * A nested group is entered while the outer one is still in effect, so the
     * middle `as` is *prefixed* onto the outer rather than replacing it.
     *
     * ## The group state is restored in a `finally`
     *
     * A callback that throws — a duplicate route, an unsupported verb — must not
     * leave the registrar stuck in the group's context, or every route declared
     * after it in the same file would silently inherit a prefix nobody gave it.
     *
     * @param array{prefix?: string, as?: string, middleware?: array<int, class-string<Contracts\Middleware>>} $attributes
     * @param callable(RouteRegistrar): void                                                                   $callback
     *
     * @throws RouteRegistrationException
     */
    public function group(array $attributes, callable $callback): void
    {
        $previousPrefix = $this->prefix;
        $previousNamePrefix = $this->namePrefix;
        $previousMiddleware = $this->middleware;

        if (isset($attributes['prefix'])) {
            $this->prefix = $this->joinPaths($this->prefix, (string) $attributes['prefix']);
        }

        if (isset($attributes['as'])) {
            $this->namePrefix = $this->namePrefix . (string) $attributes['as'];
        }

        if (isset($attributes['middleware'])) {
            $this->middleware = array_merge($this->middleware, array_values((array) $attributes['middleware']));
        }

        try {
            $callback($this);
        } finally {
            // Restore in a finally so a throwing callback cannot leave the
            // registrar stuck in the group's context for every route after it.
            $this->prefix = $previousPrefix;
            $this->namePrefix = $previousNamePrefix;
            $this->middleware = $previousMiddleware;
        }
    }

    /**
     * Register several actions of one controller in a single call.
     *
     * Each entry maps an action name to an HTTP verb:
     *
     *     $router->controller(PostController::class, [
     *         'index'  => 'GET',
     *         'store'  => 'POST',
     *         'delete' => 'DELETE',
     *     ], 'posts');
     *
     * This is the imperative spelling. To declare the routes on the controller
     * itself instead, use the #[Route] attribute with
     * {@see Controller::registerRoutes()}.
     *
     * @param class-string          $controller
     * @param array<string, string> $actions    Action name => HTTP method.
     * @param string                $path       Base path; an action named
     *                                          "index" maps to the base itself.
     */
    public function controller(string $controller, array $actions, string $path = ''): void
    {
        foreach ($actions as $action => $method) {
            $this->add($method, $this->joinPaths($path, $action === 'index' ? '' : $action))
                ->uses($controller, $action);
        }
    }

    /**
     * Register one controller action for one or more verbs, with its own
     * middleware and permission callback.
     *
     * This is the seam {@see Controller::registerRoutes()} calls into, so an
     * attribute-declared route goes through exactly the same group-prefix and
     * group-middleware handling as one declared imperatively in a routes file.
     *
     * @param array<int, string>                             $methods
     * @param class-string                                   $controller
     * @param array<int, class-string<Contracts\Middleware>> $middleware
     *
     * @throws RouteRegistrationException
     */
    public function controllerAction(
        array $methods,
        string $path,
        string $controller,
        string $action,
        array $middleware = [],
        ?string $permissionCallback = null
    ): void {
        foreach ($methods as $method) {
            $route = $this->add($method, $path, $controller, $action);

            if ($middleware !== []) {
                $route->middleware(...$middleware);
            }

            if ($permissionCallback !== null) {
                $route->permissionCallback($permissionCallback);
            }
        }
    }

    /**
     * @param string|array{0: string, 1: string}|callable|null $controller
     *        Controller class name, a `[Controller::class, 'method']` pair, or a
     *        callable handler for a one-off endpoint.
     * @param string|null $action Action name, when the controller was given as a
     *        class name. Ignored when it was given as a pair.
     *
     * @throws RouteRegistrationException
     */
    public function get(string $path, string|array|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->add('GET', $path, $controller, $action);
    }

    /**
     * @param string|array{0: string, 1: string}|callable|null $controller
     * @param string|null $action
     *
     * @throws RouteRegistrationException
     */
    public function post(string $path, string|array|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->add('POST', $path, $controller, $action);
    }

    /**
     * @param string|array{0: string, 1: string}|callable|null $controller
     * @param string|null $action
     *
     * @throws RouteRegistrationException
     */
    public function put(string $path, string|array|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->add('PUT', $path, $controller, $action);
    }

    /**
     * @param string|array{0: string, 1: string}|callable|null $controller
     * @param string|null $action
     *
     * @throws RouteRegistrationException
     */
    public function patch(string $path, string|array|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->add('PATCH', $path, $controller, $action);
    }

    /**
     * @param string|array{0: string, 1: string}|callable|null $controller
     * @param string|null $action
     *
     * @throws RouteRegistrationException
     */
    public function delete(string $path, string|array|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->add('DELETE', $path, $controller, $action);
    }

    /**
     * Register a route for several verbs at once.
     *
     * @param array<int, string>                               $methods
     * @param string|array{0: string, 1: string}|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    public function match(array $methods, string $path, string|array|callable|null $controller = null, ?string $action = null): void
    {
        foreach ($methods as $method) {
            $this->add($method, $path, $controller, $action);
        }
    }

    /**
     * @param string|array{0: string, 1: string}|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    public function any(string $path, string|array|callable|null $controller = null, ?string $action = null): void
    {
        $this->match(self::SUPPORTED_METHODS, $path, $controller, $action);
    }

    /**
     * Build, configure and collect one route.
     *
     * **The single place a route is created.** Every spelling in this class and in
     * {@see WebRouteRegistrar} funnels here, which is what makes the group's prefix
     * and shared middleware impossible to apply from one spelling and forget from
     * another — the failure mode being a route that registers fine and silently
     * runs no guard.
     *
     * Protected rather than private so a subclass can add routes of its own shape
     * (a web surface, a REST endpoint) without reimplementing the group rules.
     *
     * @param list<string>                                     $methods
     * @param string|array{0: string, 1: string}|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    protected function route(
        array $methods,
        string $path,
        string|array|callable|null $controller = null,
        ?string $action = null
    ): Route {
        $methods = array_map(static fn (string $method): string => strtoupper($method), array_values($methods));

        foreach ($methods as $method) {
            if (!in_array($method, self::SUPPORTED_METHODS, true)) {
                throw RouteRegistrationException::unsupportedMethod($method);
            }
        }

        // Configured before it is stored, and mutated in place rather than
        // rebuilt: the fluent callers below hold the reference this method
        // returns, and the Router keeps the same one. Reassigning a clone would
        // leave the registry with a route nobody could then configure.
        $route = Route::make($methods, $this->joinPaths($this->prefix, $path));

        // Handed the group's name prefix rather than named here, because the
        // author names the route from the fluent chain *after* this method
        // returns — see Route::$namePrefix for why the registrar cannot simply
        // rename it afterwards.
        if ($this->namePrefix !== '') {
            $route->namePrefix($this->namePrefix);
        }

        if ($this->middleware !== []) {
            $route->middleware(...$this->middleware);
        }

        $this->applyAction($route, $controller, $action);

        $this->router->addRoute($route);

        return $route;
    }

    /**
     * Point a route at its handler, from either action spelling.
     *
     * Three shapes are accepted, and the order of the checks is what keeps them
     * from overlapping:
     *
     *  - `[Controller::class, 'method']` — a pair of strings, and **only** that.
     *    Laravel's array-callable spelling, and always a controller action: the
     *    class is resolved from the container at dispatch, so its constructor
     *    dependencies are injected exactly as they are for the three-argument
     *    form. A static action given this way is therefore resolved too, rather
     *    than called statically.
     *  - any other callable — a closure, or `[$object, 'method']`, which is a
     *    *handler*, registered through {@see Route::handle()}.
     *  - a class-name string, with the action name alongside it.
     *
     * Anything else — a three-element array, a string-keyed array — is refused
     * here rather than at dispatch, where the same mistake would surface as a
     * missing-method error naming no route.
     *
     * @param string|array{0: string, 1: string}|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    private function applyAction(Route $route, string|array|callable|null $controller, ?string $action): void
    {
        if (is_array($controller) && array_is_list($controller) && count($controller) === 2
            && is_string($controller[0]) && $controller[0] !== '' && is_string($controller[1])
        ) {
            $route->uses($controller[0], $controller[1]);

            return;
        }

        if (is_callable($controller)) {
            $route->handle($controller);

            return;
        }

        if (is_string($controller) && $controller !== '') {
            $route->uses($controller, $action ?? 'index');

            return;
        }

        if ($controller !== null) {
            throw RouteRegistrationException::invalidAction(get_debug_type($controller));
        }
    }

    private function add(string $method, string $path, string|array|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->route([$method], $path, $controller, $action);
    }

    private function joinPaths(string $prefix, string $path): string
    {
        $joined = trim($prefix . '/' . trim($path, '/'), '/');

        return $joined;
    }
}
