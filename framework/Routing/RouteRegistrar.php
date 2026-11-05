<?php
declare(strict_types=1);

namespace Framework\Routing;

use Framework\Routing\Exceptions\RouteRegistrationException;

/**
 * The fluent route-registration surface handed to a routes file.
 *
 * This is what `$router` is inside `routes/api.php`. Group state (prefix, shared
 * middleware) is stored per registrar instance and applied to each route as it
 * is created, so a group cannot leak into routes declared outside it.
 *
 * Not to be confused with {@see Router}, which owns the collected routes and
 * talks to WordPress.
 */
class RouteRegistrar
{
    public const SUPPORTED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private string $prefix = '';

    /** @var list<class-string<Contracts\Middleware>> */
    private array $middleware = [];

    public function __construct(private readonly Router $router)
    {
    }

    /**
     * Group routes under a shared path prefix and/or middleware.
     *
     * @param array{prefix?: string, middleware?: array<int, class-string<Contracts\Middleware>>} $attributes
     * @param callable(RouteRegistrar): void                                                    $callback
     *
     * @throws RouteRegistrationException
     */
    public function group(array $attributes, callable $callback): void
    {
        $previousPrefix = $this->prefix;
        $previousMiddleware = $this->middleware;

        if (isset($attributes['prefix'])) {
            $this->prefix = $this->joinPaths($this->prefix, (string) $attributes['prefix']);
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
     * @param string|callable|null $controller Controller class name, or a
     *                                          callable handler for a one-off
     *                                          endpoint.
     *
     * @throws RouteRegistrationException
     */
    public function get(string $path, string|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->add('GET', $path, $controller, $action);
    }

    /**
     * @throws RouteRegistrationException
     */
    /**
     * @param string|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    public function post(string $path, string|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->add('POST', $path, $controller, $action);
    }

    /**
     * @throws RouteRegistrationException
     */
    /**
     * @param string|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    public function put(string $path, string|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->add('PUT', $path, $controller, $action);
    }

    /**
     * @throws RouteRegistrationException
     */
    /**
     * @param string|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    public function patch(string $path, string|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->add('PATCH', $path, $controller, $action);
    }

    /**
     * @throws RouteRegistrationException
     */
    /**
     * @param string|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    public function delete(string $path, string|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->add('DELETE', $path, $controller, $action);
    }

    /**
     * Register a route for several verbs at once.
     *
     * @param array<int, string> $methods
     *
     * @throws RouteRegistrationException
     */
    public function match(array $methods, string $path, string|callable|null $controller = null, ?string $action = null): void
    {
        foreach ($methods as $method) {
            $this->add($method, $path, $controller, $action);
        }
    }

    /**
     * @throws RouteRegistrationException
     */
    public function any(string $path, string|callable|null $controller = null, ?string $action = null): void
    {
        $this->match(self::SUPPORTED_METHODS, $path, $controller, $action);
    }

    private function add(string $method, string $path, string|callable|null $controller = null, ?string $action = null): Route
    {
        $method = strtoupper($method);

        if (!in_array($method, self::SUPPORTED_METHODS, true)) {
            throw RouteRegistrationException::unsupportedMethod($method);
        }

        // Configured before it is stored, and mutated in place rather than
        // rebuilt: the fluent callers below hold the reference this method
        // returns, and the Router keeps the same one. Reassigning a clone would
        // leave the registry with a route nobody could then configure.
        $route = Route::make([$method], $this->joinPaths($this->prefix, $path));

        if ($this->middleware !== []) {
            $route->middleware(...$this->middleware);
        }

        if (is_callable($controller)) {
            // A closure is the concise form of a one-off endpoint; treating it
            // as a controller name would be a type error several frames later.
            $route->handle($controller);
        } elseif ($controller !== null) {
            $route->uses($controller, $action ?? 'index');
        }

        $this->router->add($route);

        return $route;
    }

    private function joinPaths(string $prefix, string $path): string
    {
        $joined = trim($prefix . '/' . trim($path, '/'), '/');

        return $joined;
    }
}
