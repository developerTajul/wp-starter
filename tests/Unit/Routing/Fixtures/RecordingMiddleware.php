<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Framework\Routing\Contracts\Middleware;
use WP_Error;
use WP_REST_Request;

/**
 * Records the order in which middleware ran, so a test can assert on ordering
 * rather than inferring it from side effects.
 */
final class RecordingMiddleware implements Middleware
{
    /** @var list<string> */
    public static array $ran = [];

    public function __construct(private readonly string $label = 'recording')
    {
    }

    public function handle(WP_REST_Request $request): ?WP_Error
    {
        self::$ran[] = $this->label;

        return null;
    }
}
