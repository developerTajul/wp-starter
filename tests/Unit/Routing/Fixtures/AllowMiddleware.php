<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Framework\Routing\Contracts\Middleware;
use WP_Error;
use WP_REST_Request;

/**
 * Always allows the request. Exists so tests can assert on middleware *wiring*
 * without a denial changing the outcome.
 */
final class AllowMiddleware implements Middleware
{
    public function handle(WP_REST_Request $request): ?WP_Error
    {
        return null;
    }
}
