<?php
declare(strict_types=1);

namespace Framework\Database;

use DevLinkStudios\WPServiceContainer\ServiceProvider;
use Framework\Database\Adapters\DatabaseAdapterInterface;
use Framework\Database\Adapters\WpDatabaseAdapter;

/**
 * Binds the migrator, the schema adapter, and the console commands that drive
 * them.
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
 *
 * ## The schema adapter binding
 *
 * `DatabaseAdapterInterface` is bound to the WordPress implementation, which is
 * the seam {@see \Framework\Database\Schema\Schema} resolves through. A project
 * targeting something other than `$wpdb` — an external service, a second
 * database — binds its own adapter here and the migrations do not change, since
 * they speak only the blueprint vocabulary.
 */
class MigrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Migrator::class, static fn (): Migrator => new Migrator());

        // No `$wpdb` argument: the adapter resolves the global lazily, on first
        // use, so binding it here does not require WordPress to have finished
        // loading — which it has not, during `register()`.
        $this->container->singleton(
            DatabaseAdapterInterface::class,
            static fn (): DatabaseAdapterInterface => new WpDatabaseAdapter()
        );

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

        $this->container->bind(
            Commands\MigrateFreshCommand::class,
            static fn ($container): Commands\MigrateFreshCommand => new Commands\MigrateFreshCommand(
                $container->resolve(Migrator::class)
            )
        );

        $this->container->bind(
            Commands\MigrateRefreshCommand::class,
            static fn ($container): Commands\MigrateRefreshCommand => new Commands\MigrateRefreshCommand(
                $container->resolve(Migrator::class)
            )
        );
    }
}
