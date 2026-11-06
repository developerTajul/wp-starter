<?php
declare(strict_types=1);

namespace Framework\Routing\Exceptions;

use Framework\Routing\RouteRegistrar;
use RuntimeException;

/**
 * Raised when a route registration is structurally invalid — an empty path, an
 * unsupported HTTP method, a handler that is neither a controller action nor a
 * callable, or a conflicting duplicate.
 *
 * Registration is a programming error, so it fails loudly during development
 * rather than producing an endpoint that silently never matches.
 */
final class RouteRegistrationException extends RuntimeException
{
    /**
     * @param string $method
     */
    public static function unsupportedMethod(string $method): self
    {
        return new self(sprintf(
            'Unsupported HTTP method [%s]. Supported methods: %s.',
            $method,
            implode(', ', RouteRegistrar::SUPPORTED_METHODS)
        ));
    }

    public static function duplicate(string $method, string $path): self
    {
        return new self(sprintf(
            'A [%s] route is already registered for [%s].',
            $method,
            $path
        ));
    }

    public static function missingHandler(string $path): self
    {
        return new self(sprintf(
            'Route [%s] has no handler. Call uses() or handle() on it.',
            $path
        ));
    }

    /**
     * The action argument was not one of the accepted shapes.
     *
     * Reachable only for an array that is not a `[Controller::class, 'method']`
     * pair — everything else is caught by the parameter type. A three-element
     * array and a string-keyed array are the shapes this exists for, and both
     * would otherwise be accepted at registration and fail at dispatch, against a
     * request that names no route.
     *
     * @param string $type The `get_debug_type()` of what was passed.
     */
    public static function invalidAction(string $type): self
    {
        return new self(sprintf(
            'A route action must be a controller class name, a [Controller::class, \'method\'] pair, '
            . 'or a callable. Got [%s].',
            $type
        ));
    }

    /**
     * Two form handlers derived the same `admin_post_{action}` name.
     *
     * Raised rather than resolved because there is no safe way to pick: both
     * routes are published on one hook, `add_action()` does not replace an
     * existing callback, and so *both* would run on one submission — leaving the
     * second controller to act on a request the first was written for. The
     * symptom would be a handler that appears to work while doing the wrong
     * thing, which is worse than a refusal naming both routes.
     */
    public static function duplicateAction(string $action, string $first, string $second): self
    {
        return new self(sprintf(
            'Routes [%s] and [%s] both publish the form action [%s]. Give one of them a '
            . 'distinct path or name with ->name(), or set the [action] option on it explicitly.',
            $first,
            $second,
            $action
        ));
    }
}
