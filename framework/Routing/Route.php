<?php
declare(strict_types=1);

namespace Framework\Routing;

use InvalidArgumentException;

/**
 * One REST route: an HTTP method, a path, a handler, and its middleware.
 *
 * Mutable by design, and configured in place. The two ways a route gets built —
 * the fluent form (`$router->get('posts')->middleware(...)`) and the declarative
 * form (`#[Route('GET', 'posts', middleware: [...])]`) — both need to hold the
 * same object the Router stored, so a clone-on-write mutator here would silently
 * drop half the configuration. {@see RouteRegistrar::controllerAction()} is
 * where that matters: it returns the stored route so the caller can keep
 * configuring it.
 */
final class Route
{
    /**
     * The HTTP methods WordPress will dispatch this route for.
     *
     * @var list<string>
     */
    private array $methods;

    private string $path;

    /**
     * Controller class name, or null for a closure handler.
     *
     * @var class-string|null
     */
    private ?string $controller;

    private ?string $action;

    /** @var callable|null */
    private $handler;

    /**
     * Middleware class names, run in registration order.
     *
     * @var list<class-string<Contracts\Middleware>>
     */
    private array $middleware = [];

    private ?string $permissionCallback = null;

    /**
     * @param list<string>       $methods
     * @param class-string|null  $controller
     */
    private function __construct(array $methods, string $path, ?string $controller, ?string $action, ?callable $handler)
    {
        $this->methods = $methods;
        $this->path = $path;
        $this->controller = $controller;
        $this->action = $action;
        $this->handler = $handler;
    }

    /**
     * @param list<string> $methods
     */
    public static function make(array $methods, string $path): self
    {
        return new self($methods, self::normalizePath($path), null, null, null);
    }

    /**
     * Point the route at a controller method.
     *
     * @param class-string $controller
     */
    public function uses(string $controller, string $action): self
    {
        $this->controller = $controller;
        $this->action = $action;
        $this->handler = null;

        return $this;
    }

    /**
     * Point the route at a callable, for one-off endpoints.
     *
     * Closures are the escape hatch, not the default: a handler registered here
     * cannot be resolved with its dependencies injected, because there is no
     * class for the container to reflect on.
     */
    public function handle(callable $handler): self
    {
        $this->handler = $handler;
        $this->controller = null;
        $this->action = null;

        return $this;
    }

    /**
     * Attach middleware, run in the order given.
     *
     * @param class-string<Contracts\Middleware> ...$middleware
     */
    public function middleware(string ...$middleware): self
    {
        $this->middleware = array_merge($this->middleware, array_values($middleware));

        return $this;
    }

    /**
     * Set the permission callback directly.
     *
     * Lower level than middleware: WordPress calls this to decide authorization
     * and it can return WP_Error to short-circuit with a specific status code.
     * Prefer middleware unless a specific WP_Error response is needed.
     */
    public function permissionCallback(string $callback): self
    {
        $this->permissionCallback = $callback;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /**
     * The route path, without a leading slash. May contain WordPress-style
     * `<param>` and `(?P<name>...)` segments.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    public function getController(): ?string
    {
        return $this->controller;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function getHandler(): ?callable
    {
        return $this->handler;
    }

    /**
     * @return list<class-string<Contracts\Middleware>>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function getPermissionCallback(): ?string
    {
        return $this->permissionCallback;
    }

    /**
     * Placeholder names in this route's path, in the order they appear.
     *
     * @return list<string>
     */
    public function getParameterNames(): array
    {
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)(?::[^}]+)?\}/', $this->path, $matches);

        return $matches[1];
    }

    /**
     * The path with a leading slash, for display and for error messages.
     */
    public function getDisplayPath(): string
    {
        return '/' . $this->path;
    }

    private static function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new InvalidArgumentException('A route path cannot be empty.');
        }

        return trim($path, '/');
    }
}
