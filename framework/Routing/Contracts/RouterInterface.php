<?php
declare(strict_types=1);

namespace Framework\Routing\Contracts;

use Framework\Routing\Exceptions\RouteRegistrationException;
use Framework\Routing\Route;
use Framework\Routing\RouteRegistrar;

/**
 * One routing domain: a place routes are collected and then published.
 *
 * WordPress has four unrelated routing mechanisms and no shared abstraction over
 * them. `admin_menu` builds a navigation tree and hands back a screen id;
 * `admin_post_{action}` fires only for a matching hidden field; `template_redirect`
 * is a "the query is resolved, decide what to do" hook with no notion of a verb;
 * and `rest_api_init` wants a regex plus a permission callback. A project that
 * uses more than one of them otherwise writes four kinds of glue that drift.
 *
 * This interface is the seam that makes them one thing. Implementations collect
 * routes in memory and compile them into their own mechanism, which is what lets
 * a controller be written once against `Request`/`Response` and reached from an
 * admin page, a form post and a REST endpoint without knowing which it is.
 *
 * ## Why `flush()` and not `register()`
 *
 * Collection happens while plugins load; publication happens at the hook the
 * mechanism owns. Keeping them apart is what lets one plugin's routes coexist
 * with another's, and it is what makes a route list inspectable before anything
 * is handed to WordPress — {@see \Framework\Console\Commands\RouteListCommand}
 * depends on exactly that.
 *
 * `flush()` is therefore the only method with a side effect on the host system,
 * and it must be idempotent: WordPress can fire a hook more than once in a
 * process, and a second pass must not collide with the first.
 *
 * ## Methods are declared too, not only verbs
 *
 * `get()`/`post()`/`put()`/`delete()` are the fluent shorthand written in a
 * routes file. `addRoute()` is the general form underneath them, and
 * `getRoutes()` is the read side both the listing command and the tests use.
 */
interface RouterInterface
{
    /**
     * The fluent surface handed to a routes file.
     *
     * A routes file is:
     *
     *     return function (RouteRegistrar $router): void {
     *         $router->get('leads', LeadController::class, 'index');
     *     };
     *
     * so what it receives is the registrar rather than the router. Having the
     * interface expose it is what makes `web.php` and `api.php` the same file
     * shape over two different mechanisms.
     */
    public function registrar(): RouteRegistrar;

    /**
     * Collect a route that has already been built.
     *
     * The general form: everything `get()` and its siblings do ultimately lands
     * here. Kept public so a route assembled elsewhere — an attribute-driven
     * controller, a package shipping its own endpoints — can be added without
     * going through the fluent shorthand.
     *
     * **Returns the route it was given, not void.** The route is stored the moment
     * it is added, so that it can keep being configured — `->middleware()`,
     * `->name()`, `->withOptions()` all mutate the stored instance. Returning it
     * is what lets a caller write
     * `$router->addRoute(Route::make(...))->middleware(...)`, and it is why every
     * domain-specific helper on an adapter — `adminPage()`, `adminAction()`,
     * `page()` — can be fluent: each one builds a route, adds it and hands the
     * same object back. A `void` return would make all of them return null and
     * their fluent chaining fail at the first call.
     *
     * @throws RouteRegistrationException On a duplicate method+path pair.
     */
    public function addRoute(Route $route): Route;

    /**
     * Every route collected so far, in registration order.
     *
     * @return list<Route>
     */
    public function getRoutes(): array;

    /**
     * Publish the collected routes to WordPress.
     *
     * Called from the hook this domain owns — `admin_menu`, `rest_api_init`,
     * `template_redirect`. Must be safe to call twice: the second call is a
     * no-op rather than a re-registration, because WordPress fires several of
     * these hooks more than once per process under a worker or a reset server.
     */
    public function flush(): void;

    /**
     * The domain this router publishes into, for diagnostics and `route:list`.
     *
     * One of the {@see \Framework\Routing\RouteDomain} cases — "web", "admin",
     * "api". A string rather than an enum in the signature so an implementation
     * outside this framework can report a domain of its own without the enum
     * having to grow a case for it.
     */
    public function domain(): string;

    /**
     * Register one GET route.
     */
    public function get(string $path, ?string $controller = null, ?string $action = null): Route;

    /**
     * Register one POST route.
     */
    public function post(string $path, ?string $controller = null, ?string $action = null): Route;

    /**
     * Register one PUT route.
     */
    public function put(string $path, ?string $controller = null, ?string $action = null): Route;

    /**
     * Register one DELETE route.
     */
    public function delete(string $path, ?string $controller = null, ?string $action = null): Route;
}
