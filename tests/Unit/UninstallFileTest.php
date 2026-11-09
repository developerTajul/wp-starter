<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;
use Tests\Support\WpStub;

/**
 * `uninstall.php`, as WordPress includes it.
 *
 * ## Why every test here is out of process
 *
 * The file is written to be `require`d exactly once, from the plugin root, by a
 * request that has already defined `WP_UNINSTALL_PLUGIN`. Two of those three are
 * process-wide facts: the constant cannot be undefined, and the entry file it
 * includes carries the `WP_MINI_LARAVEL_BOOTED` guard that makes a second
 * include a no-op. A test sharing a process with another would be testing the
 * second include, which is not the code path that matters.
 *
 * ## What is actually at risk here
 *
 * This is the only file in the plugin that can destroy a site's data on a
 * request the operator initiates by clicking "Delete" in a list of plugins. What
 * it must get right, and what is asserted below:
 *
 *  - The `WP_UNINSTALL_PLUGIN` guard, without which the path is reachable over
 *    HTTP by anyone who can guess the filename.
 *  - That the wipe *actually runs* by default. It is easy to write this file so
 *    that it only runs when some other box was ticked somewhere, and the failure
 *    is invisible: the plugin disappears, the tables stay, and nothing says why.
 *  - That the documented opt-out is honoured, so keeping your data is still
 *    reachable for someone migrating off the plugin.
 *  - That no failure in between escapes into WordPress's delete request and
 *    aborts it with the files half-removed.
 */
final class UninstallFileTest extends TestCase
{
    /**
     * A table one of the plugin's own migrations creates, and therefore the
     * evidence that the wipe reached the real migration directory. `wp_leads`
     * comes from the squashed baseline, which is the first file the migrator
     * runs.
     */
    private const MIGRATED_TABLE = 'wp_leads';

    /**
     * The migration that creates it, used by the failure test to name a ledger
     * row whose file is about to be made unfindable.
     */
    private const BASELINE_MIGRATION = '2026_09_29_232811_create_leads_table';

    /**
     * An identifier in the ledger that no file on disk answers to. See
     * {@see test_a_failing_wipe_does_not_escape()}.
     */
    private const PHANTOM_MIGRATION = '1999_01_01_000000_never_existed';

    /** @var list<string> */
    private array $temporary = [];

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        foreach ($this->directories as $directory) {
            @rmdir($directory);
        }

