<?php
declare(strict_types=1);

namespace Framework\Lifecycle;

use Framework\Config\Config;
use Framework\Database\Lifecycle\MigratesOnActivation;
use Framework\Database\Lifecycle\RemovesPluginData;

/**
 * The plugin's three lifecycle entry points, as callable methods.
 *
 * What this class is, and what it is not
 *
 * It owns *policy*: which option gates the wipe, what a failure is reported as,
 * and what deactivation is allowed to touch. The *mechanism* - running pending
 * migrations, reversing every batch, dropping the ledger - belongs to the
 * framework, and lives in `Framework\Database\Lifecycle\`. That split is the
 * three-zone rule applied to a lifecycle hook: `App\` wires and decides,
 * `Framework\` does the work, and neither reaches into the other's half.
 *
 * Why these are methods rather than closures in the entry file
 *
 * Three reasons, in increasing order of importance:
 *
 *  - The entry file is a top-level script with no namespace and no way to take
 *    constructor dependencies, so a closure there would have to reach for the
 *    container itself at call time.
 *  - `uninstall.php` cannot use the entry file's closure at all. It runs in a
 *    separate request, with the plugin's own `plugins_loaded` never fired, so it
 *    needs the same behaviour reachable by a second path. One class with two
 *    callers is what keeps the two paths from drifting.
 *  - The behaviour becomes testable. `WpStub::actionsFor()` hands back the
 *    registered callbacks, and a test can invoke `uninstall()` directly against
 *    a fake database and assert what happened - which is the only way the
 *    destructive path gets covered at all.
 *
 * The container is taken as an argument, not injected
 *
 * Every method here takes the container as its first parameter rather than
 * having one injected in the constructor, and that is deliberate. The class is
 * constructed and *then* registered as callbacks that WordPress will invoke much
 * later; a constructor-injected container would be captured at construction
 * time, before `boot()` has necessarily run, and every resolution would come
 * from that earlier state. Resolving at call time is what makes `if (! booted)
 * boot` meaningful - the check answers a real question about right now.
 *
 * The container is typed against the package's `Container` rather than an
 * interface because {@see booted()} needs `isBooted()`, which is not on
 * `ContainerInterface`.
 */
final class PluginLifecycle
{
    /**
     * The suffix appended to `app.prefix` to form the uninstall option's name.
     *
     * The prefix rather than a literal name, because this option is read by
     * *`uninstall.php`*, in a request where the plugin's config is the only
     * thing that knows what the plugin calls itself. A hardcoded
     * `wp_mini_laravel_delete_data_on_uninstall` would be rewritten by the
     * rebrander in the settings UI and not in the reader, so a rebranded plugin
     * would show a toggle that silently controlled nothing - and the failure
     * would appear at uninstall time, when the data is already gone.
     *
     * See the `Rebranding` section of the README: the option prefix is data, not
     * a label, which is exactly why nothing may spell it out.
     */
    public const OPTION_SUFFIX = 'delete_data_on_uninstall';

    /**
     * The option name the wipe is gated on.
     *
     * Read through `config()`, so a project that changes `app.prefix` - or the
     * rebrander, which changes it for them - moves this option with everything
     * else the plugin stores.
     */
    public static function deleteDataOption(): string
    {
        // `DEFAULT_PREFIX`, not `DEFAULT_CLI_PREFIX`. The two constants sit next
        // to each other in `Config` and both are non-empty strings, so using the
        // wrong one produces a plausible-looking name - `wp_minidelete_data_on_uninstall`
        // - rather than an error, and nothing would notice until an uninstall
        // read an option the settings screen had never written.
        return (string) config('app.prefix', Config::DEFAULT_PREFIX) . self::OPTION_SUFFIX;
    }

