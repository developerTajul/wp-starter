<?php
declare(strict_types=1);

use Framework\Routing\RouteRegistrar;

/**
 * REST routes for this plugin.
 *
 * Loaded by App\Providers\RouteServiceProvider on the `rest_api_init` action.
 * Reached at /wp-json/{config('app.rest_namespace')}/...
 *
 * Keep this file a map of the API surface. Anything that needs to make a
 * decision belongs in a controller, where it can be resolved from the container,
 * tested on its own, and reused by a second entry point.
 */
return function (RouteRegistrar $router): void {
    // Unauthenticated health check: proves the routing layer is wired up
    // without depending on any other part of the application.
    $router->get('ping', static fn (): array => ['pong' => true]);
};