        $this->temporary = [];
        $this->directories = [];
    }

    /**
     * Including the file without WordPress's constant must destroy nothing.
     *
     * Reaching the wipe this way is what a direct HTTP request to
     * `uninstall.php` would do, and the `WP_UNINSTALL_PLUGIN` check inside the
     * file is the *only* thing standing between a typo'd URL and a destroyed
     * site — WordPress's own protection is merely that it defines the constant
     * before including the file.
     *
     * ## Why this runs a real subprocess
     *
     * The guard's response to a missing constant is `exit`, which ends the
     * *process*. A test that included the file in-process would not merely fail,
     * it would take PHPUnit's worker down with it mid-assertion — reporting an
     * unrelated failure in whichever test happened to be running, and, because
     * `RunInSeparateProcess` reuses one child across a class, poisoning every
     * test after it. So the scenario is driven in a genuinely separate PHP
     * process, where the `exit` is the observable outcome it actually is.
     *
     * What is asserted is the pair that matters: the process refuses to do the
     * work, *and* the script it would otherwise have deleted is still present
     * when it stops.
     */
    public function test_it_refuses_to_run_without_the_wordpress_guard_constant(): void
    {
        $directory = $this->makeScratchDirectory();

        // A marker standing in for the site's data. The `uninstall.php` under
        // test would delete real tables, which a subprocess cannot arrange here;
        // what it *would* touch is proxied by a file the harness deletes only if
        // the guard failed, so its survival is the evidence the guard held.
        $marker = $directory . '/site-data-marker.txt';
        file_put_contents($marker, 'still here');
        $this->temporary[] = $marker;

        $script = $directory . '/run-uninstall.php';
        $this->temporary[] = $script;

        // Deliberately does *not* define WP_UNINSTALL_PLUGIN.
        file_put_contents($script, sprintf(
            '<?php' . "\n"
            . 'require %s;' . "\n"
            . 'if (defined("WP_UNINSTALL_PLUGIN")) { fwrite(STDERR, "guard unexpectedly set"); exit(3); }' . "\n"
            . 'require %s;' . "\n"
            . 'file_put_contents(%s, "REACHED THE WIPE");' . "\n",
            var_export(dirname(__DIR__, 2) . '/tests/bootstrap.php', true),
            var_export($this->uninstallPath(), true),
            var_export($marker, true)
        ));

        $output = [];
        $status = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1',
            $output,
            $status
        );

        $this->assertSame(
            'still here',
            (string) file_get_contents($marker),
            'uninstall.php ran its wipe with no guard constant — the file was reached.'
        );
        $this->assertSame(
            0,
            $status,
            'The process did not exit cleanly: ' . implode(' ', $output)
        );
    }

    /**
     * **The default path: nothing was ever configured, and the tables go anyway.**
     *
     * This is the case the plugin got wrong. WordPress asked the site owner "are
     * you sure you want to delete *X* and its data?", they said yes, and the
     * plugin deleted its files and left every table behind — because the wipe was
     * gated on a separate checkbox on the Reading screen that defaults to off and
     * that nobody had ticked. The operator's consent was recorded in the wrong
     * place, and the symptom only showed up after the files were gone.
     *
     * So this test deliberately writes *nothing*. No option, no setting, no
     * preference — the state of every install that never visited that screen.
     * The tables must still be dropped, and this is what fails if the gate is
     * ever wired back into `uninstall.php`.
     */
    #[RunInSeparateProcess]
    public function test_it_removes_everything_when_nothing_was_ever_configured(): void
    {
        WpStub::reset();

        $db = $this->installDatabase();

        $this->assertTrue($db->tableExists(self::MIGRATED_TABLE), 'Precondition: no migration ran.');

        // Nothing writes the option, and that is the whole point.
        $this->includeUninstall();

        $this->assertFalse(
            $db->tableExists(self::MIGRATED_TABLE),
            'Deleting the plugin left its tables behind because a hidden opt-in was never ticked.'
        );
        $this->assertFalse($db->tableExists('wp_plugin_migrations'), 'The tracking table outlived the plugin.');
    }

    /**
     * The opt-out: an operator who deliberately unticked the box keeps everything.
     *
     * The counterpart to the test above, and the reason the option still exists.
     * Keeping your data has to remain reachable — someone migrating off the
     * plugin, or reinstalling over the top, needs the schema to survive a delete.
     */
    #[RunInSeparateProcess]
    public function test_it_keeps_everything_when_the_operator_opted_out(): void
    {
        WpStub::reset();

        $db = $this->installDatabase();

        \Framework\Lifecycle\PluginLifecycle::setDeleteDataOnUninstall(false);

        $this->includeUninstall();

        $this->assertTrue($db->tableExists(self::MIGRATED_TABLE), 'An explicit opt-out did not keep the data.');
        $this->assertTrue($db->tableExists('wp_plugin_migrations'), 'The ledger went despite the opt-out.');
    }

    /**
     * The high-water mark goes too. A plugin reinstalled after a wipe must start
     * at batch 1; a fresh database reporting batch 12 is a state nothing can
     * reason about.
     */
    #[RunInSeparateProcess]
    public function test_it_forgets_the_batch_high_water_mark(): void
    {
        WpStub::reset();

        $db = $this->installDatabase();
        $option = $db->prefix . \Framework\Database\Storage\WpMigrationStorage::HIGH_WATER_OPTION_SUFFIX;
        $this->assertArrayHasKey($option, WpStub::$options, 'Precondition: no high-water mark was written.');

        $this->includeUninstall();

        $this->assertArrayNotHasKey($option, WpStub::$options, 'The batch counter survived the wipe.');
    }

    /**
     * An uninstall must not *run* the plugin's lifecycle hooks.
     *
     * The trap this guards is the one a plugin walks into by writing one routine
     * for both events: `uninstall.php` includes the entry file, which is where
     * the activation and deactivation hooks are registered, so a shared code path
     * would run the wipe on every deactivation — and a deactivation is a pause
     * button, not a deletion. It matters more now than it did: the wipe is on by
     * default, so a shared path would destroy the schema of every site that
     * merely paused the plugin.
     *
     * ## What is asserted, and what deliberately is not
     *
     * **Not** that the hooks are unregistered. Since the activation fix they
     * *are* registered by the include, and that is correct and unavoidable: the
     * entry file registers them at include time, and `uninstall.php` includes the
     * entry file. Registration is harmless here — WordPress never fires
     * `activate_{plugin}` or `deactivate_{plugin}` during a delete request — so
     * asserting their absence would be asserting an accident of placement rather
     * than the safety property.
     *
     * **Not** `app()->isBooted()` either. The container *is* booted by the time
     * this returns, and correctly so: {@see \App\Lifecycle\PluginLifecycle::uninstall()}
     * boots it once the wipe is going ahead, because resolving the migrator
     * depends on providers having run. Asserting it stays un-booted would be
     * asserting the wipe did not happen.
     *
     * What is asserted is the thing that would actually hurt: the callbacks were
     * never *invoked*. A hook that fires here would run `activate()`, which
     * migrates — recreating on the way out the tables this request is deleting.
     */
    #[RunInSeparateProcess]
    public function test_including_it_does_not_fire_the_lifecycle_hooks(): void
    {
        WpStub::reset();

        $db = $this->installDatabase();
        $this->assertFalse(
            app()->isBooted(),
            'Precondition: the install step should have left the container un-booted.'
        );

        $this->includeUninstall();

        // The wipe ran — that is what this request is for.
        $this->assertFalse($db->tableExists(self::MIGRATED_TABLE), 'Precondition: the wipe did not run.');

        // ...and crucially `activate()` never ran behind it. If it had, the
        // migration would have recreated the table the wipe had just dropped —
        // an uninstall that leaves the schema behind.
        $this->assertFalse(
            $db->tableExists(self::MIGRATED_TABLE),
            'The activation hook fired during the uninstall and re-migrated the tables.'
        );

        // `wp_plugin_migrations` survives as evidence about *how* it survived: a
        // table the wipe dropped and a migration then recreated looks identical
        // to one the wipe never reached, and only the ledger tells them apart.
        $this->assertFalse(
            $db->tableExists('wp_plugin_migrations'),
            'The tracking table is back, so something re-migrated after the wipe.'
        );

        unset($db);
    }

    /**
     * A missing entry file leaves the data alone rather than fataling.
     *
     * This is the partial-deletion case: a host that removes files before running
     * the uninstall, or an operator who deleted the directory by hand. There is
     * nothing to resolve a migrator through, so the only correct outcome is to do
     * nothing — and notably *not* to guess at a `DROP TABLE` from a hardcoded list.
     */
    #[RunInSeparateProcess]
    public function test_it_leaves_the_data_alone_when_the_entry_file_is_missing(): void
    {
        WpStub::reset();

        $db = $this->installDatabase();
        // Run a copy of `uninstall.php` from a directory with no entry file
        // beside it. The copy is what makes `__DIR__` resolve somewhere empty —
        // the real file cannot be moved, because the rest of the suite needs it.
        $directory = $this->makeScratchDirectory();

        $copy = $directory . '/uninstall.php';
        copy($this->uninstallPath(), $copy);
        $this->temporary[] = $copy;

        $this->defineGuard();

        require $copy;

        $this->assertTrue($db->tableExists(self::MIGRATED_TABLE), 'A missing entry file still wiped the data.');
        $this->assertTrue($db->tableExists('wp_plugin_migrations'));
    }

    /**
     * The file must never let a failure escape. WordPress is mid-deletion when it
     * includes this; an uncaught throwable aborts that deletion and leaves the
     * plugin's files half-removed, with the reason visible only if the host
     * happens to have `display_errors` on.
     *
     * ## Why the ledger is doctored rather than a migration file deleted
     *
     * The realistic failure is a recorded migration whose file has been removed
     * from disk — the state `rollbackAll()` refuses to guess at. Reproducing it
     * by deleting that file would mean deleting it from the *real*
     * `database/migrations/`, which is a test mutating the repository it is
     * testing: a tearDown that failed to put it back leaves the working tree
     * short a migration, and every later test that runs migrations gets a
     * different plugin. Worse, the damage is invisible — the suite stays green,
     * because the file's absence is precisely what this test asserts about.
     *
     * So the ledger row is rewritten to name an identifier with no file behind
     * it. That produces the same state inside the fake database, which is
     * discarded with the process, and touches nothing on disk.
     */
    #[RunInSeparateProcess]
    public function test_a_failing_wipe_does_not_escape(): void
    {
        WpStub::reset();

        $db = $this->installDatabase();
        $db->query(
            "UPDATE `wp_plugin_migrations` SET `migration` = '"
            . self::PHANTOM_MIGRATION
            . "' WHERE `migration` = '" . self::BASELINE_MIGRATION . "'"
        );

        $this->assertContains(
            self::PHANTOM_MIGRATION,
            array_column($db->rows('wp_plugin_migrations'), 'migration'),
            'Precondition: the ledger row was not rewritten.'
        );

        $this->includeUninstall();

        // Reaching here at all is the assertion: an escaping throwable would have
        // failed the test. The table survives, which is the honest outcome — the
        // plugin reports it through the log rather than pretending.
        $this->assertTrue($db->tableExists(self::MIGRATED_TABLE));
    }

    /**
     * A provider that throws from `boot()` does not abort the deletion.
     *
     * ## The failure this pins, and why the existing test did not catch it
     *
     * `test_a_failing_wipe_does_not_escape()` above covers a failure *inside* the
     * removal, which `RemovesPluginData::runWithoutThrowing()` already catches.
     * That is not the whole path. Before the wipe runs, `uninstall()` calls
     * `$container->boot()`, and boot runs every provider's `boot()` — which
     * resolves services and can reach a database, a remote API, or a filesystem
     * that is gone. A provider throwing there escapes `uninstall()`, escapes the
     * include, and lands in `delete_plugins()`.
     *
     * That ordering matters more than it looks. WordPress calls
     * `uninstall_plugin()` — which includes this file — *before* it deletes
     * anything, so a throwable here aborts the request with the plugin's files
     * still on disk. The operator sees "Could not fully remove the plugin",
     * investigates the filesystem, and finds nothing wrong with it: the actual
     * cause is a database service that failed to boot, and nothing in the message
     * says so.
     *
     * ## Why the provider is registered at runtime
     *
     * Because the realistic trigger is a project's own provider failing on a
     * broken environment, not a broken framework. Registering a deliberately
     * failing one is how that is reproduced without a broken environment. It goes
     * through the same public `register()` the Kernel uses, so the container
     * treats it exactly as it treats a real provider.
     */
    #[RunInSeparateProcess]
    public function test_a_provider_that_throws_while_booting_does_not_escape(): void
    {
        WpStub::reset();

        $db = $this->installDatabase();
        // Registered after `installDatabase()` has already booted the container
        // once for the migration run, which is what makes this a *second* boot —
        // the state a real delete request is in, where the container has
        // registered its providers but not booted them.
        app()->register(FailingBootProvider::class);

        $this->assertTrue($db->tableExists(self::MIGRATED_TABLE), 'Precondition: nothing to uninstall.');

        $this->includeUninstall();

        // Reaching here is the assertion: the throwable was contained rather than
        // propagating into WordPress's delete request. The data survives, which is
        // the honest outcome — a boot that failed cannot be trusted to run
        // migrations, so the tables are reported rather than half-removed.
        $this->assertTrue(
            $db->tableExists(self::MIGRATED_TABLE),
            'A boot failure left the schema in an indeterminate state; it must be reported, not half-removed.'
        );
    }

    // -------------------------------------------------------------- plumbing

    private function uninstallPath(): string
    {
        return dirname(__DIR__, 2) . '/uninstall.php';
    }

    /**
     * Define the constant WordPress sets before it includes `uninstall.php`.
     */
    private function defineGuard(): void
    {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'wp-mini-laravel/wp-mini-laravel.php');
        }
    }

    /**
     * An empty directory under the system temp dir, removed in tearDown.
     */
    private function makeScratchDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/wpml-uninstall-' . bin2hex(random_bytes(6));

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $this->directories[] = $directory;

        return $directory;
    }

    /**
     * Include `uninstall.php` the way WordPress does: with `WP_UNINSTALL_PLUGIN`
     * already defined, and the plugin's own `plugins_loaded` never fired.
     */
    private function includeUninstall(): void
    {
        $this->defineGuard();

        require $this->uninstallPath();
    }

    /**
     * A database carrying a migrated table and the ledger, as a live site would.
     *
     * Built by running the plugin's own migrator over the plugin's own migrations
     * — through the same entry file the uninstall includes — so the tables this
     * test then watches disappear are the real ones, created by the real path.
     *
     * `runPending()` is called directly rather than by firing `plugins_loaded`,
     * because that action is never fired in a delete request. Activating through
     * the hook instead would test a request shape WordPress does not produce
     * here, and would leave the container booted for reasons the include below
     * does not share.
     */
    private function installDatabase(): FakeWpdb
    {
        $db = new FakeWpdb();
        $GLOBALS['wpdb'] = $db;

        require_once dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        app()->resolve(\Framework\Database\Migrator::class)->runPending();

        return $db;
    }
}
