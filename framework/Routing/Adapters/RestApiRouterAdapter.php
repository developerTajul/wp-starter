<?php
declare(strict_types=1);

namespace Framework\Routing\Adapters;

use Framework\Http\Request;
use Framework\Http\Requests\FormRequest;
use Framework\Http\Responses\RedirectResponse;
use Framework\Routing\Contracts\Middleware;
use Framework\Routing\Exceptions\RouteRegistrationException;
use Framework\Routing\Route;
use Framework\Routing\RouteCompiler;
use Framework\Routing\RouteDomain;
use Throwable;
use WP_Error;
use WP_REST_Request;

/**
 * Publishes routes as WordPress REST endpoints.
 *
 * Maps the fluent route table onto `rest_api_init` + `register_rest_route()`,
 * which is the one WordPress routing mechanism that already has a real
 * contract — a namespace, a regex path, a callback and a permission callback.
 * That makes this adapter the thin one: it translates and delegates, and the
 * interesting logic lives in the shared base.
 *
 * ## What it adds over {@see \Framework\Routing\Router}
 *
 * Functionally, nothing — this is the same behaviour behind the uniform
 * {@see \Framework\Routing\Contracts\RouterInterface}. The difference is that a
 * project can now hold one of these and a {@see WordPressRouterAdapter} behind
 * the same type, list them together, and reach them through the container by a
 * predictable name. `Router` remains for the projects already using it.
 *
 * ## Authorization is WordPress's, not the pipeline's
 *
 * The `permission_callback` is the only seam WordPress offers on a REST route,
 * so a route's middleware runs *there* — as a yes/no check that may deny — and
 * not through {@see \Framework\Http\Middleware\Pipeline}. The two middleware
 * contracts are deliberately not merged; see
 * {@see \Framework\Routing\Contracts\Middleware} for why. A web route, whose
 * responses the application owns, is where the pipeline is used instead.
 */
final class RestApiRouterAdapter extends AbstractRouterAdapter
{
    /**
     * An explicit namespace, when the caller supplied one.
     *
     * @var string|null
     */
    private ?string $namespace = null;

    public function domain(): string
    {
        return RouteDomain::Api->value;
    }

    /**
     * The REST namespace these routes are published under.
     *
     * Defaults to `app.rest_namespace`, read at *publish* time rather than captured
     * in the constructor — so a project that sets the config after registering the
     * adapter still gets the value it set, and a test does not have to mutate a
     * global store to control it.
     *
     * {@see publishUnder()} is the explicit override, which exists because a
     * namespace is a property of the route table rather than of the process: two
     * adapters in one test — or a plugin publishing a second API surface — need
     * to be able to say which namespace theirs is without editing global config.
     */
    public function namespace(): string
    {
        if ($this->namespace !== null) {
            return $this->namespace;
        }

        return (string) config('app.rest_namespace', 'wp-mini-laravel/v1');
    }

    /**
     * Publish under an explicit namespace, ignoring config.
     *
     * Returns `$this` so it composes with construction:
     *
     *     $adapter->publishUnder('my-plugin/v2')->flush();
     */
    public function publishUnder(string $namespace): self
    {
        $this->namespace = $namespace;

        return $this;
    }

    protected function publish(): void
    {
        if (!function_exists('register_rest_route')) {
            return;
        }

        $ordered = RouteCompiler::sortBySpecificity($this->routes);

        // Validated in full before anything is handed to WordPress. Registration
        // is the last moment a route can still be fixed, so a handler that never
        // arrived is reported here — naming the path, which is what a developer
        // needs to find the mistake.
        //
        // Validating up front rather than inside the loop is what keeps a single
        // bad route from leaving the set half-registered: WordPress has already
        // accepted the earlier routes by then, and every later route would be
        // lost with nothing to retry against.
        foreach ($ordered as $route) {
            if ($route->getController() === null && $route->getHandler() === null) {
                throw RouteRegistrationException::missingHandler($route->getDisplayPath());
            }
        }

        foreach ($ordered as $route) {
            register_rest_route($this->namespace(), RouteCompiler::toPattern($route->getPath()), [
                'methods' => $route->getMethods(),
                'callback' => fn (WP_REST_Request $request) => $this->dispatchRest($route, $request),
                'permission_callback' => $this->buildPermissionCallback($route),
            ]);
        }
    }

    /**
     * Dispatch a REST request, binding the framework Request for its duration.
     *
     * `Request::fromRestRequest()` is what makes a controller type-hinting the
     * framework's `Request` receive the request WordPress actually dispatched —
     * with its URL-matched parameters and a body decoded through WordPress's own
     * parameter precedence — rather than the superglobal capture taken at plugin
     * load, which carries neither.
     *
     * @return mixed
     */
    private function dispatchRest(Route $route, WP_REST_Request $request): mixed
    {
        $this->pinRouteParameters($route, $request);

        // Built once and reused for both the binding and the dispatch. Building
        // it twice would hand the controller one object and leave a *different*
        // one in the container, so a controller that read the bound Request — or
        // a constructor that type-hinted it — would see a second, independently
        // decoded copy of the same request. They agree today; the point is that
        // nothing would report it if they stopped.
        $httpRequest = Request::fromRestRequest($request);

        // Bound before the controller is resolved, because a constructor may
        // type-hint the framework Request too — resolving first and binding
        // afterwards would leave the constructor holding a stale capture.
        return $this->withHttpRequest($httpRequest, function () use ($route, $request, $httpRequest): mixed {
            return $this->withRestMarker(function () use ($route, $request, $httpRequest): mixed {
                return $this->dispatch(
                    $route,
                    $httpRequest,
                    fn (): mixed => $this->destination($route, $request)
                );
            });
        });
    }

