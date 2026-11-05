<?php
declare(strict_types=1);

namespace Framework\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Routing\Contracts\Middleware;
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

        $ordered = $this->sortBySpecificity($this->routes);

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

    /**
     * How many placeholders in a path carry a constraint.
     *
     * Used only as a tie-break when two paths have the same number of literal
     * segments, so a constrained `{id:\d+}` sorts ahead of a bare `{id}`.
     *
     * The scan reuses `findPlaceholderEnd()`, which is the only parser that
     * agrees with `substitutePlaceholders()` about where a placeholder ends. It
     * is not that a `[^}]+` match gets the common cases wrong — on
     * `a/{x:[0-9]{1,3}}/b/{y}` it happens to return the same answer, because it
     * can only ever match the inner `{1,3}` fragment and so counts that one
     * placeholder once. The two diverge on the *empty* constraint: `{x:}` is a
     * placeholder with no constraint under the scanner, and a `[^}]+` match
     * finds nothing at all there, scoring the path as unconstrained.
     */
    private static function constrainedSegments(string $path): int
    {
        $count = 0;
        $length = strlen($path);
        $i = 0;

        while ($i < $length) {
            if ($path[$i] !== '{') {
                $i++;
                continue;
            }

            $end = self::findPlaceholderEnd($path, $i);

            if ($end === null) {
                $i++;
                continue;
            }

            if (str_contains(substr($path, $i + 1, $end - $i - 1), ':')) {
                $count++;
            }

            $i = $end + 1;
        }

        return $count;
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
        return self::escapeNonPlaceholders(self::substitutePlaceholders($path));
    }

    /**
     * Escape everything outside the named groups, so only the literal parts of
     * the path are quoted and a constraint's own regex survives untouched.
     *
     * Scanned with `findPlaceholderEnd()` rather than split on a regex, because
     * this is the same "where does a placeholder end" question `substitutePlaceholders()`
     * answers, and a second, weaker parser here silently produces dead routes:
     * a constraint containing a nested group — `{id:((\d+))}`, `{id:(?:a(b))}`,
     * `{a:[^}]+}` — is a perfectly ordinary regex, but a `\([^()]*\)` splitter
     * cannot span its parens, so it fails to recognise the group at all and
     * `preg_quote`s the whole `(?P<id>...)` into literal text. WordPress then
     * 404s every request to the route, with nothing raised at registration.
     *
     * The group is found by matching the `(?P<name>` opener and then counting
     * parens — including ones inside a character class, which PHP's own compiler
     * treats as literal — to the paren that closes the group. If the group never
     * closes, the remainder is quoted as literal text, which is what an
     * unescaped `(` deserves.
     */
    private static function escapeNonPlaceholders(string $translated): string
    {
        $pattern = '';
        $length = strlen($translated);
        $i = 0;
        $literalStart = 0;

        while ($i < $length) {
            if ($translated[$i] !== '('
                || preg_match('/\G\(\?P<[A-Za-z_][A-Za-z0-9_]*>/', $translated, $m, 0, $i) !== 1
            ) {
                $i++;
                continue;
            }

            $end = self::findGroupEnd($translated, $i + strlen($m[0]));

            if ($end === null) {
                $i++;
                continue;
            }

            $pattern .= preg_quote(substr($translated, $literalStart, $i - $literalStart), '@');
            $pattern .= substr($translated, $i, $end - $i + 1);
            $i = $end + 1;
            $literalStart = $i;
        }

        return $pattern . preg_quote(substr($translated, $literalStart), '@');
    }

    /**
     * The index of the `)` closing the capture group whose body starts at
     * $start, or null when it never closes.
     *
     * Parens inside a character class are literal to PCRE — `[(]` matches a
     * paren, it does not open a group — so a class is skipped whole rather than
     * counted. An escaped paren is skipped too. Bailing out on an unterminated
     * class or a trailing backslash leaves the caller to treat the text as
     * literal, which is the safe direction: quoting a stray `(` produces a
     * pattern that matches a literal paren, where counting it as a group would
     * unbalance the compiled pattern and kill the route.
     */
    private static function findGroupEnd(string $subject, int $start): ?int
    {
        $length = strlen($subject);
        $depth = 1;
        $inClass = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $subject[$i];

            if ($char === '\\') {
                $i++;
                continue;
            }

            if ($inClass) {
                if ($char === ']') {
                    $inClass = false;
                }

                continue;
            }

            if ($char === '[') {
                $inClass = true;
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char !== ')') {
                continue;
            }

            $depth--;

            if ($depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Replace `{name}` and `{name:constraint}` with a named capture group.
     *
     * Scanned rather than matched with a regex, because a constraint is itself a
     * regex and can contain braces — `{id:[0-9]{1,3}}` is a natural way to bound a
     * numeric id, and a `[^}]+` character class stops at the quantifier's closing
     * brace. That truncation is silent: the compiled pattern then contains
     * `[0-9]{1,3)\}`, which matches nothing, so the route is dead and WordPress
     * 404s every request to it with no error at registration.
     *
     * Braces are therefore counted: the constraint ends at the `}` that returns
     * the depth to zero, so a quantifier or a nested group inside it is kept whole.
     */
    private static function substitutePlaceholders(string $path): string
    {
        $result = '';
        $length = strlen($path);
        $i = 0;

        while ($i < $length) {
            if ($path[$i] !== '{') {
                $result .= $path[$i];
                $i++;
                continue;
            }

            $end = self::findPlaceholderEnd($path, $i);

            // Not a placeholder — an unmatched `{`, or a name that does not fit
            // the convention. Left verbatim so the caller's escaping handles it,
            // rather than swallowed as a malformed group.
            if ($end === null) {
                $result .= $path[$i];
                $i++;
                continue;
            }

            $body = substr($path, $i + 1, $end - $i - 1);
            [$name, $constraint] = array_pad(explode(':', $body, 2), 2, null);

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                $result .= substr($path, $i, $end - $i + 1);
                $i = $end + 1;
                continue;
            }

            // `{id:}` splits to an empty constraint, which is *not* null — so a
            // `?? '[^/]+'` would not catch it, and the group would compile to
            // `(?P<id>)`. That matches the empty string rather than a path
            // segment, and since WordPress anchors every pattern, such a route
            // would also shadow any sibling it is registered before. Treated as
            // "no constraint given" instead.
            if ($constraint === null || trim($constraint) === '') {
                $constraint = '[^/]+';
            }

            $result .= sprintf('(?P<%s>%s)', $name, $constraint);
            $i = $end + 1;
        }

        return $result;
    }

    /**
     * The index of the `}` closing the placeholder that opens at $start, or null
     * when there is no such brace (or the body is not a plausible placeholder).
     *
     * A `:` splits the name from the constraint, and everything after it is
     * treated as regex — where a `{` opens a quantifier that must be closed before
     * the placeholder itself can close. Before the `:` a brace cannot legitimately
     * appear, so one there means this was never a placeholder.
     */
    private static function findPlaceholderEnd(string $path, int $start): ?int
    {
        $length = strlen($path);
        $depth = 0;
        $seenColon = false;

        for ($i = $start + 1; $i < $length; $i++) {
            $char = $path[$i];

            if ($char === ':') {
                $seenColon = true;
                continue;
            }

            if ($char === '{') {
                if (!$seenColon) {
                    return null;
                }

                $depth++;
                continue;
            }

            if ($char !== '}') {
                continue;
            }

            if ($depth > 0) {
                $depth--;
                continue;
            }

            return $i;
        }

        return null;
    }

}
