<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Framework\Http\Request;

/**
 * Records the URI of the request it was handed.
 *
 * Typed against the framework `Request`, which is what makes the binder hand over
 * the request object itself rather than looking for a request value named
 * "request".
 */
final class RequestEcho
{
    public static string $uri = '';

    public function index(Request $request): string
    {
        self::$uri = $request->uri();

        return 'request';
    }
}
