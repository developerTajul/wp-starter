<?php
declare(strict_types=1);

namespace App\Providers;

use App\Lifecycle\ActivationFailureNotice;
use App\Lifecycle\PluginLifecycle;
use App\Lifecycle\UninstallSettings;
use DevLinkStudios\WPServiceContainer\ServiceProvider;
use Framework\Database\Lifecycle\MigratesOnActivation;
use Framework\Database\Lifecycle\RemovesPluginData;
use Framework\Database\Migrator;
use Framework\Database\Storage\MigrationStorageInterface;
use Throwable;

/**
 * Binds the two lifecycle services, each over the migrator it needs.
 *
 * ## Why both are bound here rather than resolved automatically
 *
 * The container can build a class whose constructor parameters it can resolve,
 * so `MigratesOnActivation` over a one-argument `Migrator` would resolve without
 * this provider. `RemovesPluginData` would not: it takes a `Migrator` **and** a
 * `MigrationStorageInterface`, and the storage is bound to an interface rather
 * than to its class, so an automatic resolution has a choice to make that only a
 * binding can make for it.
 *
 * Binding both anyway, for two reasons. The symmetry means a reader does not
 * have to work out which of two sibling classes the container can build on its
 * own. And `MigratesOnActivation` takes an optional reporter — the whole point
 * of which is that a *project* supplies it, which is a decision that has to be
 * written down somewhere, and this is where. The default (null, meaning the
 * error log) is what the shipped binding uses; the commented line below is how a
 * project that has a real channel overrides it.
 *
 * ## Ordering
 *
 * Listed after {@see \Framework\Database\MigrationServiceProvider} in
 * `config/app.php`, and it has to be: registering a `singleton()` for an
 * abstract that is not yet bound is fine, but resolving `Migrator::class` here
 * would be a resolution during `register()` — before the provider that binds it
 * has run. The closures below resolve lazily, so the order only has to hold at
 * *first resolve*, which is long after every provider has registered. The
 * `config/app.php` order is kept truthful anyway rather than relied on.
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
                // The failure reporter. Without this the exception goes to the
                // error log and nowhere else, and an activation that created no
                // tables still reports success in the admin — the operator sees
                // "Plugin activated." over a database with none of the plugin's
                // schema, and has to know to go looking in a log file to find out
                // why. The notice puts the same message where they are already
                // looking.
                //
                // A project with a real channel (Slack, Sentry, a custom hook)
                // composes it here instead: call `$notice->record($e)` *and* its
                // own reporter, rather than replacing this one.
                static fn (Throwable $e) => $container->resolve(ActivationFailureNotice::class)->record($e),
            )
        );

        $this->container->singleton(
            RemovesPluginData::class,
            static fn ($container): RemovesPluginData => new RemovesPluginData(
                $container->resolve(Migrator::class),
                // The *interface*, matching how MigrationServiceProvider binds
                // it, so a project that swaps the storage implementation swaps
                // what uninstall uses too. Resolving the concrete class here
                // would look identical and silently ignore that override.
                $container->resolve(MigrationStorageInterface::class),
            )
        );

        $this->container->singleton(PluginLifecycle::class);
    }

    /**
     * Register the uninstall opt-in field and the activation-failure notice.
     *
     * Hooked in `boot()` rather than `register()`, because registering an
     * `add_action()` is touching WordPress and the register/boot split reserves
     * that for boot — the rule the container package documents, and the reason a
     * provider can depend on a binding declared by a provider listed after it.
     *
     * `save` is hooked at the default priority and `register` at 11, which
     * orders the write before the field is added. Both run inside `admin_init`
     * on the same request, so a submission that saved at 11 would render the
     * old value back into the checkbox it had just changed.
     *
     * The notice is hooked on `admin_notices`, which is where WordPress renders
     * them — not `admin_init`, which runs before any output. A failure recorded
     * during activation is therefore shown on the screen the operator is
     * redirected to, which is the plugin list.
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
