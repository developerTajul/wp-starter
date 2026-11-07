<?php
declare(strict_types=1);

namespace Tests\Unit\Database;

use Framework\Database\Adapters\WpDatabaseAdapter;
use Framework\Database\Exceptions\MigrationException;
use Framework\Database\Lifecycle\RemovesPluginData;
use Framework\Database\Migrator;
use Framework\Database\Schema\Schema;
use Framework\Database\Storage\WpMigrationStorage;
use PHPUnit\Framework\TestCase;
use Tests\Support\DownThrowingMigration;
use Tests\Support\EventRecordingMigration;
use Tests\Support\FakeWpdb;
use Tests\Support\MigrationWorkspace;
use Tests\Support\SchemaMigration;
use Tests\Support\WpStub;

/**
 * The uninstall wipe, against a fake database.
 *
 * This is the most destructive code in the framework, and the class of test it
 * most needs is the one that asserts what it does *not* touch. Every test below
 * states a precondition on real state (does the table exist, how many rows) and
 * asserts against that state rather than against `$db->queries`, because
 * `FakeWpdb` records a statement *before* parsing it — a recorded DROP proves
 * nothing about whether the table survived.
 */
final class RemovesPluginDataTest extends TestCase
{
    private MigrationWorkspace $workspace;
    private FakeWpdb $db;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->workspace = new MigrationWorkspace();
        $this->db = new FakeWpdb();

