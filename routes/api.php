<?php
declare(strict_types=1);

use App\Http\Controllers\Api\LeadController;
use App\Http\Middleware\RequireLeadRead;
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

    // The resource layer, end to end. `index` returns a collection envelope
    // ({"data": [...]}) and `show` a single resource, both filtered through
    // LeadResource — which is what decides the public shape of a lead.
    //
    // These carry RequireLeadRead, and that is not boilerplate. A REST route is
    // public by default in WordPress, and these publish a lead's email address:
    // without the middleware an anonymous caller reads the whole table. `ping`
    // above is deliberately open because it returns nothing about anybody; the
    // difference between the two is the point.
    //
    // The middleware is named as a *class* and the capability lives in that
    // class's constructor. Middleware is resolved by class name with no
    // parameter syntax, so a `Middleware::class . ':capability'` spelling here
    // would register a route whose middleware cannot be resolved — denying every
    // caller, administrators included.
    $router->get('leads', LeadController::class, 'index')
        ->middleware(RequireLeadRead::class);

    $router->get('leads/{id}', LeadController::class, 'show')
        ->middleware(RequireLeadRead::class);
};
