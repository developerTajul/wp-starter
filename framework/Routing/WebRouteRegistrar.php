<?php
declare(strict_types=1);

namespace Framework\Routing;

use Framework\Routing\Adapters\WordPressRouterAdapter;
use Framework\Routing\Exceptions\RouteRegistrationException;

/**
 * Registrar handed to `routes/web.php`. A web route's mechanism is not implied by
 * its path (`leads` could be an admin screen or a front-end page), so the surface
 * is decided from the verb:
 *
 * `get()` -> `admin_menu`; `post()` and `put`/`patch`/`delete` (via `_method`) ->
 * `admin_post_{action}`; `page()` -> `template_redirect`. A bare GET is an admin
 * screen because WordPress has no general front-end router, so a front-end page
 * has to be asked for by name. The inverse inference was the older behaviour: the
 * majority of GET routes in a WordPress plugin are admin screens, and a slug that
 * silently becomes an unreachable front-end URL is a worse failure than one that
 * has to say `page()`. The explicit helpers `adminPage()`, `adminAction()` and
 * `page()` remain for what the verb cannot express.
 *
 * Grouping, the two action spellings and the fluent chain come from
 * {@see RouteRegistrar}; every spelling funnels through {@see RouteRegistrar::route()}.
 *
 * `RouteLoader` hands a routes file a registrar rather than the router, which is
 * what gives the file `group()` and makes `web.php` and `api.php` the same shape
 * over two mechanisms. A plain registrar has no notion of a surface, so a web
 * routes file typed against the adapter died on a `TypeError` as it loaded and the
 * whole admin surface silently failed to register. This class is that seam.
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
     * The path is the menu slug - what appears in `admin.php?page={slug}` - and
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
     * The path is a URL on the site - `thank-you` matches `/thank-you` - and may
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
