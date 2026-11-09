<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\MigrationWorkspace;
use Tests\Support\ThrowingMigration;
use Tests\Support\WpStub;

/**
 * The end-to-end path the bug report described, driven through the entry file.
 *
 * ## What this covers that the unit tests do not
 *
 * {@see \Tests\Unit\Lifecycle\ActivationFailureNoticeTest} drives
 * `PluginLifecycle::activate()` against a container the test assembles, which
 * proves the class works but says nothing about whether a real activation is
 * *wired* to it. {@see \Tests\Unit\ActivationBootOrderTest} proves the hook is
 * registered at the right moment but always migrates cleanly.
 *
 * This file closes the remaining gap: the entry file, the provider it registers,
 * the reporter the provider binds, and a migration that fails — composed exactly
 * as production composes them, so that the thing asserted is the thing that ships.
 */
final class ActivationErrorHandlingTest extends TestCase
{
    private ?MigrationWorkspace $workspace = null;

    protected function tearDown(): void
    {
        $this->workspace?->cleanup();
        $this->workspace = null;

        unset($GLOBALS['wpdb']);
    }

    /**
     * **The bug report's requirement 4, end to end.** A migration that fails
     * during activation must produce a visible admin notice rather than a
     * silent log line.
     */
    #[RunInSeparateProcess]
    public function test_a_failing_migration_during_activation_produces_a_visible_notice(): void
    {
        WpStub::reset();
        $this->installPluginWithFailingMigration();

        WpStub::activatePlugin($this->basename());

        $this->assertNotSame(
            '',
            $this->renderNotice(),
            'A failed activation produced no notice. The operator sees "Plugin activated." '
            . 'over a database with no tables.'
        );
    }

    /**
     * The failure must be recorded even though the activation request completes.
     *
     * This is the property that makes the notice possible at all: the exception is
     * caught, the request finishes, and the *record* survives into the next
     * request where the notice is rendered.
     *
     * The simulation of "the next request" keeps the options and drops the hook
     * state, which is the split WordPress itself has — `wp_options` is the
     * database, and `$wp_filter` is per-request. `WpStub::reset()` clears both, so
     * calling it here would erase the very thing under test and report a false
     * failure; the options are carried across by hand for that reason.
     */
    #[RunInSeparateProcess]
    public function test_the_failure_is_still_recorded_on_the_next_request(): void
    {
        WpStub::reset();
        $this->installPluginWithFailingMigration();

        WpStub::activatePlugin($this->basename());

        $options = WpStub::$options;

        // A separate request: fresh hook state, same database.
        WpStub::reset();
        WpStub::$options = $options;

        $this->assertNotSame(
            '',
            $this->renderNotice(),
            'The failure did not survive into the request that renders the notice.'
        );
    }

    /**
     * Activation must not fatal, whatever the migration does.
     *
     * The report offered "throw it so WordPress halts activation" as an option.
     * Deliberately not taken: a throw here aborts the activation with WordPress's
     * generic failure message and discards the migration's own error, leaving a
     * plugin that cannot be activated at all rather than one that is active with a
     * schema to repair. Reaching the assertion below is the evidence that the
     * choice holds.
     */
    #[RunInSeparateProcess]
    public function test_a_failing_migration_does_not_abort_the_activation_request(): void
    {
        WpStub::reset();
        $this->installPluginWithFailingMigration();

        WpStub::activatePlugin($this->basename());

        $this->assertTrue(app()->isBooted(), 'The activation request did not run to completion.');
    }

    /**
     * A **successful** activation leaves no notice. The counterpart to the first
     * test, and the one that keeps the notice from becoming permanent furniture.
     */
    #[RunInSeparateProcess]
    public function test_a_successful_activation_produces_no_notice(): void
    {
        WpStub::reset();
        $this->installPlugin();

        WpStub::activatePlugin($this->basename());

        $this->assertSame('', $this->renderNotice(), 'A clean activation reported a failure.');
    }

