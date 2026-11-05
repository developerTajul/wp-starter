<?php
declare(strict_types=1);

namespace Framework\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Routing\Exceptions\RouteRegistrationException;
use Framework\Routing\Exceptions\RouteResolutionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use WP_REST_Request;

/**
 * Invokes a controller action, supplying its parameters.
 *
 * Two sources feed one argument list, resolved by parameter *name*:
 *
 *  1. The {@see WP_REST_Request} itself, for any parameter typed as such.
 *  2. Anything the request carries under that name — a route placeholder such as
 *     `{id}`, or a body/query parameter — which is how a controller receives the
 *     data WordPress matched for it.
 *
 * Whatever is left over is handed to the container, which auto-wires it by type.
 * That ordering is deliberate: a route parameter must be able to win over a
 * container binding, otherwise `show(int $id)` could never receive the `id`.
 */
class RouteResolver
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return mixed The action's return value.
     *
     * @throws RouteRegistrationException When the action does not exist.
     */
    public function call(object $controller, string $action, WP_REST_Request $request): mixed
    {
        if (!method_exists($controller, $action)) {
            throw new RouteRegistrationException(sprintf(
                'Method [%s::%s()] does not exist.',
                $controller::class,
                $action
            ));
        }

        $method = new ReflectionMethod($controller, $action);

        // A non-public action is a route-definition mistake, and letting the
        // container fail with a reflection error would hide which route caused it.
        if (!$method->isPublic()) {
            throw new RouteRegistrationException(sprintf(
                'Method [%s::%s()] must be public to be used as a route action.',
                $controller::class,
                $action
            ));
        }

        $arguments = [];

        // Route placeholders are read before anything else. WordPress ranks
        // request sources JSON/body, then query, then URL last — so a POST body
        // of `{"id": 999}` would otherwise fill an `$id` parameter that the URL
        // matched as 123, letting a client address one resource and act on
        // another.
        $routeParameters = $request->get_url_params();

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && is_a($request, $type->getName())) {
                $arguments[$name] = $request;

                continue;
            }

            $value = array_key_exists($name, $routeParameters)
                ? $routeParameters[$name]
                : ($request->has_param($name) ? $request->get_param($name) : null);

            if ($value === null) {
                // Nothing supplied. Leave it out entirely so the container can
                // use the parameter's own default, and so a required scalar is
                // reported by the container as an unresolvable parameter rather
                // than being passed through as null and raising a TypeError
                // inside the controller.
                continue;
            }

            if (!$this->accepts($parameter->getType(), $value)) {
                throw RouteResolutionException::unexpectedArgument($name, $value, $controller::class, $action);
            }

            $arguments[$name] = $value;
        }

        return $this->container->call([$controller, $action], $arguments);
    }

    /**
     * Whether a supplied value can satisfy a parameter's declared type.
     *
     * Only the cases the container would otherwise reject are checked: a value
     * whose type does not match is diagnosed here, where the controller action
     * is still known, instead of surfacing as a TypeError inside the action or
     * as an opaque container failure.
     *
     * @param mixed $value
     */
    private function accepts(?ReflectionType $type, mixed $value): bool
    {
        if (!$type instanceof ReflectionNamedType || !$type->isBuiltin()) {
            return true;
        }

        return match ($type->getName()) {
            'string' => is_scalar($value) || $value instanceof \Stringable,
            'int' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'float' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'bool' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true),
            // A PHP array cannot be passed from a URL segment, so an array
            // parameter can only be satisfied by a structured body value.
            'array' => is_array($value),
            default => true,
        };
    }

    /**
     * Invoke a permission callback, which WordPress may define either as a
     * method name on the controller or as a plain function name.
     *
     * @return mixed
     */
    public function callPermission(string $callback, WP_REST_Request $request): mixed
    {
        return $this->container->call($callback, ['request' => $request]);
    }
}
