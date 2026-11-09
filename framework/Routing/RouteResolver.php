<?php
declare(strict_types=1);

namespace Framework\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Http\Request;
use Framework\Routing\Exceptions\RouteRegistrationException;
use Framework\Routing\Sources\RestParameterSource;
use WP_REST_Request;

/**
 * Invokes a controller action, supplying its parameters.
 *
 * The argument-filling logic lives in {@see ParameterBinder}, because the web
 * router needs exactly the same behaviour against a different request type.
 * This class is the REST-shaped entry point: it keeps the `WP_REST_Request`
 * signatures that {@see Router}, {@see \Framework\Routing\Adapters\RestApiRouterAdapter}
 * and their tests already call, and supplies the REST seams to the binder.
 *
 * Nothing here is REST-specific except the type it accepts and the parameter
 * source it builds. A web route goes through the binder directly - see
 * {@see \Framework\Routing\Adapters\WordPressRouterAdapter} - rather than being
 * funnelled through a signature that would have to pretend to take a
 * `WP_REST_Request`.
 */
class RouteResolver
{
    private readonly ParameterBinder $binder;

    public function __construct(private readonly Container $container)
    {
        $this->binder = new ParameterBinder($container);
    }

    /**
     * @return mixed The action's return value.
     *
     * @throws RouteRegistrationException When the action does not exist.
     */
    public function call(object $controller, string $action, WP_REST_Request $request): mixed
    {
        return $this->binder->call(
            $controller,
            $action,
            $request,
            new RestParameterSource($request),
            WP_REST_Request::class
        );
    }

    /**
     * Invoke a closure handler with its parameters filled from the request.
     *
     * A closure route is not a controller, but its parameters are written the
     * same way and must be filled the same way - `fn (WP_REST_Request $r)` and
     * `fn (int $id)` both work on a closure route, and byte-for-byte the same
     * rules decide what each receives.
     *
     * @return mixed
     */
    public function callHandler(callable $handler, WP_REST_Request $request): mixed
    {
        return $this->binder->callCallable(
            $handler,
            $request,
            new RestParameterSource($request),
            WP_REST_Request::class
        );
    }

    /**
     * Call an action with an HTTP request, for the web domain.
     *
     * Present so the web adapter has a resolver-shaped entry point too, and so
     * the `Request` type appearing here is what a caller reads to know this is
     * the web path - the REST signatures above are untouched.
     *
     * @return mixed
     */
    public function callWithHttpRequest(
        object $controller,
        string $action,
        Request $request,
        array $routeParameters = []
    ): mixed {
        return $this->binder->call(
            $controller,
            $action,
            $request,
            new \Framework\Routing\Sources\HttpParameterSource($request, $routeParameters),
            Request::class
        );
    }

    /**
     * Invoke a permission callback by name.
     *
     * Delegates to the container, so the same three forms it accepts for any
     * callback apply here: a plain function name (`my_check`), a static method
     * (`Checks::canEdit`), or an instance method on a resolvable class
     * (`Checks@canEdit`). A bare method name such as `canEdit` is not among them -
     * nothing associates it with the route's controller, so it is reflected as a
     * plain function and does not resolve.
     *
     * @return mixed
     */
    public function callPermission(string $callback, WP_REST_Request $request): mixed
    {
        return $this->binder->callPermission($callback, $request);
    }

    /**
     * The shared binder, for an adapter that needs it directly.
     */
    public function binder(): ParameterBinder
    {
        return $this->binder;
    }
}