    /**
     * ...and a successful activation *after* a failed one clears it, which is the
     * sequence an operator actually performs: something breaks, they fix it,
     * they reactivate.
     *
     * The "fix" here is a real one — the failing migration is removed and a clean
     * one left in its place — rather than the failure record being deleted,
     * because deleting the record is precisely what is under test. A test that
     * cleared the option itself and then asserted the notice was gone would prove
     * only that `delete_option()` works.
     *
     * The replacement is written under the *same* identifier the failing one had,
     * and then the failing class is made unreachable by giving the clean
     * migration a different class name. That is not a contrivance: a real repair
     * edits the file and reloads the page, and the class is only re-read because
     * the process restarts. Here the process is shared, so a `class_exists()`
     * would keep serving the old throwing class however the file on disk reads.
     * Writing the repaired migration under a fresh identifier is what makes the
     * migrator reach code that can actually succeed.
     */
    #[RunInSeparateProcess]
    public function test_a_later_successful_activation_clears_an_earlier_failure(): void
    {
        WpStub::reset();
        $this->installPluginWithFailingMigration();

        WpStub::activatePlugin($this->basename());
        $this->assertNotSame('', $this->renderNotice(), 'Precondition: no failure was recorded.');

        // The operator repairs the migration. The replacement needs a distinct
        // *class* name, not merely a distinct filename: `MigrationFile` derives
        // the class from the identifier's description, so a second
        // `create_users_table` at a different timestamp still studly-cases to
        // `CreateUsersTable` and fatals on redeclaration.
        $this->workspace?->delete('2026_11_05_100000_create_users_table');
        $this->workspace?->write('2026_11_05_110000_create_leads_table', \Tests\Support\SchemaMigration::class);

        // The ledger row from the failed attempt must go too: `runPending()` would
        // otherwise still list the old identifier as pending, resolve it to a file
        // that no longer exists, and fail again for a different reason.
        $db = $GLOBALS['wpdb'];
        $db->query("DELETE FROM `wp_plugin_migrations` WHERE `migration` = '2026_11_05_100000_create_users_table'");

        WpStub::activatePlugin($this->basename());

        $this->assertSame(
            '',
            $this->renderNotice(),
            'The notice survived a successful reactivation.'
        );
    }

    // -------------------------------------------------------------- plumbing

    private function basename(): string
    {
        return plugin_basename(dirname(__DIR__, 2) . '/wp-mini-laravel.php');
    }

    /**
     * Boot the plugin with the shipped migrations, as an activation would.
     */
    private function installPlugin(): void
    {
        $this->installPluginWithFailingMigration();

        // Replace the failing migration with one that applies cleanly, so the
        // activation being tested is a real, successful one.
        $this->workspace?->cleanup();
        $this->workspace = new MigrationWorkspace();
        $this->workspace->write('2026_11_05_100000_create_users_table', \Tests\Support\SchemaMigration::class);

        $this->repinMigratorPath();
    }

    /**
     * Boot the plugin with a migration whose `up()` throws.
     */
    private function installPluginWithFailingMigration(): void
    {
        // The fake database has to be in place *before* the entry file is
        // included, because the storage resolves `$GLOBALS['wpdb']` lazily at
        // first use — and because including the entry file is what registers the
        // provider whose reporter writes the failure record.
        $GLOBALS['wpdb'] = new \Tests\Support\FakeWpdb();

        $this->workspace = new MigrationWorkspace();
        $this->workspace->write('2026_11_05_100000_create_users_table', ThrowingMigration::class);

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        $this->repinMigratorPath();
    }

    /**
     * Point the container's migrator at the workspace.
     *
     * The shipped binding reads `database/migrations`; the fake database has no
     * schema and the workspace is where the fixtures live. Overriding the binding
     * is what a project with a different layout does, so this exercises a
     * supported seam rather than a test-only back door.
     */
    private function repinMigratorPath(): void
    {
        $path = $this->workspace?->path();

        if ($path === null) {
            return;
        }

        app()->singleton(
            \Framework\Database\Migrator::class,
            static fn (): \Framework\Database\Migrator => new \Framework\Database\Migrator(
                $path,
                app()->resolve(\Framework\Database\Adapters\WpDatabaseAdapter::class),
                app()->resolve(\Framework\Database\Storage\MigrationStorageInterface::class),
            )
        );
    }

    /**
     * Render the notice with the capability it requires.
     */
    private function renderNotice(): string
    {
        ob_start();

        try {
            app()->resolve(\Framework\Lifecycle\ActivationFailureNotice::class)->render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}
