<?php
declare(strict_types=1);

namespace Framework\Routing\Adapters;

use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Exceptions\HttpException;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request;
use Framework\Http\Responses\HtmlResponse;
use Framework\Http\Responses\JsonResponse;
use Framework\Routing\AdminPage;
use Framework\Routing\Contracts\Middleware as RoutingMiddleware;
use Framework\Routing\Exceptions\RouteRegistrationException;
use Framework\Routing\Route;
use Framework\Routing\RouteCompiler;
use Framework\Routing\RouteDomain;
use Framework\Routing\RouteResolver;
use Framework\Routing\WebRequestFactory;
use Throwable;

/**
 * Publishes routes onto WordPress's non-REST routing mechanisms.
 *
 * WordPress has three of them and no shared abstraction over any:
 *
 *  - **`admin_menu`** — builds the wp-admin navigation tree. `add_menu_page()`
 *    and `add_submenu_page()` take a *slug*, and the callback fires when that
 *    screen is rendered.
 *  - **`admin_post_{action}`** — fires only for the form whose `action` field
 *    matches, with `admin_post_nopriv_{action}` for logged-out callers. There is
 *    no verb: a form can only send GET or POST.
 *  - **`template_redirect`** — "the query is resolved, decide what to do". It
 *    carries no path and no verb, so matching is this adapter's own job.
 *
 * ## The surface decides the mechanism
 *
 * A path alone cannot say which of the three an author meant — `leads` could
 * reasonably be an admin screen or a front-end page — so the surface is
 * **explicit** wherever it is ambiguous:
 *
 *     $router->adminPage('leads', LeadAdmin::class, 'index');     // admin_menu
 *     $router->adminAction('leads/store', LeadAdmin::class);      // admin_post_*
 *     $router->page('thank-you', Thanks::class, 'index');         // template_redirect
 *
 * `get()`/`post()`/... still work and infer from the verb — a non-GET verb is a
 * form handler, a bare GET is a front-end page — so `routes/web.php` reads like
 * `routes/api.php` for the common cases. The helpers set the surface option
 * explicitly, which is what a GET route that is *not* a front-end page needs.
 *
 * ## Middleware here is the HTTP contract, not the routing one
 *
 * A web route's response belongs to the application, so its middleware runs
 * through {@see Pipeline} as `Framework\Http\Contracts\Middleware` — the onion
 * that can rewrite the request and decorate the response, and where
 * `Authenticate`, `Authorize` and `VerifyAdminNonce` live. That is the opposite
 * of a REST route, where WordPress owns the response and middleware is confined
 * to the `permission_callback`'s deny-only seam. Both interfaces exist for
 * exactly this reason; see {@see \Framework\Routing\Contracts\Middleware}.
 *
 * A class implementing the *routing* contract on a web route is a mistake that
 * would otherwise fail somewhere unhelpful, so it is reported by name.
 */
final class WordPressRouterAdapter extends AbstractRouterAdapter
{
    /**
     * Route options under which the surface is recorded.
     */
    public const OPTION_SURFACE = 'surface';

    /**
     * The surfaces a web route can publish onto.
     */
    public const SURFACE_ADMIN_PAGE = 'admin_page';

    public const SURFACE_ADMIN_ACTION = 'admin_action';

    public const SURFACE_PAGE = 'page';

    /**
     * How a matched front-end route ends the request.
     *
     * @var callable(): void
     */
    private $terminator;

    /**
     * @param (callable(): void)|null $terminator Defaults to a real `exit`, which
     *                                            is what production needs and what
     *                                            a test cannot survive.
     */
    public function __construct(
        \DevLinkStudios\WPServiceContainer\Container $container,
        RouteResolver $resolver,
        private readonly WebRequestFactory $requests,
        private readonly ?Pipeline $pipeline = null,
        ?callable $terminator = null
    ) {
        parent::__construct($container, $resolver);

        $this->terminator = $terminator ?? static function (): void {
            exit;
        };
    }

    public function domain(): string
    {
        return RouteDomain::Web->value;
    }

    // ------------------------------------------------------------- fluent sugar

