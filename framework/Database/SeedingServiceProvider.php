<?php
declare(strict_types=1);

namespace Framework\Database;

use DevLinkStudios\WPServiceContainer\ServiceProvider;
use Framework\Database\Commands\DbSeedCommand;
use Framework\Database\Seeding\SeederRegistry;

/**
 * Binds the seeder registry and the commands that drive it.
 *
 * Why the registry is bound with an explicit path
 *
 * `base_path('database/seeders')`, the same way {@see Migrator} defaults to
 * `base_path('database/migrations')` when it is given no path - and for the same
 * reason, so a project that keeps its seeders elsewhere re-binds this singleton in
 * its own provider. The path is resolved through `base_path()` rather than by
 * concatenating `__DIR__`, because the framework is installed under `vendor/` in
 * a consuming project and `__DIR__` would point into the framework's own tree,
 * where there are no project seeders at all.
 *
 * Why the registry takes the container
 *
 * Seeders declare their dependencies as constructor arguments, and the registry
 * builds each one through the container so those arguments arrive. That is what
 * lets a seeder write through the application's own repository, the same
 * instance, the same connection, the same validation - instead of constructing
 * its own and quietly writing somewhere else. See {@see SeederRegistry}.
 *
 * Why `db:seed` is bound and `make:seeder` is not
 *
 * `make:seeder` needs nothing: it is a generator, has no constructor, and the
 * container builds it from its class name alone. `db:seed` takes a registry, and
 * the registry takes a path that is this project's, so it is bound here, where
 * that path is known, rather than auto-wired into a registry pointed at the
 * wrong directory.
 */
class SeedingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(
            SeederRegistry::class,
            fn ($container): SeederRegistry => new SeederRegistry(
                $container,
                $this->seedersPath(),
            )
        );

        // `bind()` rather than `singleton()`: the command holds no state between
        // runs, and the registry it resolves is already the singleton.
        $this->container->bind(
            DbSeedCommand::class,
            static fn ($container): DbSeedCommand => new DbSeedCommand(
                $container->resolve(SeederRegistry::class)
            )
        );
    }

    /**
     * The absolute path to the project's seeders directory.
     *
     * Guarded because `base_path()` is a helper the framework's own bootstrap
     * defines: a unit test that resolves this provider without booting the
     * application would otherwise fail on an undefined function rather than on the
     * one thing it is actually missing.
     */
    private function seedersPath(): string
    {
        if (function_exists('base_path')) {
            return base_path('database/seeders');
        }

        return dirname(__DIR__, 2) . '/database/seeders';
    }
}
