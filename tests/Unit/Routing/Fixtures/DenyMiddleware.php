<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Framework\Routing\Contracts\Middleware;
use WP_Error;
use WP_REST_Request;

/**
 * Always rejects the request, with a status code a test can assert on.
 */
final class DenyMiddleware implements Middleware
{
    public const STATUS = 418;

    public function handle(WP_REST_Request $request): ?WP_Error
    {
        return new WP_Error('teapot', 'I am a teapot.', ['status' => self::STATUS]);
    }
}