        Schema::useAdapter(new WpDatabaseAdapter($this->db));
    }

    protected function tearDown(): void
    {
        Schema::stopPretending();
        Schema::useAdapter(null);

        $this->workspace->cleanup();
    }

    /**
     * The whole promise: the tables are gone, and so is the ledger.
     */
    public function test_it_drops_the_tables_and_the_tracking_table(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);
        $this->migrator()->runPending();

        $this->assertTrue($this->db->tableExists('wp_users'), 'Precondition: the migration built nothing.');

        $reverted = $this->removal()();

        $this->assertSame(['2026_11_05_100000_create_users_table'], $reverted);
        $this->assertFalse($this->db->tableExists('wp_users'), 'The migration-created table survived.');
        $this->assertFalse($this->db->tableExists('wp_plugin_migrations'), 'The tracking table survived.');
    }

    /**
     * `down()` runs, rather than the table being dropped behind its back.
     *
     * This is the difference between "remove the plugin's data" and "drop every
     * table the ledger mentions": a `down()` is the author's own statement of how
     * to undo their `up()`, and it may do things a `DROP TABLE` sweep cannot know
     * about. Asserted through the recording migration, which issues no SQL and so
     * can only be observed through its own log.
     */
    public function test_it_runs_every_migrations_down_method(): void
    {
        // `EventRecordingMigration`, not `RecordingMigration`, and the reason is
        // the trap its own docblock documents: `MigrationWorkspace::write()`
        // copies the *source* into a per-workspace namespace under a new class
        // name, so the migration that actually runs is a different class with a
        // different static. Read the template's `$applied` and it is always
        // empty — a test that would pass whether or not the migration ran. The
        // event migration writes into the process-wide `WpStub` recorder, which
        // survives the copy.
        $this->workspace->write('2026_11_05_100000_first', EventRecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_second', EventRecordingMigration::class);
        $this->migrator()->runPending();

        // Both directions are asserted, so "down ran twice" cannot be satisfied
        // by "up never ran".
        $this->assertSame(
            [EventRecordingMigration::UP_MARKER, EventRecordingMigration::UP_MARKER],
            $this->bodyMarkers(),
            'Precondition: both migrations should have run their up().'
        );

        $this->removal()();

        $this->assertSame(
            [
                EventRecordingMigration::UP_MARKER,
                EventRecordingMigration::UP_MARKER,
                // Newest first, like every other reversal in the migrator.
                EventRecordingMigration::DOWN_MARKER,
                EventRecordingMigration::DOWN_MARKER,
            ],
            $this->bodyMarkers(),
            'Every migration should have run its down(), newest first.'
        );
    }

    /**
     * Only the markers the migration bodies left, in order.
     *
     * `WpStub::$fired` also collects the migrator's own `migration_starting` /
     * `finished` / `failed` hooks, interleaved with the body markers — so
     * comparing the whole list would pin this test to the migrator's hook
     * traffic as well, and a change to *that* would fail a test about
     * uninstall. Filtering to the body markers leaves an ordered list of which
     * direction ran, in which order, which is the question being asked.
     *
     * @return list<string>
     */
    private function bodyMarkers(): array
    {
        return array_values(array_filter(
            WpStub::$fired,
            static fn (string $tag): bool => $tag === EventRecordingMigration::UP_MARKER
                || $tag === EventRecordingMigration::DOWN_MARKER
        ));
    }

    /**
     * The high-water mark goes too. A plugin that is deleted and reinstalled
     * must start at batch 1, not wherever it had got to — a fresh database
     * reporting batch 47 is a state no one can reason about.
     */
    public function test_it_forgets_the_batch_high_water_mark(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);
        $this->migrator()->runPending();

        $option = $this->db->prefix . WpMigrationStorage::HIGH_WATER_OPTION_SUFFIX;
        $this->assertArrayHasKey($option, WpStub::$options, 'Precondition: no high-water mark was written.');

        $this->removal()();

        $this->assertArrayNotHasKey($option, WpStub::$options);
    }

    /**
     * A plugin uninstalled from a site where it never migrated anything must
     * still leave nothing behind — `ensureTrackingTable()` creates the tracking
     * table on the way to discovering there is nothing to do, so an empty ledger
     * is still a table that needs removing.
     */
    public function test_it_removes_an_empty_tracking_table(): void
    {
        // Reached through `batches()`, which ensures the tracking table before
        // reading it — the same path `rollbackAll()` takes.
        $this->migrator()->batches();

        $this->assertTrue($this->db->tableExists('wp_plugin_migrations'), 'Precondition: no tracking table.');

        $reverted = $this->removal()();

        $this->assertSame([], $reverted, 'Nothing was recorded, so nothing should have been reversed.');
        $this->assertFalse($this->db->tableExists('wp_plugin_migrations'), 'An empty tracking table survived.');
    }

    /**
     * A `down()` that throws must propagate. Unlike activation — where the
     * half-state is recoverable and the site keeps working — a half-finished
     * uninstall leaves tables the user believes are gone, and the caller needs
     * to know so it can say so.
     */
    public function test_a_failing_down_propagates(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', DownThrowingMigration::class);
        $this->migrator()->runPending();

        $this->expectException(MigrationException::class);

        $this->removal()();
    }

    /**
     * ...and the tolerant form reports instead of throwing, because
     * `uninstall.php` runs while WordPress is deleting the plugin and an
     * escaping throwable aborts that deletion partway.
     */
    public function test_run_without_throwing_reports_instead_of_throwing(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', DownThrowingMigration::class);
        $this->migrator()->runPending();

        $reverted = null;
        $reported = null;

        $completed = $this->removal()->runWithoutThrowing($reverted, $reported);

        $this->assertFalse($completed);
        $this->assertTrue($reported);
        $this->assertSame([], $reverted);
    }

    /**
     * The tolerant form still reports how far it got, which is the number that
     * decides whether a re-run is needed.
     */
    public function test_run_without_throwing_reports_what_was_reversed_before_the_failure(): void
    {
        // The *older* migration is the one that throws, which is what makes this
        // test about partial progress at all. Reversal runs newest-first, so the
        // throwing migration has to be reached second for anything to have been
        // reversed before it — the other way round the sweep stops on its very
        // first step and reports an empty list, which is correct and proves
        // nothing about the reporting.
        $this->workspace->write('2026_11_05_100000_first', DownThrowingMigration::class);
        $this->workspace->write('2026_11_05_120000_second', EventRecordingMigration::class);
        $this->migrator()->runPending();

        $reverted = null;
        $reported = null;

        $completed = $this->removal()->runWithoutThrowing($reverted, $reported);

        $this->assertFalse($completed);
        $this->assertTrue($reported);
        $this->assertSame(
            ['2026_11_05_120000_second'],
            $reverted,
            'A failing down() should still report what it reversed on the way.'
        );

        // Asserted independently of the return value, so the number above is not
        // the only evidence that the reversal actually happened.
        $this->assertSame(
            [EventRecordingMigration::UP_MARKER, EventRecordingMigration::DOWN_MARKER],
            $this->bodyMarkers(),
            'The newer migration should have been reversed before the older one failed.'
        );
    }

    /**
     * A recorded migration whose file has been deleted must be *reported*, not
     * skipped.
     *
     * This is the case that rules out `dropAllTables()`: it resolves each
     * recorded migration to a table name and silently skips one it cannot
     * resolve, so the table would survive a wipe the operator was told
     * succeeded. `rollbackAll()` throws instead, naming the missing file.
     */
    public function test_a_recorded_migration_with_no_file_is_reported_not_skipped(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);
        $this->migrator()->runPending();

        $this->workspace->delete('2026_11_05_100000_create_users_table');

        try {
            $this->removal()();
            $this->fail('A recorded migration with no file was silently skipped.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('2026_11_05_100000_create_users_table', $e->getMessage());
            $this->assertStringContainsString('missing', $e->getMessage());
        }
    }

    /**
     * Activation after a wipe must rebuild from nothing, which is what makes
     * "delete and reinstall" a working workflow rather than a broken one.
     */
    public function test_the_plugin_can_be_reinstalled_after_a_wipe(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);
        $this->migrator()->runPending();
        $this->removal()();

        $container = $this->container();
        (new \Framework\Database\Lifecycle\MigratesOnActivation($container->resolve(Migrator::class)))();

        $this->assertTrue($this->db->tableExists('wp_users'));
        $this->assertCount(1, $this->db->rows('wp_plugin_migrations'));
    }

    private function migrator(): Migrator
    {
        return new Migrator(
            $this->workspace->path(),
            new WpDatabaseAdapter($this->db),
            new WpMigrationStorage($this->db),
        );
    }

    private function removal(): RemovesPluginData
    {
        return new RemovesPluginData($this->migrator(), new WpMigrationStorage($this->db));
    }

    private function container(): \DevLinkStudios\WPServiceContainer\Container
    {
        $container = new \DevLinkStudios\WPServiceContainer\Container();
        $container->instance(
            \DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface::class,
            $container
        );
        $container->instance(\DevLinkStudios\WPServiceContainer\Container::class, $container);
        $container->instance(Migrator::class, $this->migrator());

        return $container;
    }
}