    /**
     * Whether the operator has agreed to have their data deleted.
     *
     * Why this defaults to *true*
     *
     * It used to default to `false`, on the reasoning that a destructive default
     * is unsafe, and that was wrong in a way that made the setting worthless.
     * WordPress asks the site owner, in plain language, at the moment they act:
     * "Are you sure you want to delete *X* and its data?" Clicking through that
     * prompt is the consent, and it is given per deletion, by the person doing
     * the deleting. A plugin that then consults a *separate* opt-in checkbox -
     * one that lives on the Reading screen, that most operators never find, and
     * that reads as off by default - does not honour that consent, it overrides
     * it. The plugin's files disappear, the tables do not, and nothing on screen
     * ever said why.
     *
     * So the option is no longer a *gate* that defaults to closed. It is an
     * *override* that defaults to "do what the delete prompt asked for", and
     * `false` is the deliberate escape hatch: an operator who wants their data
     * kept - to migrate it, to feed another tool, to reinstall over the top -
     * unticks the box and gets exactly that.
     *
     * What "unset" means
     *
     * Absent is the overwhelmingly common case: an install where nobody has ever
     * opened the Reading screen. It must mean yes, so `get_option()`'s default
     * argument is `true`. A stored `false` is the only value that keeps data,
     * and the `(bool)` cast is what makes a stored `'0'`, `0` or `''` mean the
     * same thing - a settings field can write any of them depending on who
     * rendered it, and a caller deciding whether to drop a table should not have
     * to reason about which.
     */
    public static function shouldDeleteDataOnUninstall(): bool
    {
        if (!function_exists('get_option')) {
            return true;
        }

        return (bool) get_option(self::deleteDataOption(), true);
    }

