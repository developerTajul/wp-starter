<?php
declare(strict_types=1);

namespace Framework\Routing\Middleware;

use Framework\Routing\Contracts\Middleware;
use WP_Error;
use WP_REST_Request;

/**
 * Rejects the request unless the current user holds a given capability.
 *
 * The capability defaults to `manage_options`, which is the right default for an
 * admin endpoint and a dangerous one for anything else. To check a different
 * capability, subclass it:
 *
 *     final class RequireEditPosts extends RequireCapability
 *     {
 *         public function __construct()
 *         {
 *             parent::__construct('edit_posts');
 *         }
 *     }
 *
 * then list the subclass as the route's middleware.
 *
 * Because middleware is resolved by class name with the *resolved* class as the
 * container context, a contextual binding only applies when it names that same
 * class. Binding on the subclass works:
 *
 *     $container->when(RequireEditPosts::class)->needs('$capability')->give('edit_posts');
 *
 * Binding on `RequireCapability` itself, or on the controller whose route uses
 * it, does **not** - the container never sees either during resolution. Nothing
 * errors: the binding is simply never consulted and the capability silently
 * stays whatever the constructor default is. Prefer the subclass's own
 * constructor over a contextual binding; it fails loudly if it is wrong.
 *
 * Not `final`, so that subclassing works. See
 * {@see \Framework\Routing\Router::runMiddleware()}.
 */
class RequireCapability implements Middleware
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
