<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Framework\Routing\Contracts\Middleware;
use WP_Error;
use WP_REST_Request;

/**
 * Throws, so a test can prove a middleware failure never becomes an allow and
 * never escapes as an uncaught throwable from the permission callback.
 */
final class ThrowingMiddleware implements Middleware
{
    public function handle(WP_REST_Request $request): ?WP_Error
    {
        throw new \RuntimeException('middleware blew up');
    }
}
