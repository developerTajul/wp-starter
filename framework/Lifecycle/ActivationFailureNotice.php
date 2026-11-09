<?php
declare(strict_types=1);

namespace Framework\Lifecycle;

use Framework\Config\Config;
use Throwable;

/**
 * Surfaces a failed activation in the admin, where the operator is looking.
 *
 * Why this exists
 *
 * A migration that fails during activation is caught by
 * {@see \Framework\Database\Lifecycle\MigratesOnActivation} and written to the
 * error log, which is the right *mechanism* and an inadequate *outcome*: the
 * activation request itself completes normally. WordPress reports "Plugin
 * activated.", redirects to the plugin list, and the operator is left with a
 * plugin that looks healthy and a database with none of its tables.
 *
 * The message exists, but only in a file most operators never open. So the
 * failure is recorded as an option here and rendered as an admin notice - the
 * one place a site owner is certain to look immediately after activating
 * something.
 *
 * Why an option and not a transient
 *
 * A transient would expire. The condition it describes does not: the tables are
 * still missing tomorrow, and the notice has to keep saying so until someone
 * fixes the schema. It is cleared when a later activation *succeeds*, which is
 * the only event that makes the message untrue.
 *
 * Why not throw instead
 *
 * Throwing would abort the activation and show WordPress's generic "the plugin
 * could not be activated", which is more dramatic and less useful. It discards
 * the one thing the operator needs, which is *which* migration failed and why,
 * and it leaves a plugin that cannot be activated at all rather than one that is
 * active with a schema to repair. Reporting is the better failure; this class is
 * what makes the report visible.
 */
final class ActivationFailureNotice
{
    /**
     * The option suffix, appended to `app.prefix` like every other stored key.
     */
    public const OPTION_SUFFIX = 'activation_failure';

    /**
     * The admin notice hook, and the capability required to see it.
     */
    private const CAPABILITY = 'activate_plugins';

    /**
     * Record a failure and log it.
     *
     * The caller is the reporter closure bound in
     * `App\Providers\LifecycleServiceProvider`, so this runs *inside* the
     * activation request - before WordPress redirects - which is what makes the
     * notice visible on the very next screen rather than one request later.
     */
    public function record(Throwable $e): void
    {
        $this->write($e);

        // The log line is kept as well as the notice. A notice is for the person
        // who was there when it happened; the log is for the person who finds the
        // site broken a week later, and for a host with no admin access.
        \Framework\Database\Lifecycle\MigratesOnActivation::reportToErrorLog($e);
    }

    /**
     * Forget any recorded failure.
     *
     * Called after a successful activation, because the only reason to keep
     * showing the notice is that the problem is still there.
     */
    public function clear(): void
    {
        if (function_exists('delete_option')) {
            delete_option($this->option());
        }
    }

    /**
     * Render the notice, if there is one to render.
     *
     * Hooked on `admin_notices`. Guarded throughout rather than assuming an admin
     * context, because providers boot on front-end requests too.
     */
    public function render(): void
    {
        if (!function_exists('get_option') || !function_exists('current_user_can')) {
            return;
        }

        // Only the people who could act on it. A notice about a failed schema
        // migration is noise to an author who cannot run one.
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $failure = get_option($this->option());

        if (!is_array($failure) || !isset($failure['message'])) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><code>%s</code></p>'
            . '<p>%s</p></div>',
            'WP Mini Laravel: the database could not be prepared.',
            'The plugin is active, but its migrations did not finish. Some tables may be missing, '
            . 'so parts of the plugin will not work until this is resolved.',
            $this->escape((string) $failure['message']),
            'Run <code>wp ' . $this->escape($this->cliPrefix()) . ' migrate</code> from the command line '
            . 'to see the full error, then activate the plugin again to retry.'
        );
    }

    /**
     * The option name the notice is stored under.
     */
    public function option(): string
    {
        // `DEFAULT_PREFIX`, not `DEFAULT_CLI_PREFIX` - see the same note in
        // {@see PluginLifecycle::deleteDataOption()}: the two constants sit next
        // to each other and the wrong one produces a plausible name rather than
        // an error.
        return (string) config('app.prefix', Config::DEFAULT_PREFIX) . self::OPTION_SUFFIX;
    }

    /**
     * Store what went wrong, as the little that is useful and safe to re-render.
     *
     * Deliberately not the exception object: an option is serialised into the
     * database and read back in a later request, and an unserialisable or
     * class-missing value there is a second failure on top of the first. A
     * message, a class name and a timestamp survive that round trip.
     */
    private function write(Throwable $e): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        update_option(
            $this->option(),
            [
                'message' => $e->getMessage() === '' ? $e::class : $e::class . ': ' . $e->getMessage(),
                'time' => function_exists('time') ? time() : 0,
            ],
            false
        );
    }

    /**
     * The console prefix, so the suggested command is one that actually exists.
     */
    private function cliPrefix(): string
    {
        return (string) config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX);
    }

    /**
     * Escape for HTML.
     *
     * `esc_html()` when WordPress is loaded - the correct function, and the one
     * that respects a site's filters, falling back to `htmlspecialchars()` so
     * the method is still correct outside an install.
     */
    private function escape(string $value): string
    {
        if (function_exists('esc_html')) {
            return esc_html($value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
