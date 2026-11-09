<?php

declare(strict_types=1);

namespace App\Providers;

use Framework\Lifecycle\ActivationFailureNotice;
use Framework\Lifecycle\PluginLifecycle;
use Framework\Lifecycle\UninstallSettings;
use DevLinkStudios\WPServiceContainer\ServiceProvider;
use Framework\Database\Lifecycle\MigratesOnActivation;
use Framework\Database\Lifecycle\RemovesPluginData;
use Framework\Database\Migrator;
use Framework\Database\Storage\MigrationStorageInterface;
use Throwable;

/**
 * Binds the lifecycle services over the migrator they need.
 *
 * Must be listed after {@see \Framework\Database\MigrationServiceProvider} in
 * `config/app.php`: the closures resolve `Migrator::class` lazily, so the
 * order only has to hold at first resolve, but the list is kept truthful.
 */
class LifecycleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(UninstallSettings::class);
        $this->container->singleton(ActivationFailureNotice::class);

        $this->container->singleton(
            MigratesOnActivation::class,
            static fn ($container): MigratesOnActivation => new MigratesOnActivation(
                $container->resolve(Migrator::class),
                // Without this reporter the failure goes only to the error
                // log, and an activation that created no tables still reports
                // success in the admin. The notice puts the message where the
                // operator is looking. A project with its own channel composes
                // it here with `$notice->record($e)` rather than replacing it.
                static fn (Throwable $e) => $container->resolve(ActivationFailureNotice::class)->record($e),
            )
        );

        $this->container->singleton(
            RemovesPluginData::class,
            static fn ($container): RemovesPluginData => new RemovesPluginData(
                $container->resolve(Migrator::class),
                // The interface, matching how MigrationServiceProvider binds
                // it, so a project that swaps the storage swaps what uninstall
                // uses too. Resolving the concrete class would silently ignore
                // that override.
                $container->resolve(MigrationStorageInterface::class),
            )
        );

        $this->container->singleton(PluginLifecycle::class);
    }

    /**
     * Register the uninstall opt-out field and the activation-failure notice.
     *
     * Hooked in `boot()` because registering `add_action()` touches
     * WordPress, which the register/boot split reserves for boot.
     *
     * `register` is hooked at priority 11 so it runs after `save`; both run
     * inside `admin_init` on the same request, so a save at 11 would render
     * the old value back into the checkbox it just changed.
     *
     * The notice is hooked on `admin_notices`, which is where WordPress
     * renders notices, so a failure recorded during activation shows on the
     * plugin list the operator is redirected to.
     */
    public function boot(): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        $settings = $this->container->resolve(UninstallSettings::class);

        add_action('admin_init', [$settings, 'register'], 11);
        add_action('admin_init', [$settings, 'save']);

        $notice = $this->container->resolve(ActivationFailureNotice::class);

        add_action('admin_notices', [$notice, 'render']);
    }
}
