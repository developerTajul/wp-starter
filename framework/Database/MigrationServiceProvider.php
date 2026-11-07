<?php
declare(strict_types=1);

namespace Framework\Database;

use DevLinkStudios\WPServiceContainer\ServiceProvider;
use Framework\Database\Adapters\DatabaseAdapterInterface;
use Framework\Database\Adapters\WpDatabaseAdapter;
use Framework\Database\Schema\SchemaDumper;
use Framework\Database\Storage\MigrationStorageInterface;
use Framework\Database\Storage\WpMigrationStorage;

/**
 * Binds the migrator, the schema adapter, the migration storage, and the console
 * commands that drive them.
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
 * ## The migrator's two dependencies
 *
 * Both are resolved through their *interface* bindings, never by passing a
 * concrete class, so a project that overrides either one overrides what the
 * migrator uses — the same rule the dumper below follows, and for the same
 * reason: `new WpMigrationStorage()` here would look identical and silently
 * ignore the override.
 *
 * The adapter and the storage are separate bindings rather than one, because
 * they are separate seams. `DatabaseAdapterInterface` compiles and runs the DDL
 * a migration describes; {@see MigrationStorageInterface} reads and writes the
 * rows that record what has run, and takes the lock. Widening the first to carry
 * the second would put `insert()` and `get_var()` on every adapter in the
 * project, including ones over targets with no SQL at all, to serve one caller.
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
        // No `$wpdb` argument on either of these: both resolve the global lazily,
        // on first use, so binding them here does not require WordPress to have
        // finished loading — which it has not, during `register()`.
        $this->container->singleton(
            DatabaseAdapterInterface::class,
            static fn (): DatabaseAdapterInterface => new WpDatabaseAdapter()
        );

        $this->container->singleton(
            MigrationStorageInterface::class,
            static fn (): MigrationStorageInterface => new WpMigrationStorage()
        );

        // Both arguments resolved through their interface bindings, so a project
        // that swaps either implementation swaps what the migrator uses. The path
        // is left null: see the class docblock.
        $this->container->singleton(
            Migrator::class,
            static fn ($container): Migrator => new Migrator(
                null,
                $container->resolve(DatabaseAdapterInterface::class),
                $container->resolve(MigrationStorageInterface::class),
            )
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

        // `migrate:dump` takes the dumper rather than the migrator, because it
        // never runs a migration: it reads the schema and writes a file. Sharing
        // the Migrator binding would have meant giving it a dependency it has no
        // use for, purely to match its neighbours.
        $this->container->bind(
            Commands\MigrateDumpCommand::class,
            static fn ($container): Commands\MigrateDumpCommand => new Commands\MigrateDumpCommand(
                $container->resolve(SchemaDumper::class)
            )
        );

        // Resolved through the *interface* binding above, so a project that
        // overrides the adapter overrides what the dumper reads too. Binding the
        // dumper to a concrete `WpDatabaseAdapter` here would look identical and
        // quietly ignore that override.
        $this->container->bind(
            SchemaDumper::class,
            static fn ($container): SchemaDumper => new SchemaDumper(
                $container->resolve(DatabaseAdapterInterface::class)
            )
        );
    }
}