    /**
     * Register an admin screen, published on `admin_menu`.
     *
     * The path is the menu slug WordPress is given, so it is what appears in
     * `admin.php?page={slug}`. Options carry the rest of what WordPress wants:
     *
     *     ->withOptions([
     *         'page_title'  => 'All Leads',
     *         'menu_title'  => 'Leads',
     *         'capability'  => 'manage_options',
     *         'icon'        => 'dashicons-email',
     *         'position'    => 26,
     *         'parent_slug' => 'tools.php',   // submenu instead of top-level
     *     ])
     *
     * @throws RouteRegistrationException
     */
    public function adminPage(string $path, string $controller, string $action = 'index'): Route
    {
        return $this->addRoute(
            Route::make(['GET'], $path)
                ->uses($controller, $action)
                ->option(self::OPTION_SURFACE, self::SURFACE_ADMIN_PAGE)
        );
    }

    /**
     * Register a form handler, published on `admin_post_*`.
     *
     * Defaults to POST. Pass a verb to register the same handler for another —
     * a browser cannot send PUT or DELETE, so reach those through the `_method`
     * field {@see \Framework\Http\Request::method()} already understands:
     *
     *     <input type="hidden" name="_method" value="DELETE">
     *     <input type="hidden" name="action" value="leads_destroy">
     *
     * @throws RouteRegistrationException
     */
    public function adminAction(string $path, string $controller, string $action = 'store', string $method = 'POST'): Route
    {
        return $this->addRoute(
            Route::make([strtoupper($method)], $path)
                ->uses($controller, $action)
                ->option(self::OPTION_SURFACE, self::SURFACE_ADMIN_ACTION)
        );
    }

    /**
     * Register a front-end route, matched on `template_redirect`.
     *
     * The path is a URL on the site — `thank-you` matches `/thank-you` — and may
     * carry placeholders with the same grammar the REST router uses.
     *
     * @throws RouteRegistrationException
     */
    public function page(string $path, string $controller, string $action = 'index'): Route
    {
        return $this->addRoute(
            Route::make(['GET'], $path)
                ->uses($controller, $action)
                ->option(self::OPTION_SURFACE, self::SURFACE_PAGE)
        );
    }

    // -------------------------------------------------------------- publication

    protected function publish(): void
    {
        $ordered = RouteCompiler::sortBySpecificity($this->routes);

        // Validated in full before anything is registered, so one bad route
        // cannot leave the set half-published — WordPress has already accepted
        // the earlier registrations by the time a throw would escape, and the
        // remaining routes would be silently missing.
        foreach ($ordered as $route) {
            if ($route->getController() === null && $route->getHandler() === null) {
                throw RouteRegistrationException::missingHandler($route->getDisplayPath());
            }
        }

        // Resolved before anything is registered, because two routes deriving the
        // same action name would silently hijack each other's hook — the second
        // `add_action()` on one tag does not replace the first, so both callbacks
        // fire and the *wrong controller runs*, with nothing raised. A collision
        // is a routes-file mistake, so it is refused loudly, at the last moment
        // it can still be fixed.
        $actions = $this->resolveAdminActions($ordered);

        $this->registerAdminPages($ordered);
        $this->registerAdminActions($ordered, $actions);
        $this->registerPages($ordered);
    }

    /**
     * Publish every admin-screen route on `admin_menu`.
     *
     * The menu callbacks are registered inside one `admin_menu` listener rather
     * than one listener per route, because `admin_menu` fires once per admin
     * request and `add_menu_page()` has to be called during it — registering a
     * separate listener per route would work and would also mean N closures
     * where one does.
     *
     * @param list<Route> $routes
     */
    private function registerAdminPages(array $routes): void
    {
        $pages = array_values(array_filter(
            $routes,
            fn (Route $route): bool => $this->surfaceOf($route) === self::SURFACE_ADMIN_PAGE
        ));

        if ($pages === [] || !function_exists('add_action')) {
            return;
        }

        add_action('admin_menu', function () use ($pages): void {
            foreach ($pages as $route) {
                $this->registerAdminPage($route);
            }
        });
    }

    private function registerAdminPage(Route $route): void
    {
        // Read through the value object rather than the option bag, so this and
        // every other reader — the route listing, a view rendering the menu —
        // resolve `menu_title` and its fallbacks from one place. See
        // {@see AdminPage} for why the defaults are what they are.
        $page = AdminPage::fromRoute($route);

        $callback = fn (): mixed => $this->dispatchWeb($route, []);

        if ($page->isSubmenu()) {
            if (!function_exists('add_submenu_page')) {
                return;
            }

            add_submenu_page(
                (string) $page->parentSlug(),
                $page->pageTitle(),
                $page->menuTitle(),
                $page->capability(),
                $page->slug(),
                $callback,
                $page->position()
            );

            return;
        }

        if (function_exists('add_menu_page')) {
            add_menu_page(
                $page->pageTitle(),
                $page->menuTitle(),
                $page->capability(),
                $page->slug(),
                $callback,
                $page->icon(),
                $page->position()
            );
        }
    }

