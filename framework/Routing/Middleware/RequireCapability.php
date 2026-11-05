<?php
declare(strict_types=1);

namespace Framework\Routing\Middleware;

use Framework\Routing\Contracts\Middleware;
use WP_Error;
use WP_REST_Request;

/**
 * Rejects the request unless the current user holds a given capability.
 *
 * The capability defaults to `manage_options`, which is the right default for
 * an admin endpoint and a dangerous one for anything else — so to check a
 * different capability, bind this class for the route's context:
 *
 *     $container->when(LeadController::class)
 *               ->needs('$capability')
 *               ->give('edit_posts');
 *
 * Middleware is resolved from the container by class name, so a binding is what
 * supplies a constructor argument that is not the default.
 */
final class RequireCapability implements Middleware
{
    public function __construct(private readonly string $capability = 'manage_options')
    {
    }

    public function handle(WP_REST_Request $request): ?WP_Error
    {
        if (current_user_can($this->capability)) {
            return null;
        }

        // 403 rather than 401: the client is authenticated as *someone*, they
        // simply are not allowed. Returning 401 would invite a login retry that
        // could not possibly succeed.
        return new WP_Error(
            'rest_forbidden',
            __('Sorry, you are not allowed to do that.', 'wp-mini-laravel'),
            ['status' => rest_authorization_required_code()]
        );
    }
}
