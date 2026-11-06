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
        // Generators.
        \Framework\Console\Commands\MakeProviderCommand::class,
        \Framework\Console\Commands\MakeControllerCommand::class,
        \Framework\Console\Commands\MakeRouteCommand::class,
        \Framework\Console\Commands\MakeCommandCommand::class,
        \Framework\Database\Commands\MakeMigrationCommand::class,

        // Migrations, in the order a developer meets them: apply, undo one
        // batch, undo everything and redo it, throw it all away and rebuild.
        \Framework\Database\Commands\MigrateCommand::class,
        \Framework\Database\Commands\MigrateRollbackCommand::class,
        \Framework\Database\Commands\MigrateRefreshCommand::class,
        \Framework\Database\Commands\MigrateFreshCommand::class,
        \Framework\Database\Commands\MigrateStatusCommand::class,

        // Introspection.
        \Framework\Console\Commands\RouteListCommand::class,
    ];
}
