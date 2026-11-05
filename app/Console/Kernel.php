<?php
declare(strict_types=1);

namespace App\Console;

use Framework\Console\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    /**
     * Commands registered with WP-CLI at boot.
     *
     * This is the project-level registry: the framework ships the console
     * machinery, but which commands exist is a per-project decision.
     *
     * @var array<int, class-string<\Framework\Console\Command>>
     */
    protected array $commands = [
        \Framework\Console\Commands\MakeProviderCommand::class,
    ];
}
