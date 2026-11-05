<?php
declare(strict_types=1);

namespace Framework\Database;

use DevLinkStudios\WPServiceContainer\ServiceProvider;

/**
 * Binds the migrator, and the console commands that drive it.
 *
 * The migrator is bound as a singleton with **no** explicit path, so it falls
 * back to `base_path('database/migrations')`. Binding the path here instead would
 * hardcode this framework's layout into a consuming project that may keep its
 * migrations elsewhere; a project with a different layout re-binds the singleton
 * in its own provider, and because `register()` order is provider-list order, an
 * app provider listed after this one wins.
 *
 * The three commands take the migrator by constructor injection, which is what
 * makes them testable without WP-CLI: a test hands them a migrator built over a
 * temporary directory and a fake database.
 */
class MigrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Migrator::class, static fn (): Migrator => new Migrator());

        $this->container->bind(
            Commands\MigrateCommand::class,
            static fn ($container): Commands\MigrateCommand => new Commands\MigrateCommand(
                $container->resolve(Migrator::class)
            )
        );

        $this->container->bind(
            Commands\MigrateRollbackCommand::class,
            static fn ($container): Commands\MigrateRollbackCommand => new Commands\MigrateRollbackCommand(
                $container->resolve(Migrator::class)
            )
        );

        $this->container->bind(
            Commands\MigrateStatusCommand::class,
            static fn ($container): Commands\MigrateStatusCommand => new Commands\MigrateStatusCommand(
                $container->resolve(Migrator::class)
            )
        );
    }
}
