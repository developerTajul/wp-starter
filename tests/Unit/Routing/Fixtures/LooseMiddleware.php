<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use WP_REST_Request;

/**
 * A middleware that satisfies the container's resolution contract without
 * implementing the Middleware interface at all.
 *
 * This is not hypothetical: Router resolves middleware by class name from a
 * `middleware()` call that takes a plain string, so nothing stops an author
 * passing a class that never declared `implements Middleware`. PHP cannot
 * catch this at compile time the way it does for an interface implementor
 * with an incompatible signature — there is no interface in play — so the
 * router has to notice at runtime.
 *
 * It returns a truthy string, which is the dangerous shape: read naively it
 * would look exactly like the router's own "allowed" return.
 */
final class LooseMiddleware
{
    /**
     * @return string
     */
    public function handle(WP_REST_Request $request)
    {
        return 'yes';
    }
}
