<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Framework\Routing\Contracts\Middleware;
use WP_Error;
use WP_REST_Request;

/**
 * Allows the request only when its injected dependency reports the expected
 * source, so a test can prove middleware dependency injection actually ran
 * rather than merely not throwing.
 */
final class SourceCheckingMiddleware implements Middleware
{
    public function __construct(private readonly ReportSource $source)
    {
    }

    public function handle(WP_REST_Request $request): ?WP_Error
    {
        if ($this->source->name() === 'from-middleware') {
            return null;
        }

        return new WP_Error('unexpected_source', 'Unexpected source.', ['status' => 500]);
    }
}
