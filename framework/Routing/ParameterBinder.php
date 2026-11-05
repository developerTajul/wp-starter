<?php
declare(strict_types=1);

namespace Framework\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;
use Framework\Http\Requests\FormRequest;
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
 *
 * ## A `FormRequest` parameter short-circuits the call
 *
 * A parameter typed as a {@see FormRequest} subclass is built around the request
 * and validated *before* the action runs. When validation fails the action is
 * never entered, and the form request's own failure response — a redirect for a
 * browser, a `422` for a JSON client — is returned in place of the action's
 * value.
 *
 * This is the one place a controller's contract can be enforced without the
 * author's cooperation, and it is deliberately here rather than in a middleware
 * or the controller: the type-hint is the declaration, and the binder is the only
 * code that reads it before the call.
 *
 * The returned response is a {@see ResponseInterface}. Every domain can render
 * one — the web adapter sends it as-is — and a domain that cannot translate it
 * yet reports that rather than swallowing it, so a half-wired surface is loud.
 *
 * A failure short-circuits the *call*, not the *binding*. Every parameter is
 * still bound — and a parameter no request value can satisfy still raises — so
 * the two defects stay distinguishable: a rejected form is something the user
 * can fix, and an unbindable parameter is something only the route author can.
 * Binding the rest also keeps the result independent of where in the signature
 * the form request sits.
 */
final class ParameterBinder
{
    /**
     * The response a failing `FormRequest` produced, if any.
     *
     * Held rather than thrown because the framework has no global exception
     * handler to catch a `ValidationException` from — see {@see FormRequest} for
     * the full reasoning. The value travels back through `call()`'s return, which
     * is the same channel an action's response uses, so every caller already
     * handles it.
     *
     * Widened past {@see ResponseInterface} because a REST route's failure is a
     * `WP_Error` — the only shape WordPress's REST server renders. The binder
     * does not care which it is; it hands the value back untouched, exactly as it
     * does with an action's own return value.
     *
     * ## Why it is scoped to one dispatch and not merely cleared
     *
     * This is mutable state on a collaborator that outlives the request: one
     * binder is held by a resolver, and the resolver by an adapter, for the
     * lifetime of the process. Any path that *arms* this and then leaves without
     * consuming it — a handler that throws, an adapter that discards the
     * arguments, a future entry point that calls {@see arguments()} directly —
     * leaves a failure response pointing at the request that is already over. The
     * next dispatch on the same worker would then return that stale redirect (or
     * `WP_Error`) instead of running its action, which reads as an intermittent
     * authorization bug under a persistent process and never reproduces locally.
     *
     * Clearing it in the two places that consume it is not enough, because
     * "the places that consume it" is exactly the set that grows. So the state is
     * never allowed to outlive a dispatch: {@see armed()} resets it on entry and
     * {@see disarm()} resets it in a `finally`, and neither the arming site nor
     * the consuming site has to remember anything.
     *
     * @var ResponseInterface|\WP_Error|null
     */
    private ResponseInterface|\WP_Error|null $shortCircuit = null;

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Call one action on an already-resolved controller.
     *
     * Returns the action's value, or — when a `FormRequest` parameter it declared
     * failed validation — that request's failure response, with the action never
     * invoked.
     *
     * @param class-string $requestClass The type a parameter must declare to
     *                                   receive the request object itself.
     *
     * @return mixed The action's return value, or a failure response.
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
        // Scoped to this one dispatch — see $shortCircuit. The reset on entry is
        // what makes a leak *impossible* rather than merely unlikely: even if
        // some future path left the property armed, the next dispatch clears it
        // before it can be read.
        $this->shortCircuit = null;

        try {
            $arguments = $this->arguments($controller, $action, $request, $source, $requestClass);

            // Validation refused the request. The action is not called at all —
            // that is the whole contract of a FormRequest, and calling through
            // anyway to let the controller "decide" is how a half-validated
            // record gets saved.
            if ($this->shortCircuit !== null) {
                return $this->shortCircuit;
            }

            return $this->container->call([$controller, $action], $arguments);
        } finally {
            // Unconditional, including on a throw: this is the half of the fix
            // that covers the paths nobody has written yet.
            $this->shortCircuit = null;
        }
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
        // Scoped to this one dispatch, exactly as call() does — see $shortCircuit.
        $this->shortCircuit = null;

        try {
            $reflection = new \ReflectionFunction(\Closure::fromCallable($handler));
            $arguments = $this->bindParameters(
                $reflection->getParameters(),
                $request,
                $source,
                $requestClass,
                $reflection->getName(),
                'closure'
            );

            if ($this->shortCircuit !== null) {
                return $this->shortCircuit;
            }

            return $this->container->call($handler, $arguments);
        } finally {
            $this->shortCircuit = null;
        }
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