    /**
     * Mark the dispatch as REST for the duration of the call.
     *
     * A failing {@see \Framework\Http\Requests\FormRequest} has to answer as a
     * `WP_Error` here rather than as a framework response, because the REST
     * server serializes whatever a callback returns and does not send framework
     * responses. Nothing about the request itself says which domain it is on —
     * both paths build the same `Request` — so the domain is stated here, by the
     * code that knows it.
     *
     * Restored in a `finally` rather than reset afterwards, so a controller that
     * throws still leaves the flag clear. A stuck flag would make the *next*
     * dispatch on this worker answer with a `WP_Error` on a web route, which is
     * exactly the kind of bug that only appears under a persistent process.
     *
     * @param callable(): mixed $callback
     *
     * @return mixed
     */
    private function withRestMarker(callable $callback): mixed
    {
        $previous = FormRequest::$restDispatch;
        FormRequest::$restDispatch = true;

        try {
            return $callback();
        } finally {
            FormRequest::$restDispatch = $previous;
        }
    }

    /**
     * Invoke the route's controller or handler.
     *
     * ## Why this calls the resolver directly and not `callController()`
     *
     * `AbstractRouterAdapter::callController()` takes a `Framework\Http\Request`
     * because the *web* adapter hands it one — that class is shared between the
     * two domains, so its signature had to pick a request type, and it picked the
     * web one.
     *
     * This path holds a `WP_REST_Request`. Passing it to `callController()` was a
     * `TypeError` at the moment any REST controller route was reached, and it went
     * unnoticed because the adapter only dispatches routes registered *into it*,
     * and this project's REST surface is still owned by the legacy
     * {@see \Framework\Routing\Router}. {@see RouteResolver::call()} is the
     * REST-shaped entry point and is what the legacy router has always used, so
     * both now go through the same binder with the same rules.
     */
    private function destination(Route $route, WP_REST_Request $request): mixed
    {
        $controller = $route->getController();

        if ($controller !== null) {
            try {
                $instance = $this->container->resolve($controller);
            } catch (Throwable $e) {
                // Reported in the domain's own shape rather than thrown: an
                // escaping throwable here is an opaque 500 out of a REST callback
                // with nothing naming the class that could not be built.
                return new WP_Error(
                    RedirectResponse::resolvePrefix() . 'controller_failed',
                    sprintf(
                        'Could not build controller [%s]: %s',
                        $controller,
                        $e->getMessage()
                    ),
                    ['status' => 500]
                );
            }

            return $this->resolver->call($instance, $route->getAction() ?? 'index', $request);
        }

        $handler = $route->getHandler();

        if ($handler === null) {
            return new WP_Error(
                RedirectResponse::resolvePrefix() . 'no_handler',
                __('This route has no handler.', 'wp-mini-laravel'),
                ['status' => 500]
            );
        }

        return $this->resolver->callHandler($handler, $request);
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
     * Report a route failure as a WP_Error, which is what WordPress renders for
     * a REST route. A thrown exception here would escape the REST callback as an
     * unhandled throwable and surface as an opaque 500 naming nothing.
     *
     * @return mixed
     */
    protected function failed(Route $route, string $message): mixed
    {
        return new WP_Error(
            RedirectResponse::resolvePrefix() . 'route_failed',
            sprintf('Route [%s] failed: %s', $route->getDisplayPath(), $message),
            ['status' => 400]
        );
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
                $result = $this->runMiddleware($route, $middlewareClass, $request);

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
     * `call()` understands: a plain function name, a static method
     * (`MyChecks::canEdit`), or an instance method on a resolvable class
     * (`MyChecks@canEdit`). A bare method name such as `canEdit` is **not** one
     * of them: nothing ties it to the route's controller, so the container
     * reflects it as a plain function, does not find one, and throws.
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
                RedirectResponse::resolvePrefix() . 'permission_callback_failed',
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
    private function runMiddleware(Route $route, string $middlewareClass, WP_REST_Request $request): ?WP_Error
    {
        try {
            /** @var Middleware $middleware */
            $middleware = $this->container->resolve($middlewareClass);
            $result = $middleware->handle($request);
        } catch (Throwable $e) {
            return new WP_Error(
                RedirectResponse::resolvePrefix() . 'middleware_failed',
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
                RedirectResponse::resolvePrefix() . 'middleware_contract',
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
}
