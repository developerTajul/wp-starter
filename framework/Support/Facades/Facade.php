<?php
declare(strict_types=1);

namespace Framework\Support\Facades;

use DevLinkStudios\WPServiceContainer\Container;
use RuntimeException;

/**
 * A static proxy onto one service in the container.
 *
 * Laravel's facades, with the two pieces of machinery this framework actually
 * needs and none of the rest: a resolving class name, and a swappable root.
 *
 *     Route::get('leads', [LeadController::class, 'index']);
 *
 * ## Why a subclass and not one facade class per service
 *
 * The proxying is identical for every facade — resolve, forward, repeat. What
 * differs is one string. So {@see getFacadeAccessor()} is the only method a
 * subclass has to write, and a facade is four lines.
 *
 * ## The root is resolved per call, not cached
 *
 * `resolveFacadeInstance()` deliberately does not memoise the way Laravel's does.
 * The container here holds **singletons**, so the cache would be redundant in the
 * ordinary case, and actively wrong in the case that matters: the routing facade's
 * target is swapped around every `require` of a routes file, precisely so that
 * `routes/web.php` and `routes/api.php` write into different routers. A cached
 * instance would pin whichever was resolved first and send the other file's routes
 * into the wrong table — a whole surface that registers cleanly and is reachable
 * from nowhere.
 *
 * ## `app()` rather than an injected container
 *
 * A facade is called from a routes file, which is `require`d rather than built by
 * the container, so there is nothing to inject into. `app()` is the same accessor
 * every helper in this framework already uses, and it degrades honestly: outside
 * a booted plugin, `Routing`'s docblock says what happens.
 */
abstract class Facade
{
    /**
     * The service this facade proxies, by container key.
     *
     * The concrete return type is the guard: a facade that forgets this method
     * cannot be loaded, rather than failing at the first static call with a
     * message about a null array key.
     */
    abstract protected static function getFacadeAccessor(): string;

    /**
     * Forward every static call to the resolved service.
     *
     * The service is resolved on **every** call rather than held, for the reason
     * in the class docblock: the routing facade's target changes between routes
     * files, and a facade holding the first one it saw would route both files into
     * one domain.
     *
     * @param array<int, mixed> $arguments
     *
     * @return mixed
     *
     * @throws RuntimeException When the accessor is not bound.
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        $root = static::getFacadeRoot();

        if (!is_object($root) || !method_exists($root, $method)) {
            throw new RuntimeException(sprintf(
                'Facade [%s] cannot call [%s()]: the service bound to [%s] is [%s].',
                static::class,
                $method,
                static::getFacadeAccessor(),
                get_debug_type($root)
            ));
        }

        /** @var callable $callable */
        $callable = [$root, $method];

        return $callable(...$arguments);
    }

    /**
     * The service behind this facade, as the container currently has it.
     *
     * Resolved fresh on each call, so a swap is visible immediately to every
     * caller — which is the whole mechanism the routing facade relies on.
     *
     * @return mixed
     *
     * @throws RuntimeException When the container has nothing bound to the key.
     */
    public static function getFacadeRoot(): mixed
    {
        return static::resolveFacadeInstance(static::getFacadeAccessor());
    }

    /**
     * @return mixed
     *
     * @throws RuntimeException When the key is unbound.
     */
    protected static function resolveFacadeInstance(string $name): mixed
    {
        $container = app();

        if (!$container instanceof Container || !$container->bound($name)) {
            throw new RuntimeException(sprintf(
                'Facade [%s] resolved [%s] before it was bound. A facade is only callable '
                . 'once the service provider that binds it has registered — and, for the '
                . 'routing facade, only from inside a routes file being loaded.',
                static::class,
                $name
            ));
        }

        return $container->resolve($name);
    }
}