            // A FormRequest type-hint is built around the request and validated
            // here, before anything else reads a parameter. Checked ahead of the
            // value lookup because a FormRequest is not a *source* of values —
            // it is a gate in front of the action.
            if ($this->isFormRequest($type)) {
                /** @var class-string $formClass */
                $formClass = $type->getName();
                $form = $this->bindFormRequest($formClass, $request);

                if ($form === null) {
                    // Validation failed and its response has been recorded. The
                    // action will not be called, but the loop keeps going rather
                    // than returning here, and the reason is the same one that
                    // makes every parameter get bound in the success case: a
                    // parameter whose type no request value can satisfy is a
                    // *route* defect, and it must be reported as one whether or
                    // not the form happened to pass.
                    //
                    // Returning early hid that defect behind the validation
                    // failure — fix the form, and the `TypeError` appears — and it
                    // made binding depend on parameter order, because a parameter
                    // declared *before* the form was bound and then discarded.
                    continue;
                }

                $arguments[$name] = $form;

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
     * Whether a declared type is a FormRequest subclass.
     *
     * `is_subclass_of` rather than `is_a`, because the type is a *name* here and
     * `is_a` with a string is happy to match the abstract base itself — a
     * parameter typed as `FormRequest` directly could never be built (it is
     * abstract, and has no `rules()`), so it is left to fail where it is
     * resolved, with the container's own message about an abstract class.
     *
     * `class_exists` guards the reflection: a type name that no autoloader can
     * resolve is left alone rather than reported, because the container's
     * failure names the missing class, which is the more useful message.
     */
    private function isFormRequest(?ReflectionType $type): bool
    {
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return false;
        }

        $name = $type->getName();

        return class_exists($name) && is_subclass_of($name, FormRequest::class);
    }

    /**
     * Build and validate a FormRequest, recording its failure response.
     *
     * Returns null when validation failed — the failure response has been
     * recorded on {@see $shortCircuit}, the action will not be called, and this
     * parameter is left out of the argument list. The caller keeps binding the
     * remaining parameters. Returns the validated request otherwise.
     *
     * The request handed to the form request is the domain's own request object,
     * which is why this takes `mixed`: a REST dispatch passes the framework
     * `Request` built by {@see \Framework\Http\Request::fromRestRequest()}, and a
     * web dispatch passes the captured one. Both are `Framework\Http\Request`,
     * which is what {@see FormRequest::fromRequest()} declares — and the `mixed`
     * here is the same seam `call()` already has for that reason.
     *
     * @param class-string $class
     */
    private function bindFormRequest(string $class, mixed $request): ?FormRequest
    {
        // A REST dispatch hands the binder a `WP_REST_Request`, because that is
        // what a REST controller action's own parameters read — but a
        // `FormRequest` validates a framework `Request`, which is the only object
        // that merges the sources and decodes a JSON body. Translating here rather
        // than in the adapter keeps the rule in one place: a form request works on
        // either domain because the binder normalizes what it is given.
        if (!$request instanceof Request) {
            $request = $this->toHttpRequest($request);
        }

        if (!$request instanceof Request) {
            // A hand-wired caller passing something neither shape. Refusing is
            // better than validating an empty payload and reporting every field as
            // missing, which reads as "the form rejects everything".
            throw new RouteResolutionException(sprintf(
                'A FormRequest-typed parameter needs a %s or a WP_REST_Request to validate '
                . 'against, but the dispatch supplied [%s].',
                Request::class,
                get_debug_type($request)
            ));
        }

        /** @var FormRequest $form */
        $form = $class::fromRequest($request);
        $form->validate();

        if ($form->fails()) {
            $this->shortCircuit = $form->failureResponse();

            return null;
        }

        return $form;
    }

    /**
     * Build a framework Request from a REST request, when one was supplied.
     *
     * Duck-typed on `fromRestRequest()` rather than type-hinted against
     * `WP_REST_Request`, for the same reason {@see Request::fromRestRequest()}
     * is: the framework has to load and be testable without WordPress, and a
     * hard reference to a WordPress class in a signature would make this file
     * unloadable outside a WordPress install.
     *
     * Returns null when the value is neither shape, so the caller reports it.
     */
    private function toHttpRequest(mixed $request): ?Request
    {
        if (!is_object($request) || !method_exists($request, 'get_params')) {
            return null;
        }

        return Request::fromRestRequest($request);
    }

    /**
     * Convert a request value into the shape the declared type actually expects.
     *
     * Only `bool` needs this, and it needs it badly. A request body of
     * `{"published": "false"}` reaches a `bool $published` parameter as the
     * non-empty string `"false"`, and PHP coerces a non-empty string to `true` —
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
