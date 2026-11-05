<?php
declare(strict_types=1);

namespace App\Providers;

use Framework\Routing\RouteServiceProvider as BaseRouteServiceProvider;

/**
 * Project-level REST wiring.
 *
 * Override getNamespace() here to publish this project's routes under its own
 * REST namespace, or set $routesFile to null to turn the routes file off and
 * register routes some other way.
 */
class RouteServiceProvider extends BaseRouteServiceProvider
{
    protected function getNamespace(): string
    {
        return (string) config('app.rest_namespace', 'wp-mini-laravel/v1');
    }
}
