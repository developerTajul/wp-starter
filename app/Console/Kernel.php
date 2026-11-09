<?php

declare(strict_types=1);

namespace App\Console;

use Framework\Console\Kernel as BaseKernel;

/**
 * The project's command registry: which commands this project exposes.
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
        // Beside the controller generator, because the resource stub it
        // renders names two classes this command writes.
        \Framework\Console\Commands\MakeRequestCommand::class,
        \Framework\Console\Commands\MakeRouteCommand::class,
        \Framework\Console\Commands\MakeCommandCommand::class,
        \Framework\Database\Commands\MakeMigrationCommand::class,
        \Framework\Database\Commands\MakeSeederCommand::class,

        // Migrations, in the order a developer meets them: apply, undo one
        // batch, undo every batch, undo everything and redo it, throw it all
        // away and rebuild.
        \Framework\Database\Commands\MigrateCommand::class,
        \Framework\Database\Commands\MigrateRollbackCommand::class,
        \Framework\Database\Commands\MigrateResetCommand::class,
        \Framework\Database\Commands\MigrateRefreshCommand::class,
        \Framework\Database\Commands\MigrateFreshCommand::class,
        \Framework\Database\Commands\MigrateStatusCommand::class,
        \Framework\Database\Commands\MigrateDumpCommand::class,

        // Seeders. Separate from the migration group and named to sort after
        // it: `seed` runs after the schema exists, and a seeder against a
        // missing table would fail on its first insert.
        \Framework\Database\Commands\DbSeedCommand::class,

        // Introspection.
        \Framework\Console\Commands\RouteListCommand::class,
    ];
}
