<?php
declare(strict_types=1);

namespace Framework\Http;

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use DevLinkStudios\WPServiceContainer\ServiceProvider;
use Framework\Http\Middleware\Pipeline;
use Framework\Support\ViewRenderer;

/**
 * Binds the HTTP layer into the container.
 *
 * Register `Framework\Http\HttpServiceProvider::class` in `config('app.providers')`
 * to have {@see Request}, {@see Pipeline} and {@see ViewRenderer} resolvable by
 * type-hint.
 *
 * ## Why the request is a singleton, and what that costs
 *
 * A type-hinted `Request` must be *the* request — the one carrying the payload
 * the client sent — not a freshly captured one built at the moment some service
 * happens to be resolved. Binding it as a shared instance is what makes that
 * true, and it is also why the instance is captured eagerly in `register()`
 * rather than lazily in a factory: a factory would capture the superglobals at
 * first resolve, which is usually the same thing and occasionally is not.
 *
 * The cost is that the binding is fixed for the process. A test that wants a
 * different request binds its own instance over this one — `instance()` replaces
 * whatever was there — and a long-running worker that handles several requests
 * in one process must re-bind per request. `Request` is immutable, so a stale
 * binding can never *mutate* into the wrong answer; it can only be the wrong
 * object, which is easier to see.
 *
 * ## Why register() and not boot()
 *
 * `capture()` reads `$_SERVER` and `php://input`, which are available the moment
 * PHP starts handling a request — there is nothing here that needs to wait for a
 * WordPress hook. Deferring it to `boot()` would mean anything resolved by
 * another provider's `register()` got a container with no request in it.
 */
class HttpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Captured once, then shared. See the class docblock for why the instance
        // is built here rather than inside a closure.
        $this->container->instance(Request::class, Request::capture());

        // Not shared: a pipeline is per-use. Two requests — or two stacks in one
        // request — that shared a pipeline would share its middleware list and
        // its request, so configuring the second would silently reconfigure the
        // first. It is cheap to build, so there is nothing to gain by caching it.
        $this->container->bind(
            Pipeline::class,
            fn (ContainerInterface $container): Pipeline => new Pipeline(
                // $container here is typed as the interface, but resolve() is not
                // part of it — the concrete Container is what the closure needs.
                // Nothing else is bound under the interface in this framework, so
                // the cast is a statement of that fact rather than a guess.
                $container instanceof Container ? $container : app()
            )
        );

        // Shared, because a renderer carries state that must survive between
        // renders: the shared variable bag a project fills in `boot()`, the view
        // root (resolved from config and cached), and the flash payload — which
        // is *consumed* from the transient on first read. A per-use renderer
        // would mean a project's `share()` calls vanished, and a page rendering
        // two partials read the flash twice: the second read empty, because the
        // first destroyed the transient.
        //
        // Cheap to hold for the process: no views are opened at construction.
        $this->container->singleton(
            ViewRenderer::class,
            static fn (ContainerInterface $container): ViewRenderer => new ViewRenderer()
        );
    }
}
