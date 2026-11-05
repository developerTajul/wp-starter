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
}
