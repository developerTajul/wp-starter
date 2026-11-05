<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

/**
 * The contract a controller depends on.
 *
 * Exists so the container integration tests can prove a bound interface is
 * injected in preference to an auto-wired implementation — the whole reason
 * for depending on a contract rather than a class.
 */
interface ReportSource
{
    public function name(): string;
}
