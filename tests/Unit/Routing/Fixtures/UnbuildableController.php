<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

/**
 * Cannot be constructed, to prove a build failure is reported rather than thrown.
 *
 * The untyped `string $required` is the point: the container can auto-wire the
 * `stdClass` and cannot invent a string, so construction fails with an
 * unresolvable-dependency error. That is a deployment mistake, and it must reach
 * the client as a named error page rather than escaping into a WordPress hook as
 * an unhandled fatal.
 */
final class UnbuildableController
{
    public function __construct(
        private readonly \stdClass $needs,
        private readonly string $required
    ) {
    }

    public function index(): string
    {
        return '<p>never reached</p>';
    }
}
