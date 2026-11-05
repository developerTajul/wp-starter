<?php
declare(strict_types=1);

namespace Framework\Routing\Exceptions;

use RuntimeException;

/**
 * Raised when a request carries a value that cannot satisfy the controller
 * action's declared parameter type.
 *
 * The alternative is letting the value through and having PHP raise a TypeError
 * from inside the controller, where the message names the parameter but not the
 * route that supplied it.
 */
final class RouteResolutionException extends RuntimeException
{
    /**
     * @param mixed $value
     */
    public static function unexpectedArgument(
        string $parameter,
        mixed $value,
        string $controller,
        string $action
    ): self {
        return new self(sprintf(
            'Parameter [$%s] of [%s::%s()] received a value of type [%s] that its declared type cannot accept.',
            $parameter,
            $controller,
            $action,
            get_debug_type($value)
        ));
    }
}
