<?php
declare(strict_types=1);

namespace Framework\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Http\Request;
use Framework\Routing\Exceptions\RouteRegistrationException;
use Framework\Routing\Exceptions\RouteResolutionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use Throwable;

/**
 * Binds a request's values onto a controller action's parameters.
 *
 * ## Why this is shared rather than duplicated per domain
 *
 * The two domains receive their inputs from genuinely different places — a REST
 * request arrives as a `WP_REST_Request` WordPress already parsed, a web request
 * is captured from the superglobals — but everything *after* that point is the
 * same decision: which request values fill which parameters, in what order, and
 * what happens when a value cannot satisfy a declared type.
 *
 * Those decisions are where the sharp edges are. `{"published": "false"}` must
 * not arrive at a `bool $published` parameter as the non-empty string `"false"`,
 * which PHP coerces to `true` and silently publishes the record. A matched route
 * placeholder must outrank a body parameter of the same name, or a client can
 * address one record and act on another. Both of those are properties of *binding*
 * — not of REST, and not of the web — so they live in one place, and a fix to
 * either one fixes both domains rather than half of each.
 *
 * ## How the two domains differ, and how little
 *
 * They differ in exactly two seams, and both are injected:
 *
 *  - **Where the values come from** — {@see ParameterSource}. A REST source reads
 *    through `WP_REST_Request`; an HTTP source reads through `Framework\Http\Request`.
 *  - **Whether the request object itself is injectable** — `$requestClass`. A
 *    controller may type-hint the request it is being handed by declaring that
 *    class, and a non-builtin parameter of that type receives the request rather
 *    than any of its values. It is passed in rather than hard-coded to
 *    `Request::class` because the REST domain dispatches with a `WP_REST_Request`
 *    and must keep being able to hand one over.
 *
 * ## What it does not decide
 *
 * It does not resolve the controller — the caller has already done that, because
 * each adapter resolves through the same container but reports a failure in its
 * own response shape. It does not choose an action. It returns the action's
 * value untouched, so each domain renders it as it must.
 */
