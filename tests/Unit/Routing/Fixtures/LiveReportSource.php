<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

/**
 * The auto-wired default implementation, used when nothing is bound for the
 * interface.
 */
final class LiveReportSource implements ReportSource
{
    public function name(): string
    {
        return 'live-service';
    }
}
