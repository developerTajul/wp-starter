<?php
declare(strict_types=1);

namespace Framework\Routing;

use Framework\Routing\Adapters\WordPressRouterAdapter;
use Framework\Routing\Exceptions\RouteRegistrationException;

/**
 * The registrar handed to `routes/web.php`: Laravel's route syntax, mapped onto
 * WordPress's mechanisms.
 *
 * ## The surface is inferred from the verb
 *
 * A web route's mechanism is not implied by its path — `leads` could be an admin
 * screen or a front-end page — so this class decides from the verb, which is the
 * one thing a Laravel-shaped routes file always states:
 *
 * | you write | WordPress mechanism | what it is |
 * | --- | --- | --- |
 * | `get($slug, ...)` | `admin_menu` | a wp-admin screen |
 * | `post($slug, ...)` | `admin_post_{action}` | a form handler |
 * | `put`/`patch`/`delete` | `admin_post_{action}` | a form handler (via `_method`) |
 * | `page($url, ...)` | `template_redirect` | a front-end page |
 *
 * A `get()` is an admin screen because that is what a GET means in a *web* routes
 * file: WordPress has no general front-end router, so a front-end request is the
 * case that has to be asked for by name. **Use `page()` for one.** Before this
 * class existed the inference was the other way round — a bare GET was a
 * front-end page — and the change is deliberate: the overwhelming majority of GET
 * routes in a WordPress plugin are admin screens, and a slug that silently
 * becomes an unreachable front-end URL is a worse failure than a URL that has to
 * say `page()`.
 *
 * The three explicit helpers remain, both as documentation and as the way to say
 * something the verb cannot: `adminPage()`, `adminAction()` and `page()`.
 *
 * ## Everything else is inherited
 *
 * `group()` — prefix and shared middleware — the two action spellings
 * (`LeadController::class, 'index'` and `[LeadController::class, 'index']`), and
 * the fluent `->name()`, `->middleware()`, `->withOptions()` chain all come from
 * {@see RouteRegistrar}. Every spelling here funnels through
 * {@see RouteRegistrar::route()}, so the surface is the only thing that differs
 * between them — a group prefix joins onto a slug exactly as it joins onto a URI,
 * and group middleware is never dropped.
 *
 * ## Why this class has to exist at all
 *
 * `RouteLoader` hands a routes file a *registrar*, not the router, because that is
 * what gives a routes file `group()` and what makes `web.php` and `api.php` the
 * same shape over two mechanisms. But a plain registrar has no notion of a
 * surface, so a web routes file could not say "admin screen" — and when the file
 * was typed against the adapter instead, it died on a `TypeError` the moment it
 * loaded and the whole admin surface silently failed to register. This class is
 * the seam that satisfies both: still a `RouteRegistrar`, with the web surfaces
 * on top.
 */
class WebRouteRegistrar extends RouteRegistrar
{
    /**
     * @param WordPressRouterAdapter $web Typed rather than left as
     *      `RouterInterface` so a registrar for the wrong domain cannot be built
     *      here by accident; the adapter is reached through the parent's
     *      `RouterInterface` property, which is the same object.
     */
    public function __construct(WordPressRouterAdapter $web)
    {
        parent::__construct($web);
    }

    // ------------------------------------------------------------ the surfaces

    /**
     * A wp-admin screen, published on `admin_menu`.
     *
     * The path is the menu slug — what appears in `admin.php?page={slug}` — and
     * the rest of what WordPress wants arrives through `->withOptions([...])`.
     *
     * @param string|array{0: string, 1: string}|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    public function get(string $path, string|array|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->surfaceRoute(['GET'], $path, $controller, $action, WordPressRouterAdapter::SURFACE_ADMIN_PAGE);
    }

    /**
     * A form handler, published on `admin_post_{action}`.
     *
     * @param string|array{0: string, 1: string}|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    public function post(string $path, string|array|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->surfaceRoute(['POST'], $path, $controller, $action, WordPressRouterAdapter::SURFACE_ADMIN_ACTION);
    }

    /**
     * A form handler reached with a `_method` field.
     *
     * A browser cannot send PUT, PATCH or DELETE, so these arrive as a POST
     * carrying `<input type="hidden" name="_method" value="DELETE">`. They are
     * form handlers for that reason, not screens.
     *
     * @param string|array{0: string, 1: string}|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    public function put(string $path, string|array|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->surfaceRoute(['PUT'], $path, $controller, $action, WordPressRouterAdapter::SURFACE_ADMIN_ACTION);
    }

    /**
     * @see put()
     *
     * @param string|array{0: string, 1: string}|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    public function patch(string $path, string|array|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->surfaceRoute(['PATCH'], $path, $controller, $action, WordPressRouterAdapter::SURFACE_ADMIN_ACTION);
    }

    /**
     * @see put()
     *
     * @param string|array{0: string, 1: string}|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    public function delete(string $path, string|array|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->surfaceRoute(['DELETE'], $path, $controller, $action, WordPressRouterAdapter::SURFACE_ADMIN_ACTION);
    }

    // -------------------------------------------------- explicit surface names

    /**
     * A front-end page, matched on `template_redirect`.
     *
     * The path is a URL on the site — `thank-you` matches `/thank-you` — and may
     * carry placeholders with the same grammar the REST router uses.
     *
     * **This is how a GET route stops being an admin screen**, which is the one
     * thing the verb cannot express: `get('thank-you', ...)` registers a menu slug
     * called `thank-you`, while `page('thank-you', ...)` serves the URL.
     *
     * @param string|array{0: string, 1: string}|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    public function page(string $path, string|array|callable|null $controller = null, ?string $action = null): Route
    {
        return $this->surfaceRoute(['GET'], $path, $controller, $action, WordPressRouterAdapter::SURFACE_PAGE);
    }

    /**
     * A wp-admin screen, spelled out rather than inferred.
     *
     * Equivalent to {@see get()}; kept because a reader of a routes file should
     * not have to know the inference rules to see what a route is, and because the
     * WordPress-shaped name is what the adapter's own API and the README use.
     *
     * @param string|array{0: string, 1: string}|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    public function adminPage(string $path, string|array|callable|null $controller = null, ?string $action = 'index'): Route
    {
        return $this->get($path, $controller, $action);
    }

    /**
     * A form handler, spelled out rather than inferred.
     *
     * The `$method` argument exists here and not on the verb methods because a
     * form handler's verb is not what identifies it: `adminAction('leads/update',
     * C::class, 'update', 'PUT')` is clearer than reaching for `put()` when the
     * form itself sends POST.
     *
     * @param string|array{0: string, 1: string}|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    public function adminAction(
        string $path,
        string|array|callable|null $controller = null,
        string $action = 'store',
        string $method = 'POST'
    ): Route {
        return $this->surfaceRoute([$method], $path, $controller, $action, WordPressRouterAdapter::SURFACE_ADMIN_ACTION);
    }

    /**
     * Build a route for one WordPress surface.
     *
     * The only place a surface is stamped onto a route, so the table in the class
     * docblock is the single statement of which verb means which mechanism.
     *
     * @param list<string>                                     $methods
     * @param string|array{0: string, 1: string}|callable|null $controller
     *
     * @throws RouteRegistrationException
     */
    private function surfaceRoute(
        array $methods,
        string $path,
        string|array|callable|null $controller,
        ?string $action,
        string $surface
    ): Route {
        return $this->route($methods, $path, $controller, $action)
            ->option(WordPressRouterAdapter::OPTION_SURFACE, $surface);
    }
}