final class ParameterBinder
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Call one action on an already-resolved controller.
     *
     * @param class-string $requestClass The type a parameter must declare to
     *                                   receive the request object itself.
     *
     * @return mixed The action's return value.
     *
     * @throws RouteRegistrationException When the action is missing or not public.
     * @throws RouteResolutionException   When a request value cannot satisfy a
     *                                    parameter's declared type.
     */
    public function call(
        object $controller,
        string $action,
        mixed $request,
        ParameterSource $source,
        string $requestClass
    ): mixed {
        $arguments = $this->arguments($controller, $action, $request, $source, $requestClass);

        return $this->container->call([$controller, $action], $arguments);
    }

    /**
     * Invoke any callable, filling its parameters from the request.
     *
     * A closure handler carries no controller, but its parameters deserve the
     * same treatment: `fn (Request $r)` and `fn (int $id)` should work on a
     * closure route exactly as they do on a controller action. Reflection over a
     * closure and over a method differ only in how the parameters are listed.
     *
     * @return mixed
     */
    public function callCallable(
        callable $handler,
        mixed $request,
        ParameterSource $source,
        string $requestClass
    ): mixed {
        $reflection = new \ReflectionFunction(\Closure::fromCallable($handler));
        $arguments = $this->bindParameters(
            $reflection->getParameters(),
            $request,
            $source,
            $requestClass,
            $reflection->getName(),
            'closure'
        );

        return $this->container->call($handler, $arguments);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RouteRegistrationException
     * @throws RouteResolutionException
     */
    private function arguments(
        object $controller,
        string $action,
        mixed $request,
        ParameterSource $source,
        string $requestClass
    ): array {
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

        return $this->bindParameters(
            $method->getParameters(),
            $request,
            $source,
            $requestClass,
            $controller::class,
            $action
        );
    }

    /**
     * @param list<ReflectionParameter> $parameters
     * @return array<string, mixed>
     *
     * @throws RouteResolutionException
     */
    private function bindParameters(
        array $parameters,
        mixed $request,
        ParameterSource $source,
        string $requestClass,
        string $subject,
        string $action
    ): array {
        $arguments = [];

        // Route placeholders are read before anything else. WordPress ranks
        // request sources JSON/body, then query, then URL last — so a POST body
        // of `{"id": 999}` would otherwise fill an `$id` parameter that the URL
        // matched as 123, letting a client address one resource and act on
        // another.
        $routeParameters = $source->routeParameters();

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if (
                $type instanceof ReflectionNamedType
                && !$type->isBuiltin()
                && is_a($request, $type->getName())
            ) {
                $arguments[$name] = $request;

                continue;
            }

            $value = array_key_exists($name, $routeParameters)
                ? $routeParameters[$name]
                : $source->value($name);

            if ($value === null) {
                // Nothing supplied. Leave it out entirely so the container can
                // use the parameter's own default, and so a required scalar is
                // reported by the container as an unresolvable parameter rather
                // than being passed through as null and raising a TypeError
                // inside the controller.
                continue;
            }

            if (!$this->accepts($parameter->getType(), $value)) {
                throw RouteResolutionException::unexpectedArgument($name, $value, $subject, $action);
            }

            $arguments[$name] = $this->normalize($parameter->getType(), $value);
        }

        return $arguments;
    }

    /**
     * Convert a request value into the shape the declared type actually expects.
     *
     * Only `bool` needs this, and it needs it badly. A request body of
     * `{"published": "false"}` reaches a `bool $published` parameter as the
     * non-empty string "false", and PHP coerces a non-empty string to `true` —
     * so the controller publishes the record. `"0"` happens to coerce correctly,
     * which is what makes this treacherous: only some of the false-y spellings
     * invert.
     *
     * `accepts()` already whitelists 'true' and 'false' as valid bool inputs, so
     * the intent to support them is there; the coercion just has to happen before
     * the value reaches reflection, not after.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalize(?ReflectionType $type, mixed $value): mixed
    {
        if (!$type instanceof ReflectionNamedType || !$type->isBuiltin()) {
            return $value;
        }

        if ($type->getName() !== 'bool' || is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            // Matched case-insensitively: `?published=False` is as likely from a
            // hand-built query string as the lowercase form.
            return match (strtolower(trim($value))) {
                'true', '1' => true,
                'false', '0', '' => false,
                default => $value,
            };
        }

        return is_int($value) ? $value !== 0 : $value;
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
            // Case-insensitive, and including the empty string, because these
            // arrive from query strings and form bodies where `?published=FALSE`
            // and `published=` are both ordinary. Every accepted spelling is
            // converted by normalize() before it reaches reflection — a bare
            // in_array() here would have let 'false' through as the *string*
            // "false", which PHP then coerces to true.
            'bool' => self::isBoolLike($value),
            // A PHP array cannot be passed from a URL segment, so an array
            // parameter can only be satisfied by a structured body value.
            'array' => is_array($value),
            default => true,
        };
    }

    /**
     * Whether a value is one of the spellings a `bool` parameter accepts.
     *
     * @param mixed $value
     */
    private static function isBoolLike(mixed $value): bool
    {
        if (is_bool($value) || $value === 0 || $value === 1) {
            return true;
        }

        return is_string($value)
            && in_array(strtolower(trim($value)), ['true', 'false', '0', '1', ''], true);
    }

    /**
     * Invoke a permission callback by name.
     *
     * Delegates to the container, so the same three forms it accepts for any
     * callback apply here: a plain function name (`my_check`), a static method
     * (`Checks::canEdit`), or an instance method on a resolvable class
     * (`Checks@canEdit`). A bare method name such as `canEdit` is not among them —
     * nothing associates it with the route's controller, so it is reflected as a
     * plain function and does not resolve.
     *
     * The request is supplied by name, matching the container's
     * `$parameter => $value` override shape; a callback that wants a route
     * placeholder instead reads it off the request it is given.
     *
     * @return mixed
     */
    public function callPermission(string $callback, mixed $request): mixed
    {
        try {
            return $this->container->call($callback, ['request' => $request]);
        } catch (Throwable $e) {
            // Re-thrown so the caller can report it in the response shape its own
            // domain renders — a REST denial is a WP_Error, a web one is a page.
            // Caught and re-thrown rather than left alone only to add the
            // context the container's message lacks.
            throw new RouteResolutionException(sprintf(
                'Could not run permission callback [%s]: %s',
                $callback,
                $e->getMessage()
            ), 0, $e);
        }
    }
}
