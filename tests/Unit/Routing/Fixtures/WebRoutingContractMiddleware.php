<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Framework\Routing\Contracts\Middleware;
use WP_Error;
use WP_REST_Request;

/**
 * A *routing*-contract middleware: valid on a REST route, a mistake on a web one.
 *
 * The point of the fixture is that nothing about the class is wrong in itself —
 * it is a correct REST middleware. Only the domain it was used in is wrong, which
 * is why the adapter has to name the contract rather than let the failure surface
 * later as a container error about an interface the author never mentioned.
 */
final class WebRoutingContractMiddleware implements Middleware
{
    public function handle(WP_REST_Request $request): ?WP_Error
    {
        return null;
    }
}
