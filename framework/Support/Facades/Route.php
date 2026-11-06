<?php
declare(strict_types=1);

namespace Framework\Support\Facades;

use Framework\Routing\Contracts\RouterInterface;
use Framework\Routing\RouteLoader;
use Framework\Routing\RouteRegistrar;
use RuntimeException;

/**
 * The routing facade: a Laravel-shaped static surface for `routes/*.php`.
 *
 *     Route::get('leads', [LeadController::class, 'index'])
 *         ->name('leads.index')
 *         ->withOptions(['menu_title' => 'Leads']);
 *
 *     Route::group(['prefix' => 'reports', 'middleware' => [...]], function (): void {
 *         Route::get('all', [ReportController::class, 'index']);
 *     });
 *
 * ## The target is not "the router" — because there isn't one
 *
 * This framework has two routers and two routes files: `routes/web.php` writes
 * into a {@see \Framework\Routing\Adapters\WordPressRouterAdapter} and
 * `routes/api.php` into the REST router. A facade resolves *one* service by key,
 * so a facade that resolved "the router" would send both files' routes into
 * whichever table it happened to pick — the exact failure
 * `App\Providers\RouteServiceProvider`'s docblock records, where every endpoint
 * registered twice and `ping` became two REST routes on one path.
 *
 * So the accessor is deliberately **not** a router type. `'router'` is bound to a
 * {@see RouteRegistrar} — the object a routes file is handed today — and
 * {@see RouteLoader} rebinds it around each `require`. The file therefore talks to
 * the router that is loading it without having to say so, which is the one piece
 * of context a closure parameter used to carry and a static call cannot.
 *
 * `'router.api'` and `'router.web'` remain the keys for asking for an adapter *by
 * domain*, and nothing here touches them.
 *
 * ## Why the accessor is a plain key and not a router
 *
 * A registrar, not an adapter, because a routes file needs `group()` on top of the
 * verbs: the registrar is what carries the group prefix and the shared middleware,
 * and an adapter's `get()` would register the route with neither. That is the same
 * reason the loader passed `$router->registrar()` before this facade existed.
 */
class Route extends Facade
{
    /**
     * The container key the loader rebinds around every routes file.
     *
     * Named here rather than repeated as a literal in {@see RouteLoader} and the
     * service provider, because all three have to agree and a typo would present
     * as "facade resolved before it was bound" — a message about a missing
     * binding, pointing at none of the three.
     */
    public const ACCESSOR = 'router';

    protected static function getFacadeAccessor(): string
    {
        return self::ACCESSOR;
    }

    /**
     * Run a callback against a given registrar, then put the previous target back.
     *
     * **This is what makes two routes files possible.** {@see RouteLoader} wraps
     * each `require` in it, so a file's calls land on the router loading that file.
     * Restoring in a `finally` is not tidiness: a routes file that throws leaves the
     * static target pointing at its own router, and the next file — or the next
     * request under a persistent worker — would silently register into a table
     * nobody publishes.
     *
     * ## Why this does not touch the container
     *
     * The obvious spelling would be `$container->instance('router', $registrar)` and
     * restore afterwards, and it is wrong twice over. `Container::instance()`
     * **returns extenders to the caller** rather than keeping them, and
     * `ServiceProvider` registers extenders — so a routes file doing top-level
     * `app()->instance(...)` while this binding was swapped away would permanently
     * drop another provider's extenders when the binding came back. And a registrar
     * is per-file state, not a service: a caller of
     * `$container->resolve('router')` would be handed whichever file was loading at
     * the time.
     *
     * The target is held here instead, and {@see setTarget()} is how anything but
     * the loader — a service provider wiring routes in code, a test — points it.
     *
     * @param callable(RouteRegistrar): mixed $callback
     *
     * @return mixed Whatever the callback returned.
     */
    public static function withRegistrar(RouteRegistrar $registrar, callable $callback): mixed
    {
        $previous = self::hasTarget() ? self::target() : null;

        self::setTarget($registrar);

        try {
            return $callback($registrar);
        } finally {
            if ($previous instanceof RouteRegistrar) {
                self::setTarget($previous);
            } else {
                // Nothing was targeted before — an unwrapped load, or the first
                // file. Clearing rather than leaving the registrar installed means
                // a call from outside any load fails loudly instead of reaching
                // into the route table of a file that finished loading.
                self::$target = null;
            }
        }
    }

    /**
     * Point the facade at a registrar.
     *
     * For a caller that owns a router and wants the facade to drive it: a service
     * provider wiring routes in code, or a test asserting the swap. A routes file
     * never calls this — {@see RouteLoader} does it around the `require`.
     */
    public static function setTarget(RouteRegistrar $registrar): void
    {
        self::$target = $registrar;
    }

    /**
     * Point the facade at a router, building its registrar.
     *
     * The convenience for a caller holding an adapter rather than a registrar, so
     * it does not have to know that `registrar()` is the thing a routes file talks
     * to. For a web router this is a
     * {@see \Framework\Routing\WebRouteRegistrar}, so the surface helpers are
     * available through the facade exactly as they are through a closure.
     */
    public static function setRouter(RouterInterface $router): void
    {
        self::setTarget($router->registrar());
    }

    /**
     * Drop the target, so the next call reports an unbound facade.
     *
     * For tests, which assert the unbound case without a fresh process.
     */
    public static function clearTarget(): void
    {
        self::$target = null;
    }

    /**
     * The registrar calls are forwarded to, or null when none is set.
     *
     * Static rather than a container lookup: the accessor is bound **only** while a
     * routes file is loading, so this is where the per-load state lives. Held as a
     * typed property rather than in {@see static::$resolvedInstance} so a
     * half-initialised facade cannot hand back a router that was never a registrar.
     */
    private static ?RouteRegistrar $target = null;

    /**
     * The registrar every static call is forwarded to.
     *
     * Overridden so the target set by {@see withRegistrar()} is what `__callStatic`
     * reaches, rather than the container — see the class docblock for why the
     * container is not where this lives.
     *
     * @throws RuntimeException When nothing has been targeted.
     */
    public static function getFacadeRoot(): mixed
    {
        return self::target();
    }

    /**
     * @throws RuntimeException When no routes file is loading and nothing was set.
     */
    public static function target(): RouteRegistrar
    {
        if (!self::$target instanceof RouteRegistrar) {
            throw new RuntimeException(sprintf(
                'The [%s] facade was called outside a routes file. It is bound by '
                . '%s while that file loads, and has no target otherwise — a static call '
                . 'from anywhere else has no router to write into.',
                self::class,
                RouteLoader::class
            ));
        }

        return self::$target;
    }

    public static function hasTarget(): bool
    {
        return self::$target instanceof RouteRegistrar;
    }
}
