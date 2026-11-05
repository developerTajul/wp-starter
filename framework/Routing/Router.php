<?php
declare(strict_types=1);

namespace Framework\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Routing\Contracts\Middleware;
use Framework\Routing\Exceptions\RouteRegistrationException;
use Framework\Routing\Exceptions\RouteResolutionException;
use WP_Error;
use WP_REST_Request;

/**
 * Collects routes and publishes them to the WordPress REST API.
 *
 * The split matters: collecting happens at plugin load (a routes file is just a
 * PHP file returning a closure) while publishing happens on `rest_api_init`,
 * which fires well after every plugin has loaded. Keeping those apart is what
 * lets one plugin's routes coexist with another's.
 */
class Router
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
        return array_values($this->routes);
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

        $this->registeredNamespaces[$namespace] = true;

        foreach ($this->sortBySpecificity($this->routes) as $route) {
            // Registration is the last moment a route can still be fixed, so
            // this is where a handler that never arrived is reported — naming
            // the path, which is what a developer needs to find the mistake.
            if ($route->getController() === null && $route->getHandler() === null) {
                throw RouteRegistrationException::missingHandler($route->getDisplayPath());
            }

            register_rest_route($namespace, self::toWordPressRoute($route->getPath()), [
                'methods' => $route->getMethods(),
                'callback' => fn (WP_REST_Request $request) => $this->dispatch($route, $request),
                'permission_callback' => $this->buildPermissionCallback($route),
            ]);
        }
    }

    /**
     * Order routes most-specific-first, which is the order WordPress matches in.
     *
     * WordPress tries every registered pattern in insertion order and takes the
     * first that matches, so `posts/{id}` registered before `posts/latest` would
     * dispatch `show` with id="latest" — a silent wrong-action dispatch, not a
     * 404. Counting literal segments rather than comparing patterns keeps this
     * predictable: the more of a path an author spelled out, the earlier it is
     * matched.
     *
     * @param array<string, Route> $routes
     * @return list<Route>
     */
    private function sortBySpecificity(array $routes): array
    {
        $routes = array_values($routes);

        usort($routes, static function (Route $a, Route $b): int {
            $specificity = self::literalSegments($b->getPath()) <=> self::literalSegments($a->getPath());

            if ($specificity !== 0) {
                return $specificity;
            }

            // Same literal count: prefer the route with fewer wildcards in its
            // placeholders, so a constrained `{id:\d+}` beats a bare `{id}`.
            return self::constrainedSegments($b->getPath()) <=> self::constrainedSegments($a->getPath());
        });

        return $routes;
    }

    private static function literalSegments(string $path): int
    {
        $count = 0;

        foreach (explode('/', $path) as $segment) {
            if ($segment !== '' && preg_match('/\{[A-Za-z_]/', $segment) !== 1) {
                $count++;
            }
        }

        return $count;
    }

    private static function constrainedSegments(string $path): int
    {
        preg_match_all('/\{[A-Za-z_][A-Za-z0-9_]*:[^}]+\}/', $path, $matches);

        return count($matches[0]);
    }

    /**
     * Resolve the route's handler and invoke it.
     *
     * @return mixed
     */
    private function dispatch(Route $route, WP_REST_Request $request): mixed
    {
        $this->pinRouteParameters($route, $request);

        $controller = $route->getController();

        if ($controller !== null) {
            try {
                // Resolved per request rather than cached: a controller resolved
                // once and reused would carry request-scoped state into the next
                // request under a persistent-object-cache or long-running setup.
                $instance = $this->container->resolve($controller);
                $action = $route->getAction() ?? 'index';

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
                $result = $this->resolver->callPermission($explicit, $request);

                // `false` and `null` both mean "deny" to WordPress, which is
                // the whole reason `return $allowed ? true : null;` is such a
                // common idiom in permission callbacks. Treating only `false`
                // as a denial would let that idiom fall through to the
                // middleware loop and then be reported as allowed — turning a
                // deliberately protected route into a public one.
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
     * WordPress hands the registered `route` the path with the namespace already
     * stripped, and compiles it as `@^<route>$@i`, so everything that is not a
     * placeholder must be escaped. Without that, a literal `.` matches any
     * character (`file.json` and `fileXjson` both dispatch), and a stray `(` ,
     * `[` or `+` corrupts the pattern so `preg_match` fails and the route is
     * silently dead.
     *
     * Placeholder constraints are left as written — they are regex by design.
     */
    public static function toWordPressRoute(string $path): string
    {
        $translated = preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)(?::([^}]+))?\}/',
            static function (array $matches): string {
                $name = $matches[1];
                $constraint = $matches[2] ?? '[^/]+';

                return sprintf('(?P<%s>%s)', $name, $constraint);
            },
            $path
        );

        if ($translated === null) {
            $translated = $path;
        }

        // Escape only the segments between placeholders, so a constraint's own
        // regex survives untouched.
        $segments = preg_split(
            '/(\(\?P<[A-Za-z_][A-Za-z0-9_]*>(?:[^()]|\([^()]*\))*\))/',
            $translated,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        if ($segments === false) {
            return $translated;
        }

        $pattern = '';

        foreach ($segments as $segment) {
            $pattern .= str_starts_with($segment, '(?P<')
                ? $segment
                : preg_quote($segment, '@');
        }

        return $pattern;
    }

}