    /**
     * Store the operator's decision.
     *
     * The setter counterpart to {@see shouldDeleteDataOnUninstall()}: the Reading
     * screen's checkbox, a `wp option update`, or any future surface ends up
     * calling this, and none of them has to know how the option name is derived.
     */
    public static function setDeleteDataOnUninstall(bool $delete): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        // Autoload off, matching the migration high-water mark: this is read
        // once, during an uninstall request, and loading it on every page view
        // for the life of the site would be waste.
        update_option(self::deleteDataOption(), $delete, false);
    }

    /**
     * Activation: bring the schema up to date.
     *
     * @param \DevLinkStudios\WPServiceContainer\Container $container
     */
    public function activate($container): void
    {
        // The container is booted here for the case activation runs *without*
        // `plugins_loaded` having fired - which is every activation request, as
        // WordPress fires `activate_{plugin}` from `activate_plugin()` during
        // `admin_init`, long after `plugins_loaded`. Providers have registered by
        // now (that happens when the entry file is included, which is what
        // reaches this callback at all), but their `boot()` methods have not run.
        //
        // Booting is what a project expects of an activation hook: a provider
        // that registers a rewrite rule, schedules a cron event, or installs a
        // role does it in `boot()`, and a plugin activated without it would need
        // a second request before its own hooks existed.
        //
        // Safe to call unconditionally - `boot()` is idempotent and boots each
        // provider at most once - and it matters that it is unconditional rather
        // than behind a check: a stale boolean cached before another provider
        // registered would skip the boot entirely. The one thing the timing does
        // change is the `plugins_loaded` case, where WordPress has already passed
        // that action and any hook a provider registers on it during this boot
        // will not fire. That is unavoidable and is why `boot()` is written to
        // register its hooks for the future rather than to act immediately.
        $container->boot();

        $notice = $container->resolve(ActivationFailureNotice::class);

        // Cleared *before* the run, not after, and this ordering is the whole
        // correctness of it. The notice describes the outcome of the most recent
        // activation, so the previous one's verdict has to go first and the
        // reporter re-records it if this run fails too. Clearing afterwards
        // instead would have to answer "did *this* run fail, or is that option
        // left over from last time?", and the option cannot tell you, because a
        // stale failure and a fresh one are the same bytes. An operator who fixed
        // the problem and reactivated would then keep being told the database
        // could not be prepared, forever.
        //
        // Clearing regardless of whether anything is pending is deliberate: a
        // second activation on an up-to-date schema applies nothing and is
        // entirely successful, and must clear the notice just the same. Keying it
        // on `$applied` being non-empty would pin a stale notice on screen for as
        // long as the schema stayed current - which is the rest of the site's
        // life.
        $notice->clear();

        $this->migratesOnActivation($container)->__invoke();
    }

    /**
     * Deactivation: flush rewrite rules, and touch nothing else.
     *
     * Why the flush is here and conditional
     *
     * A plugin that registers post types, taxonomies or rewrite rules adds rows
     * to `wp_rewrite_rules`; deactivating it leaves those rules behind pointing
     * at routes that no longer exist. WordPress's own guidance is to flush on
     * both activation and deactivation to clear them, and that is all this does.
     *
     * It is guarded on the class existing because `uninstall.php` and any
     * programmatic `deactivate_plugins()` from a bootstrap context can run in a
     * request where the rewrite API was never loaded. The flush itself is done
     * through `$wp_rewrite`'s own method rather than the `flush_rewrite_rules()`
     * wrapper, because the wrapper's job is to persist and re-read the option ,
     * work that is pointless in a request that is about to end.
     *
     * What this must never do
     *
     * Drop a table, reverse a migration, or delete an option. Deactivation is a
     * pause button: the user may be debugging, switching between two plugins
     * that conflict, or waiting for a host to finish a deploy. Everything stored
     * survives it, which is also what makes reactivation cheap - `activate()`
     * finds nothing pending and does nothing.
     *
     * @param \DevLinkStudios\WPServiceContainer\Container $container
     */
    public function deactivate($container): void
    {
        // Deliberately does *not* boot the container. Booting would run every
        // provider's `boot()`, which resolves services, and the rewrite rules
        // this method exists to clear do not need any of it. Deactivation should
        // be the least eventful hook in the plugin's life, and doing less is how
        // it stays that way.
        unset($container);

        if (!class_exists('WP_Rewrite')) {
            return;
        }

        global $wp_rewrite;

        if ($wp_rewrite instanceof \WP_Rewrite) {
            $wp_rewrite->flush_rules(false);
        }
    }

    /**
     * Uninstall: delete the plugin's data, unless the operator opted out.
     *
     * Where the decision comes from
     *
     * Reaching this method at all means `uninstall.php` ran, which means
     * WordPress was deleting the plugin - and the site owner confirmed the
     * prompt that says the data goes with it. That is the consent. This method
     * honours it by default and consults the option only to catch the operator
     * who deliberately unticked "delete my data" on the Reading screen; see
     * {@see shouldDeleteDataOnUninstall()} for why the default is now `true`
     * rather than `false`. The option is read from the database, never from
     * config or an environment variable: the decision belongs to the site owner,
     * not to whoever configured the deployment.
     *
     * Where the boot happens
     *
     * After the check, and that ordering is load-bearing. `uninstall.php` runs in
     * a request where the plugin's own `plugins_loaded` has not fired, so the
     * container has registered its providers but not booted them; resolving the
     * migrator works regardless, since bindings are all `register()` sets. But
     * booting is not free - it runs every provider's `boot()`, which resolves
     * services, registers hooks and can touch the database - and doing that on an
     * uninstall that is going to keep all the data would be work performed for a
     * decision already made. The check comes first so an opted-out uninstall
     * costs one option read.
     *
     * @param \DevLinkStudios\WPServiceContainer\Container $container
     * @return bool True when the data was removed, false when it was kept or the
     *              removal failed.
     */
    public function uninstall($container): bool
    {
        if (!self::shouldDeleteDataOnUninstall()) {
            return false;
        }

        $container->boot();

        $reverted = [];
        $reported = false;

        $completed = $this->removesPluginData($container)->runWithoutThrowing($reverted, $reported);

        // Nothing is logged on the two paths where the outcome is what was asked
        // for or what was chosen. A log line per uninstall would train whoever
        // reads the log to skip the one that matters.
        if (!$completed && $reported) {
            return false;
        }

        unset($reverted);

        return true;
    }

    /**
     * The activation service, resolved through the container.
     *
     * Resolved per call rather than held in a property, so a test that swaps the
     * `Migrator` binding between two invocations sees the swap. A property would
     * capture the first resolution and keep serving it.
     */
    private function migratesOnActivation($container): MigratesOnActivation
    {
        return $container->resolve(MigratesOnActivation::class);
    }

    /**
     * The removal service, resolved through the container - see above.
     */
    private function removesPluginData($container): RemovesPluginData
    {
        return $container->resolve(RemovesPluginData::class);
    }
}
