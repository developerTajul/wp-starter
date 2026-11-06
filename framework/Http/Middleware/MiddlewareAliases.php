<?php
declare(strict_types=1);

namespace Framework\Http\Middleware;

use InvalidArgumentException;

/**
 * Short names for middleware classes, so a route can say `'capability'` instead
 * of a fully-qualified class name.
 *
 *     ->middleware(['capability:manage_options', 'nonce:leads_store'])
 *
 * instead of
 *
 *     ->middleware([
 *         CheckCapability::class . ':manage_options',
 *         VerifyNonce::class . ':leads_store',
 *     ])
 *
 * The second is not wrong — a class name is a perfectly good stack entry and
 * always will be — but it is 40 characters of namespace before the reader
 * reaches the part that differs between two routes, and a `use` statement that
 * exists only to build a string. An alias is what makes the routes file read as
 * policy rather than as wiring.
 *
 * ## Aliases are a convenience, and the class name always wins
 *
 * An alias never *replaces* a class name. {@see resolve()} is applied to an entry
 * only when the entry is not already a loadable class, so:
 *
 *  - a real class name passes through untouched, alias or no alias;
 *  - an alias resolves to this framework's class;
 *  - an unknown name is resolved to itself and fails later, at dispatch, naming
 *    the entry — rather than being reported here, where there is no route to
 *    name and no way to tell a typo from a project's own un-aliased middleware.
 *
 * That ordering matters for a framework that ships to other projects: a project
 * may define its own `CheckCapability` class, and a registry that rewrote the
 * name would quietly substitute the framework's. Class names are checked first so
 * that can never happen.
 *
 * ## The default set
 *
 * The framework's own parameterized middleware is aliased out of the box. A
 * project adds its own by binding this class and calling {@see alias()} — most
 * naturally in a service provider's `register()`:
 *
 *     $aliases = $container->resolve(MiddlewareAliases::class);
 *     $aliases->alias('lead-access', RequireLeadAccess::class);
 *
 * A registry instance is shared, so an alias added at boot is visible to every
 * route dispatched later in the process.
 */
final class MiddlewareAliases
{
    /**
     * Alias => class name.
     *
     * @var array<string, class-string<\Framework\Http\Contracts\Middleware>>
     */
    private array $aliases = [
        'capability' => CheckCapability::class,
        'nonce' => VerifyNonce::class,
        'auth' => Authenticate::class,
        'can' => Authorize::class,
        'trim' => TrimStrings::class,
        'null' => ConvertEmptyStringsToNull::class,
        'sanitize' => SanitizeInput::class,
    ];

    /**
     * Register an alias, replacing any previous entry under the same name.
     *
     * Replacing rather than refusing: a project overriding `'capability'` to
     * point at its own class is a legitimate thing to want, and the alternative —
     * throwing — would make the framework's defaults unoverridable. Silent
     * replacement is the same contract the container's `instance()` and `bind()`
     * already have, so nothing here is surprising to a reader who knows those.
     *
     * @param class-string<\Framework\Http\Contracts\Middleware> $middleware
     */
    public function alias(string $name, string $middleware): void
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('A middleware alias cannot be empty.');
        }

        // A colon cannot appear in an alias, because the colon is what separates
        // an entry's class from its parameters: an alias containing one would be
        // indistinguishable from `'alias:parameter'` and could never be addressed.
        if (str_contains($name, ':')) {
            throw new InvalidArgumentException(sprintf(
                'A middleware alias cannot contain a colon, because the colon separates '
                . 'a middleware from its parameters. Got [%s].',
                $name
            ));
        }

        $this->aliases[$name] = $middleware;
    }

    /**
     * Register several aliases at once.
     *
     * @param array<string, class-string<\Framework\Http\Contracts\Middleware>> $aliases
     */
    public function aliasMany(array $aliases): void
    {
        foreach ($aliases as $name => $middleware) {
            $this->alias($name, $middleware);
        }
    }

    /**
     * The class name for a stack entry, resolving an alias when there is one.
     *
     * Order is loadable-class-first, then alias, then the entry unchanged — see
     * the class docblock for why that order is not arbitrary.
     */
    public function resolve(string $entry): string
    {
        if (class_exists($entry) || interface_exists($entry)) {
            return $entry;
        }

        return $this->aliases[$entry] ?? $entry;
    }

    /**
     * Whether the name is a registered alias.
     */
    public function has(string $name): bool
    {
        return isset($this->aliases[$name]);
    }

    /**
     * Every alias, name => class.
     *
     * @return array<string, class-string<\Framework\Http\Contracts\Middleware>>
     */
    public function all(): array
    {
        return $this->aliases;
    }
}
