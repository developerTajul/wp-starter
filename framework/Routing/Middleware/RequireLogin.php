<?php
declare(strict_types=1);

namespace Framework\Routing\Middleware;

use Framework\Routing\Contracts\Middleware;
use WP_Error;
use WP_REST_Request;

/**
 * Rejects the request unless a user is logged in.
 *
 * Note this is *not* enough on its own for WordPress cookie authentication:
 * the REST API additionally requires an X-WP-Nonce header on cookie-authenticated
 * requests, which WordPress enforces itself. This middleware answers "is anyone
 * logged in", not "is this request verified".
 */
final class RequireLogin implements Middleware
{
    public function handle(WP_REST_Request $request): ?WP_Error
    {
        if (is_user_logged_in()) {
            return null;
        }

        return new WP_Error(
            'rest_forbidden',
            __('Sorry, you must be logged in to do that.', 'wp-mini-laravel'),
            ['status' => 401]
        );
    }
}