    /**
     * Publish every form-handler route on `admin_post_{action}`.
     *
     * **Both** hooks are registered for each action, and that pairing is the
     * whole point: `admin_post_{action}` fires only for a logged-in user, and
     * `admin_post_nopriv_{action}` only for a logged-out one. Registering just
     * the first is the classic bug — the form works perfectly while logged in,
     * and silently 0s out for everyone else. A handler that should be
     * login-only says so with middleware, which can return a redirect to the
     * login form; that is a decision the route makes, not a default this adapter
     * imposes by omitting a hook.
     *
     * @param list<Route>             $routes
     * @param array<string, string>   $actions Route object id => action name.
     */
    private function registerAdminActions(array $routes, array $actions): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        foreach ($routes as $route) {
            if ($this->surfaceOf($route) !== self::SURFACE_ADMIN_ACTION) {
                continue;
            }

            $action = $actions[spl_object_id($route)];

            $callback = fn (): mixed => $this->dispatchWeb($route, []);

            add_action('admin_post_' . $action, $callback);
            add_action('admin_post_nopriv_' . $action, $callback);
        }
    }

    /**
     * Publish every front-end route on `template_redirect`.
     *
     * One listener matches the request path against every route and dispatches
     * the first hit. `template_redirect` fires on **every** front-end request,
     * so a path that matches nothing must return without sending anything —
     * WordPress then renders the theme as it normally would. Sending an empty
     * response on a miss would blank the entire site.
     *
     * @param list<Route> $routes
     */
    private function registerPages(array $routes): void
    {
        $pages = array_values(array_filter(
            $routes,
            fn (Route $route): bool => $this->surfaceOf($route) === self::SURFACE_PAGE
        ));

        if ($pages === [] || !function_exists('add_action')) {
            return;
        }

        add_action('template_redirect', function () use ($pages): void {
            $path = $this->requestPath();

            foreach ($pages as $route) {
                $params = $this->matchPath($route, $path);

                if ($params === null) {
                    continue;
                }

                $this->dispatchWeb($route, $params);

                // Only reached when a route genuinely matched. Terminating is how
                // a front-end route ends the request: WordPress has already
                // resolved the query and would otherwise go on to render the
                // theme on top of whatever the controller sent.
                $this->terminate();

                return;
            }
        });
    }

    /**
     * End the request after a front-end route has sent its response.
     *
     * An injected collaborator rather than a bare `exit` at the call site, because
     * `exit` cannot be intercepted, stubbed or caught: a test that fires
     * `template_redirect` through a real route would kill the PHPUnit process, so
     * the only way to exercise the match-and-dispatch path at all is to be able to
     * replace this. A protected method would not do — this class is `final`, so
     * there is no subclass to override it in.
     *
     * `exit` rather than `return` is not a stylistic choice, and the default
     * reflects it. `template_redirect` is an action, not a filter: returning from
     * a listener tells WordPress nothing, and it proceeds to load the theme
     * template — so the controller's markup would be printed and then the entire
     * page rendered underneath it.
     */
    private function terminate(): void
    {
        ($this->terminator)();
    }

    /**
     * The request path, as it should be matched against a route.
     *
     * Taken from the request URI rather than `$_SERVER` directly, and stripped of
     * the query string and any surrounding slashes, so `thank-you` and
     * `/thank-you/` are the same route. A subdirectory install is handled by
     * trimming the site's own path prefix — WordPress is routinely installed
     * under `/blog`, and without that a route would have to repeat the prefix.
     */
    private function requestPath(): string
    {
        // Matched against the request this adapter built, not against whatever the
        // container happens to hold. The two are usually the same object, and are
        // not required to be: the binding is installed once at boot, while a route
        // is matched per request. Going through the factory keeps the path and the
        // dispatched request describing one request, which is the property that
        // makes `{id}` match the same value the controller receives.
        $uri = $this->requests->make()->uri();

        $path = parse_url($uri, PHP_URL_PATH);

        if (!is_string($path)) {
            $path = '/';
        }

        $prefix = parse_url(function_exists('home_url') ? home_url() : '', PHP_URL_PATH);

        if (is_string($prefix) && $prefix !== '' && $prefix !== '/') {
            $path = preg_replace('#^' . preg_quote(rtrim($prefix, '/'), '#') . '#', '', $path) ?? $path;
        }

        return trim($path, '/');
    }

    /**
     * Match a path against one route, returning its parameters or null.
     *
     * Uses {@see RouteCompiler::toPattern()} — the same translation the REST
     * router hands WordPress — so `{id:\d+}` means the same thing in both
     * domains. A second matcher here would drift from it, and the symptom would
     * be a path that resolves on one surface and 404s on the other.
     *
     * @return array<string, string>|null
     */
    private function matchPath(Route $route, string $path): ?array
    {
        $pattern = '@^' . RouteCompiler::toPattern($route->getPath()) . '$@i';

        if (preg_match($pattern, $path, $matches) !== 1) {
            return null;
        }

        $params = [];

        foreach ($route->getParameterNames() as $name) {
            if (isset($matches[$name])) {
                $params[$name] = (string) $matches[$name];
            }
        }

        return $params;
    }

    /**
     * Dispatch a matched web route and send its response.
     *
     * @param array<string, string> $urlParams
     */
    private function dispatchWeb(Route $route, array $urlParams): void
    {
        // A GET route reached by a non-GET request is a caller error, and acting
        // on it would be worse than refusing: a form posted to a screen's slug
        // would otherwise run the screen's controller with form data as if it
        // were a read. Reported rather than ignored.
        $request = $this->requests->make($urlParams);

        if (!$this->methodAllowed($route, $request)) {
            $this->sendFailure($route, sprintf(
                'Route [%s] accepts %s but the request was %s. To submit a form, declare the route with adminAction() or a non-GET verb.',
                $route->getDisplayPath(),
                implode('/', $route->getMethods()),
                $request->method()
            ), 405);

            return;
        }

        // Bound before the controller is resolved, so a constructor may type-hint
        // the framework Request, and restored afterwards because
        // Container::instance() has no unset.
        $this->withHttpRequest($request, function () use ($route, $request, $urlParams): void {
            $this->runPipeline($route, $request, $urlParams);
        });
    }

    /**
     * Run the route's middleware and controller through the HTTP pipeline.
     *
     * @param array<string, string> $urlParams
     */
    private function runPipeline(Route $route, Request $request, array $urlParams): void
    {
        // Inside the try, deliberately. A middleware written against the wrong
        // contract is a mistake in the route table, and it has to be reported the
        // same way every other such mistake is — through the response, so the
        // operator sees which route and which middleware. Thrown from outside the
        // try it would escape the dispatch entirely and surface as an unhandled
        // throwable inside a WordPress action.
        try {
            $this->assertHttpMiddleware($route);
        } catch (HttpException $e) {
            $this->sendFailure($route, $e->getMessage(), 500);

            return;
        }

        $pipeline = $this->resolvePipeline();

        if ($pipeline === null) {
            // No HTTP layer bound — a project that dropped HttpServiceProvider.
            // The route still runs; it simply has no middleware to run.
            $this->sendDestination($route, $request, $urlParams);

            return;
        }

        try {
            $response = $pipeline
                ->send($request)
                ->through($route->getMiddleware())
                ->then(
                    fn (Request $inner): ResponseInterface => $this->destination($route, $inner, $urlParams)
                );

            $response->send();
        } catch (HttpException $e) {
            $this->sendFailure($route, $e->getMessage(), 500);
        }
    }

    /**
     * Build the response a route's destination produces.
     *
     * A controller action may return any {@see ResponseInterface} — the shape
     * Phase 4 established — or a plain string, which is what a view-less handler
     * naturally produces and is wrapped rather than refused. Anything else is a
     * mistake that would otherwise emit `Array` or nothing at all, so it is
     * reported against the route that caused it.
     */
    private function destination(Route $route, Request $request, array $urlParams = []): ResponseInterface
    {
        return $this->toResponse($route, $this->invoke($route, $request, $urlParams));
    }

    /**
     * @param array<string, mixed> $urlParams
     *
     * @return mixed
     */
    private function invoke(Route $route, Request $request, array $urlParams): mixed
    {
        $controller = $route->getController();

        if ($controller !== null) {
            try {
                $instance = $this->container->resolve($controller);
            } catch (Throwable $e) {
                // Thrown rather than answered, because the response shape depends
                // on who is asking — a REST caller wants JSON, a browser wants a
                // page — and only the error handler knows which. Answering here
                // would hard-code one of the two.
                throw new HttpException(sprintf(
                    'Could not build controller [%s] for route [%s]: %s',
                    $controller,
                    $route->getDisplayPath(),
                    $e->getMessage()
                ));
            }

            // Called through the resolver's HTTP entry point, not
            // RouteResolver::call() — that signature is WP_REST_Request-shaped and
            // would raise a TypeError on a framework Request. See
            // AbstractRouterAdapter::callController().
            return $this->resolver->callWithHttpRequest(
                $instance,
                $route->getAction() ?? 'index',
                $request,
                $urlParams
            );
        }

        $handler = $route->getHandler();

        if ($handler === null) {
            throw new HttpException(sprintf('Route [%s] has no handler.', $route->getDisplayPath()));
        }

        return $this->callHandler($handler, $request, $urlParams);
    }

    /**
     * @param mixed $result
     *
     * @throws HttpException When the result cannot be sent as a response.
     */
    private function toResponse(Route $route, mixed $result): ResponseInterface
    {
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        if (is_string($result)) {
            return new HtmlResponse($result);
        }

        if ($result === null) {
            // A controller that returns nothing has produced no page. An empty
            // 200 would be indistinguishable from a blank screen, and on an
            // admin screen it would look like a broken plugin rather than a
            // missing return — so it is named.
            throw new HttpException(sprintf(
                'Route [%s] produced no response. A web action must return a ResponseInterface or a string.',
                $route->getDisplayPath()
            ));
        }

        // An array is the REST idiom; sending one here would emit the literal
        // text "Array". WP_REST_Response is only rendered by the REST server and
        // would emit nothing at all through this path. Both are worth naming
        // rather than coercing into something that half-works.
        throw new HttpException(sprintf(
            'Route [%s] returned [%s], which cannot be sent as a web response. '
            . 'Return a ResponseInterface or a string — an array or a WP_REST_Response '
            . 'belongs on a route in routes/api.php.',
            $route->getDisplayPath(),
            get_debug_type($result)
        ));
    }

    /**
     * @param array<string, mixed> $urlParams
     */
    private function sendDestination(Route $route, Request $request, array $urlParams = []): void
    {
        try {
            $this->destination($route, $request, $urlParams)->send();
        } catch (HttpException $e) {
            $this->sendFailure($route, $e->getMessage(), 500);
        }
    }

    /**
     * Answer a failed dispatch.
     *
     * The response shape follows the client, as the HTTP layer's middleware
     * already does: a headless caller gets JSON it can parse, a browser gets a
     * plain error page rather than a stack trace or a blank screen.
     */
    private function sendFailure(Route $route, string $message, int $status): void
    {
        $response = $this->container->bound(Request::class)
            && $this->container->resolve(Request::class)->expectsJson()
                ? new JsonResponse(['error' => 'route_failed', 'message' => $message], ['status_code' => $status])
                : new HtmlResponse(
                    sprintf('<h1>%d</h1><p>%s</p>', $status, htmlspecialchars($message, ENT_QUOTES, 'UTF-8')),
                    $status
                );

        try {
            $response->send();
        } catch (Throwable) {
            // Reported and swallowed: this is the last-resort error path, and a
            // throw escaping here would be an unhandled fatal inside a WordPress
            // hook — the outcome this method exists to avoid. A JsonResponse
            // cannot send outside WordPress, and there is nothing further to do
            // about it.
        }
    }

    /**
     * Refuse a route whose middleware is written against the routing contract.
     *
     * `Framework\Routing\Contracts\Middleware` is the deny-only REST seam; a web
     * route's middleware runs through the pipeline and must implement
     * `Framework\Http\Contracts\Middleware`. Nothing enforces that at
     * registration — `Route::middleware()` stores class-string values and must
     * stay contract-agnostic, because the same DTO serves both domains — so a
     * wrong contract would surface later as a container or interface error that
     * names the middleware but not the route that chose it.
     *
     * Checked by interface rather than by class list, so a project's own
     * middleware is covered. A class that cannot be loaded at all is left alone
     * and fails where it is resolved, which gives the container's own message.
     */
    private function assertHttpMiddleware(Route $route): void
    {
        foreach ($route->getMiddleware() as $middlewareClass) {
            if (!class_exists($middlewareClass)) {
                continue;
            }

            if (is_a($middlewareClass, \Framework\Http\Contracts\Middleware::class, true)) {
                continue;
            }

            if (!is_a($middlewareClass, RoutingMiddleware::class, true)) {
                continue;
            }

            throw new HttpException(sprintf(
                'Middleware [%s] on route [%s] implements the routing middleware contract, which only '
                . 'runs inside a REST permission callback. A web route uses %s — implement that instead. '
                . 'See Framework\\Http\\Middleware\\Authenticate for an example.',
                $middlewareClass,
                $route->getDisplayPath(),
                \Framework\Http\Contracts\Middleware::class
            ));
        }
    }

    /**
     * The pipeline, or null when the HTTP layer is not registered.
     *
     * Bound by {@see \Framework\Http\HttpServiceProvider}. Resolved per dispatch
     * rather than held, because the provider binds it as a *non-shared* service —
     * a pipeline is per-use, and one cached here would be reconfigured by the
     * next route to run.
     */
    private function resolvePipeline(): ?Pipeline
    {
        if ($this->pipeline !== null) {
            return $this->pipeline;
        }

        if (!class_exists(Pipeline::class) || !$this->container->bound(Pipeline::class)) {
            return null;
        }

        $pipeline = $this->container->resolve(Pipeline::class);

        return $pipeline instanceof Pipeline ? $pipeline : null;
    }

    /**
     * @param array<string, string> $urlParams
     */
    private function methodAllowed(Route $route, Request $request): bool
    {
        // `_method` is honoured by Request::method() itself, so a form posting
        // `_method=DELETE` reports DELETE here and matches a DELETE route — which
        // is the only way a browser can reach one.
        $declared = array_map('strtoupper', $route->getMethods());

        if (in_array('GET', $declared, true) && $request->isMethod('GET')) {
            return true;
        }

        return in_array($request->method(), $declared, true);
    }

    /**
     * Dispatch the `admin_post_{action}` the current request is addressing.
     *
     * ## Why this exists rather than a plain `add_action('admin_post_' . $action)`
     *
     * The hook name is derived from the route table, and the route table comes
     * from `routes/web.php`, which is only read once a WordPress action fires. On
     * the first form submission of a process the sequence would have to be:
     * read the file → learn the action names → register the hooks → *then* have
     * WordPress fire the right one. It cannot be, because WordPress resolves and
     * fires `admin_post_{action}` in a single pass, and by the time a listener
     * attached to generic `admin_post` runs, that pass is already under way.
     *
     * So the name is not used to find the route — the *request* is. WordPress
     * sets `$_REQUEST['action']` to the action it dispatched, and the routes
     * already published in this same request carry their own action names, so
     * matching the two is both simpler and immune to the ordering problem.
     *
     * A no-op when nothing matches: WordPress fires `admin_post` for its own
     * internal handlers too, and an unmatched action is not this plugin's
     * business. Returning without sending lets WordPress continue to whichever
     * handler owns it.
     */
    public function dispatchCurrentAdminAction(): void
    {
        $action = $_REQUEST['action'] ?? null;

        if (!is_string($action) || $action === '') {
            return;
        }

        foreach (RouteCompiler::sortBySpecificity($this->routes) as $route) {
            if ($this->surfaceOf($route) !== self::SURFACE_ADMIN_ACTION) {
                continue;
            }

            if ($this->actionName($route) !== $action) {
                continue;
            }

            $this->dispatchWeb($route, $this->matchedAdminParams($route));

            // Matched and dispatched. One route owns an action name — collisions
            // are refused at flush() — so there is nothing further to look for.
            return;
        }
    }

    /**
     * The URL-matched parameters for the current admin-post request.
     *
     * An admin-post submission carries its values in the request body, not in
     * the URL: WordPress dispatches on `admin-post.php?action=...` regardless of
     * the path, so a route written as `leads/{id}/delete` never has its
     * placeholder filled from the URL. It is filled from a form field of the same
     * name instead — which is what a form hidden input is for, and what the
     * request's own body already contains.
     *
     * Named here rather than inlined because it is the one place the two
     * mechanisms' difference is visible, and reading `[]` at the call site would
     * look like a route with no parameters rather than a deliberate choice.
     *
     * @return array<string, mixed>
     */
    private function matchedAdminParams(Route $route): array
    {
        $params = [];

        foreach ($route->getParameterNames() as $name) {
            if (isset($_REQUEST[$name]) && is_scalar($_REQUEST[$name])) {
                $params[$name] = (string) $_REQUEST[$name];
            }
        }

        return $params;
    }

    /**
     * The `admin_post_{action}` name a route was published under.
     *
     * Read back from the route's options after flush(), where
     * {@see resolveAdminActions()} recorded it, so a caller — the service
     * provider registering hooks, a view rendering a form — does not derive the
     * name a second time and disagree with what was published.
     */
    public function actionFor(Route $route): ?string
    {
        return AdminPage::fromRoute($route)->action();
    }

    /**
     * The surface a route publishes onto.
     *
     * Explicit when the author used `adminPage()`/`adminAction()`/`page()` or set
     * the option directly. Otherwise inferred from the verb: a non-GET verb is a
     * form handler, and a bare GET is a front-end page. The inference is a
     * convenience for the common case, not a claim about an ambiguous one — a GET
     * route that is really an admin screen says so.
     */
    private function surfaceOf(Route $route): string
    {
        $surface = $route->getOption(self::OPTION_SURFACE);

        if (is_string($surface) && $surface !== '') {
            return $surface;
        }

        return RouteDomain::isMutating($route->getMethods())
            ? self::SURFACE_ADMIN_ACTION
            : self::SURFACE_PAGE;
    }

    /**
     * The `admin_post_{action}` name for every form-handler route.
     *
     * ## Why the verb is part of the name for non-POST verbs
     *
     * `PUT leads/{id}` and `DELETE leads/{id}` both derive `leads` from the path,
     * and would then share one hook — so whichever registered last would not
     * replace the first, and *both* callbacks would fire on one submission. Since
     * a browser reaches PUT and DELETE only through a `_method` field, the verb
     * is the only thing distinguishing them, so it belongs in the name. POST
     * keeps the bare derived name, because it is the overwhelmingly common case
     * and `leads_store` reads better than `leads_store_post`.
     *
     * @param list<Route> $routes
     * @return array<int, string> Keyed by spl_object_id.
     *
     * @throws RouteRegistrationException On a collision.
     */
    private function resolveAdminActions(array $routes): array
    {
        $actions = [];
        $owners = [];

        foreach ($routes as $route) {
            if ($this->surfaceOf($route) !== self::SURFACE_ADMIN_ACTION) {
                continue;
            }

            $action = $this->actionName($route);

            if (isset($owners[$action])) {
                throw RouteRegistrationException::duplicateAction(
                    $action,
                    $owners[$action],
                    $route->getDisplayPath()
                );
            }

            $owners[$action] = $route->getDisplayPath();
            $actions[spl_object_id($route)] = $action;

            // Recorded on the route so `route:list` and a view rendering the
            // form's hidden `action` field both report the name WordPress will
            // actually fire, rather than each deriving it again.
            $route->option('action', $action);
        }

        return $actions;
    }

    /**
     * Derive the `admin_post_{action}` name for one route.
     *
     * A name given with `->name()` wins, because it is the identifier the author
     * chose and already appears in the route table; otherwise the path is used
     * with its placeholders and separators flattened. Non-POST verbs are
     * suffixed — see {@see resolveAdminActions()} for why that is necessary
     * rather than cosmetic.
     */
    private function actionName(Route $route): string
    {
        $explicit = $route->getOption('action');

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $source = $route->getName() ?? $route->getPath();

        // Placeholders are dropped rather than flattened: `leads/{id}` names the
        // same handler whether the id is numeric or a slug, and the value arrives
        // as a request parameter, not as part of the action.
        $source = (string) preg_replace('/\{[^}]*\}/', '', $source);
        $action = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $source));
        $action = trim($action, '_');

        if ($action === '') {
            $action = 'route';
        }

        $verbs = array_map('strtoupper', $route->getMethods());

        if ($verbs !== ['POST']) {
            $action .= '_' . strtolower(implode('_', $verbs));
        }

        return $action;
    }
}
