<?php
declare(strict_types=1);

namespace Framework\Routing\Contracts;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A cross-cutting check that runs before a route's handler.
 *
 * Kept to a WP_Error-or-nothing contract rather than a `$next` pipeline: WP_REST
 * already owns the request/response lifecycle and a permission_callback is the
 * only seam it offers, so a middleware that returned a response could not
 * actually stop the handler from running.
 */
interface Middleware
{
    /**
     * Return a WP_Error to reject the request; return null to allow it through.
     *
     * WordPress turns a non-null WP_Error into the response directly, so the
     * error's status code is what the client sees.
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function handle(WP_REST_Request $request): ?WP_Error;
}
