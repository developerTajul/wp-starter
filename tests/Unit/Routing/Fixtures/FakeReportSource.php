<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

/**
 * A binding target a test can name, so an assertion can tell an injected
 * binding apart from an auto-wired default by its value alone.
 */
final class FakeReportSource implements ReportSource
{
    public function __construct(private readonly string $name = 'fake-service')
    {
    }

    public function name(): string
    {
        return $this->name;
    }
}
