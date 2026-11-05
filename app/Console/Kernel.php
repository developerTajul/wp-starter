<?php
declare(strict_types=1);

namespace App\Console;

use Framework\Console\Kernel as BaseKernel;

/**
 * The project's command registry.
 *
 * The framework ships the console machinery (Framework\Console\Kernel); this
 * class decides which commands this particular project exposes.
 */
class Kernel extends BaseKernel
{
    /**
     * Commands registered with WP-CLI at boot.
     *
     * @var array<int, class-string<\Framework\Console\Command>>
     */
    protected array $commands = [
        \Framework\Console\Commands\MakeProviderCommand::class,
        \Framework\Console\Commands\MakeControllerCommand::class,
        \Framework\Console\Commands\MakeRouteCommand::class,
        \Framework\Database\Commands\MakeMigrationCommand::class,
        \Framework\Database\Commands\MigrateCommand::class,
        \Framework\Database\Commands\MigrateRollbackCommand::class,
        \Framework\Database\Commands\MigrateStatusCommand::class,
    ];
}
