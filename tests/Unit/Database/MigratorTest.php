<?php
declare(strict_types=1);

namespace Tests\Unit\Database;

use Framework\Database\Migration;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;
use Tests\Support\MigrationWorkspace;
use Tests\Support\RecordingMigration;
use Tests\Support\ThrowingMigration;
use Tests\Support\WpStub;

/**
 * The migrator end-to-end: discovery, batching, rollback, and the failure
 * behaviour the tracking table's honesty depends on.
 *
 * Migrations are written into a temporary directory as real files and required
 * by the migrator, so the class-name-from-filename convention is exercised for
 * real rather than stubbed around.
 */
final class MigratorTest extends TestCase
{
    private MigrationWorkspace $workspace;
    private FakeWpdb $db;
    private string $table;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->workspace = new MigrationWorkspace();
        $this->db = new FakeWpdb();
        $this->table = 'wp_plugin_migrations';
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    // -------------------------------------------------------------- tracking

    public function test_it_creates_the_tracking_table_when_absent(): void
    {
        $this->assertFalse($this->db->tableExists($this->table));

        $this->migrator()->ensureTrackingTable();

        $this->assertTrue($this->db->tableExists($this->table));
        $this->assertSame(
            ['id', 'migration', 'batch'],
            $this->db->get_col('SHOW COLUMNS FROM `' . $this->table . '`')
        );
    }

    /**
     * The tracking table is created on every entry point, so a `status()` call
     * against a fresh install must not fatal on a missing table.
     */
    public function test_the_tracking_table_is_created_lazily_by_any_read(): void
    {
        $this->assertSame([], $this->migrator()->getStatus());
        $this->assertTrue($this->db->tableExists($this->table));
    }

    /**
     * `pendingMigrations()` is the one read that runs *before* the migrator's own
     * entry point rather than inside it: `migrate` calls it to decide whether
     * there is anything to do, and only then calls `runPending()`.
     *
     * So it is the first thing to touch the tracking table on a fresh install,
     * and until it guarded itself it issued a `SELECT` against a table nothing
     * had created — the "Table doesn't exist" a first `wp wp_mini migrate` failed
     * with. The test above covers the same question through `status()`, which
     * guarded from the start and so never exercised this path.
     */
    public function test_pending_migrations_creates_the_tracking_table_when_absent(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $this->assertFalse($this->db->tableExists($this->table));

        $pending = $this->migrator()->pendingMigrations();

        $this->assertTrue($this->db->tableExists($this->table));
        $this->assertSame(['2026_11_05_100000_create_users_table'], array_column($pending, 'identifier'));
    }

    /**
     * Running it twice must not try to create the table twice — the second
     * CREATE TABLE would fail and abort a genuinely valid migration run.
     */
    public function test_creating_the_tracking_table_is_idempotent(): void
    {
        $migrator = $this->migrator();

        $migrator->ensureTrackingTable();
        $migrator->ensureTrackingTable();
        $migrator->ensureTrackingTable();

        $creates = array_filter(
            $this->db->queries,
            static fn (string $sql): bool => str_starts_with(trim($sql), 'CREATE TABLE')
        );

        $this->assertCount(1, $creates);
    }

    /**
     * An install carrying the pre-`batch` version of this table must be repaired
     * rather than every write failing with "Unknown column 'batch'".
     */
    public function test_it_adds_a_missing_batch_column_to_an_older_table(): void
    {
        $this->db->createTable($this->table, ['id', 'migration']);

        $this->migrator()->ensureTrackingTable();

        $this->assertContains('batch', $this->db->get_col('SHOW COLUMNS FROM `' . $this->table . '`'));
    }

    public function test_it_fails_loudly_when_the_tracking_table_cannot_be_created(): void
    {
        $this->db->failOn('CREATE TABLE');

        $this->expectException(\Framework\Database\Exceptions\MigrationException::class);
        $this->expectExceptionMessage('Unable to create the migration tracking table');

        $this->migrator()->ensureTrackingTable();
    }

    /**
     * A sibling table whose name matches the tracking table's *pattern* must not
     * be mistaken for it.
     *
     * The existence probe is `SHOW TABLES LIKE`, where `_` matches any character —
     * and the WordPress table prefix is full of underscores. Unescaped, the
     * pattern `wp_plugin_migrations` matches `wpxpluginxmigrations`, so the
     * CREATE is skipped and every later query fails against a table that was
     * never made.
     */
    public function test_a_table_matching_the_like_pattern_is_not_mistaken_for_the_tracking_table(): void
    {
        // Same shape as the tracking table, different name: every `_` replaced by
        // a character of its own.
        $decoy = str_replace('_', 'X', $this->table);
        $this->db->createTable($decoy, ['id', 'migration', 'batch']);

        $this->migrator()->ensureTrackingTable();

        $this->assertTrue(
            $this->db->tableExists($this->table),
            'A decoy table matching the LIKE pattern must not satisfy the existence probe.'
        );
    }

    /**
     * `wpdb::prepare()` with no placeholder is a doing-it-wrong notice on
     * WordPress 6.2+, so the schema probe must not route through it.
     *
     * The fake records every query it is handed, and `prepare()` leaves the SQL
     * untouched when there is nothing to substitute — so the assertion is on the
     * *shape* of the recorded query: a verbatim `SHOW COLUMNS FROM \`table\``,
     * with no quote-wrapping that prepare() would have introduced.
     */
    public function test_the_schema_probe_is_issued_verbatim_rather_than_through_prepare(): void
    {
        // The probe only runs when the table already exists; a fresh install
        // takes the CREATE TABLE path and returns before reaching it.
        $this->db->createTable($this->table, ['id', 'migration', 'batch']);
        $this->db->resetQueries();

        $this->migrator()->ensureTrackingTable();

        $probes = array_values(array_filter(
            $this->db->queries,
            static fn (string $sql): bool => str_starts_with(ltrim($sql), 'SHOW COLUMNS FROM')
        ));

        $this->assertNotEmpty($probes, 'The schema probe must have been issued.');
        $this->assertSame(
            'SHOW COLUMNS FROM `' . $this->table . '`',
            trim($probes[0]),
            'The probe must be a plain identifier reference, not a quoted string value.'
        );
    }

    // ------------------------------------------------------------- discovery

    public function test_it_runs_pending_migrations_oldest_first(): void
    {
        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_130000_add_status_to_leads', RecordingMigration::class);

        $applied = $this->migrator()->runPending();

        // Written out of order, applied in order: the sequence comes from the
        // filenames, never from the directory listing.
        $this->assertSame([
            '2026_11_05_100000_create_users_table',
            '2026_11_05_120000_create_leads_table',
            '2026_11_05_130000_add_status_to_leads',
        ], $applied);
    }

    /**
     * A `.php` file in the migrations directory that is not a migration — a
     * scratch file, an editor backup — must not stop the real ones running.
     */
    public function test_it_skips_files_that_do_not_follow_the_naming_convention(): void
    {
        $this->workspace->writeRaw('helpers.php', '<?php // not a migration');
        $this->workspace->writeRaw('2026-11-05-120000-wrong-separators.php', '<?php // not a migration');
        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $applied = $this->migrator()->runPending();

        $this->assertSame(['2026_11_05_120000_create_leads_table'], $applied);
    }

    public function test_it_reports_a_missing_migrations_directory(): void
    {
        $migrator = $this->migrator($this->workspace->path() . '/does-not-exist');

        $this->expectException(\Framework\Database\Exceptions\MigrationException::class);
        $this->expectExceptionMessage('does not exist');

        $migrator->discover();
    }

    public function test_pending_excludes_already_run_migrations(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $migrator = $this->migrator();
        $migrator->runPending();

        $this->assertSame([], $migrator->runPending());
        $this->assertSame([], $migrator->pendingMigrations());
    }

    /**
     * A file that follows the convention but declares the wrong class is nearly
     * always a rename that only happened on one side. The message has to name
     * the class it expected, or the fix is guesswork.
     */
    public function test_a_migration_declaring_the_wrong_class_is_reported(): void
    {
        $this->workspace->writeRaw(
            '2026_11_05_120000_create_leads_table.php',
            "<?php\n\nnamespace " . $this->workspace->namespace() . ";\n\nclass SomethingElse\n{\n}\n"
        );

        $this->expectException(\Framework\Database\Exceptions\MigrationException::class);
        $this->expectExceptionMessage('does not declare the class [CreateLeadsTable]');

        $this->migrator()->runPending();
    }

    public function test_a_class_that_is_not_a_migration_is_reported(): void
    {
        $this->workspace->writeRaw(
            '2026_11_05_120000_create_leads_table.php',
            "<?php\n\nnamespace " . $this->workspace->namespace() . ";\n\n"
            . "class CreateLeadsTable\n{\n    public function up(): void {}\n}\n"
        );

        $this->expectException(\Framework\Database\Exceptions\MigrationException::class);
        $this->expectExceptionMessage('must extend');

        $this->migrator()->runPending();
    }

    /**
     * A migration may live in any namespace — the generator writes them into the
     * project's own — so the migrator has to find the class by the short name the
     * filename implies rather than assuming a global one.
     */
    public function test_a_migration_in_its_own_namespace_is_found(): void
    {
        $path = $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        // Confirms the fixture really is namespaced, so the test cannot pass by
        // accidentally exercising a global class.
        $this->assertStringContainsString(
            'namespace ' . $this->workspace->namespace() . ';',
            (string) file_get_contents($path)
        );

        // The real assertion: it ran, which is only possible if the namespaced
        // class was located and instantiated.
        $this->assertSame(
            ['2026_11_05_100000_create_users_table'],
            $this->migrator()->runPending()
        );
    }

    // ---------------------------------------------------------------- batching

    public function test_every_migration_in_one_run_shares_a_batch_number(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $this->migrator()->runPending();

        $rows = $this->db->rows($this->table);
        $batches = array_unique(array_column($rows, 'batch'));

        $this->assertCount(1, $batches);
        $this->assertSame(1, (int) reset($batches));
    }

    /**
     * The whole point of batches: a second run gets its own number, so
     * `rollback()` undoes exactly that run and not the one before it.
     */
    public function test_a_second_run_allocates_the_next_batch_number(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $batches = $this->batchesByIdentifier();

        $this->assertSame(1, $batches['2026_11_05_100000_create_users_table']);
        $this->assertSame(2, $batches['2026_11_05_120000_create_leads_table']);
    }

    public function test_progress_is_reported_before_each_migration_runs(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $seen = [];
        $this->migrator()->runPending(static function (string $identifier) use (&$seen): void {
            $seen[] = $identifier;
        });

        $this->assertSame([
            '2026_11_05_100000_create_users_table',
            '2026_11_05_120000_create_leads_table',
        ], $seen);
    }

    /**
     * Batch numbers must never go backwards. Deriving the next number from
     * `MAX(batch)` alone makes them do exactly that once a rollback empties the
     * table: migrate (batch 1) → rollback (no rows) → migrate (batch 1 again).
     * A number that has meant two different sets of migrations makes an old
     * `--batch=1` from a transcript silently target the wrong set.
     */
    public function test_batch_numbers_do_not_restart_after_a_rollback(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $migrator = $this->migrator();

        $migrator->runPending();
        $this->assertSame([1], $this->batches());

        $migrator->rollback();
        $this->assertSame([], $this->db->rows($this->table));

        $migrator->runPending();
        $this->assertSame([2], $this->batches(), 'The re-applied migration must not reuse batch 1.');
    }

    /**
     * A batch that fails partway still consumes its number, so the retry is a
     * different batch rather than one that reuses a number already printed.
     */
    public function test_a_failed_batch_consumes_its_number(): void
    {
        $this->workspace->write('2026_11_05_120000_create_leads_table', ThrowingMigration::class);

        $migrator = $this->migrator();

        try {
            $migrator->runPending();
        } catch (\Framework\Database\Exceptions\MigrationException) {
            // Expected. Nothing was recorded, but the number is spent.
        }

        // The failing file is removed, standing in for the developer fixing it
        // between runs — a class cannot be redeclared in this process, so the
        // retry has to be a different file either way.
        $this->workspace->delete('2026_11_05_120000_create_leads_table');
        $this->workspace->write('2026_11_05_130000_add_status', RecordingMigration::class);

        $migrator->runPending();

        $this->assertSame([2], $this->batches());
    }

    /**
     * A caller whose batch threw must still be able to learn how much of it
     * landed, because `runPending()` throws rather than returning a partial list.
     *
     * The progress callback is not a substitute: it fires *before* each migration
     * runs, so counting from it reports the failing migration as applied.
     */
    public function test_last_run_applied_reports_what_was_recorded_before_a_failure(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_110000_create_leads_table', ThrowingMigration::class);
        $this->workspace->write('2026_11_05_120000_add_status', RecordingMigration::class);

        $migrator = $this->migrator();

        $announced = [];

        try {
            $migrator->runPending(function (string $identifier) use (&$announced): void {
                $announced[] = $identifier;
            });
            $this->fail('Expected the batch to fail.');
        } catch (\Framework\Database\Exceptions\MigrationException) {
            // Expected.
        }

        // The callback announced both, because it fires before each one runs...
        $this->assertCount(2, $announced);

        // ...but only the first was actually recorded.
        $this->assertSame(
            ['2026_11_05_100000_create_users_table'],
            $migrator->lastRunApplied()
        );
        $this->assertSame(
            ['2026_11_05_100000_create_users_table' => 1],
            $this->batchesByIdentifier()
        );
    }

    public function test_last_run_applied_is_empty_when_nothing_could_be_recorded(): void
    {
        $this->workspace->write('2026_11_05_110000_create_leads_table', ThrowingMigration::class);

        $migrator = $this->migrator();

        try {
            $migrator->runPending();
        } catch (\Framework\Database\Exceptions\MigrationException) {
            // Expected.
        }

        $this->assertSame([], $migrator->lastRunApplied());
    }

    /**
     * A run with nothing pending must clear the report, not leave the previous
     * run's list standing.
     *
     * `lastRunApplied()` describes the most recent `runPending()` call and no
     * earlier one. If the empty-pending path returned before clearing it, a caller
     * that ran a migration and then polled again would read a stale list claiming
     * migrations were applied that the second call never touched.
     */
    public function test_last_run_applied_is_cleared_by_a_run_with_nothing_pending(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $migrator = $this->migrator();

        $this->assertSame(['2026_11_05_100000_create_users_table'], $migrator->runPending());
        $this->assertSame(['2026_11_05_100000_create_users_table'], $migrator->lastRunApplied());

        // Nothing left to run, so this call applies nothing...
        $this->assertSame([], $migrator->runPending());

        // ...and must say so rather than echoing the first call.
        $this->assertSame([], $migrator->lastRunApplied());
    }

    /**
     * After a batch fails without recording anything, `rollback()` must target
     * the newest batch that *did* record — not the burned number.
     *
     * The danger this guards is a transcript: the failed run printed a batch
     * number, so an operator may reasonably assume `migrate:rollback` reverses
     * it. It does not, and silent confusion here means the previous batch's
     * schema comes down instead. `MigrateCommand` warns when this state arises;
     * this pins the underlying behaviour the warning describes.
     */
    public function test_rollback_after_a_failed_empty_batch_targets_the_last_recorded_batch(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $migrator = $this->migrator();
        $migrator->runPending();
        $this->assertSame([1], $this->batches());

        // A second batch appears and fails immediately, recording nothing.
        $this->workspace->write('2026_11_05_110000_create_leads_table', ThrowingMigration::class);

        try {
            $migrator->runPending();
        } catch (\Framework\Database\Exceptions\MigrationException) {
            // Expected.
        }

        // The high-water mark advanced, but no row carries it.
        $this->assertSame([1], $this->batches());

        $reverted = $migrator->rollback();

        $this->assertSame(['2026_11_05_100000_create_users_table'], $reverted);
        $this->assertSame([], $this->batches());
    }

    // --------------------------------------------------------------- rollback

    public function test_rollback_reverses_the_last_batch_newest_first(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_130000_add_status', RecordingMigration::class);

        $migrator = $this->migrator();
        $migrator->runPending();

        $reverted = $migrator->rollback();

        // Newest first: undoing forward order would apply a change on top of a
        // structure that is about to be removed.
        $this->assertSame([
            '2026_11_05_130000_add_status',
            '2026_11_05_120000_create_leads_table',
            '2026_11_05_100000_create_users_table',
        ], $reverted);
    }

    public function test_rollback_deletes_the_tracking_rows(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $migrator = $this->migrator();
        $migrator->runPending();

        $this->assertCount(1, $this->db->rows($this->table));

        $migrator->rollback();

        $this->assertSame([], $this->db->rows($this->table));
    }

    /**
     * The defining property of a batch: rollback takes the last one only, and a
     * batch applied earlier survives.
     */
    public function test_rollback_only_affects_the_most_recent_batch(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $migrator = $this->migrator();
        $migrator->runPending();

        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);
        $migrator->runPending();

        $reverted = $migrator->rollback();

        $this->assertSame(['2026_11_05_120000_create_leads_table'], $reverted);

        $rows = $this->db->rows($this->table);
        $this->assertCount(1, $rows);
        $this->assertSame('2026_11_05_100000_create_users_table', $rows[0]['migration']);
    }

    /**
     * A rolled-back migration must become pending again, or `rollback()` would
     * leave the schema reverted while the migrator still believes it is applied.
     */
    public function test_a_rolled_back_migration_runs_again(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $migrator = $this->migrator();
        $migrator->runPending();
        $migrator->rollback();

        $this->assertSame(
            ['2026_11_05_100000_create_users_table'],
            $migrator->runPending()
        );
    }

    public function test_rollback_with_nothing_applied_does_nothing(): void
    {
        $this->assertSame([], $this->migrator()->rollback());
    }

    public function test_rollback_batch_can_target_an_older_batch(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $migrator = $this->migrator();
        $migrator->runPending();

        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);
        $migrator->runPending();

        $reverted = $migrator->rollbackBatch(1);

        $this->assertSame(['2026_11_05_100000_create_users_table'], $reverted);
        $this->assertSame(
            ['2026_11_05_120000_create_leads_table'],
            array_column($this->db->rows($this->table), 'migration')
        );
    }

    /**
     * A migration recorded as ran whose file has been deleted cannot be rolled
     * back. Silently skipping it would drop the row and make it permanently
     * forgotten; reporting it is what surfaces the mistake.
     */
    public function test_rollback_reports_a_recorded_migration_whose_file_is_gone(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $migrator = $this->migrator();
        $migrator->runPending();

        $this->workspace->delete('2026_11_05_100000_create_users_table');

        $this->expectException(\Framework\Database\Exceptions\MigrationException::class);
        $this->expectExceptionMessage('its file is missing');

        $migrator->rollback();
    }

    // ----------------------------------------------------------------- status

    public function test_status_maps_every_file_to_its_batch_or_pending(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $migrator = $this->migrator();

        $before = $migrator->getStatus();
        $this->assertFalse($before['2026_11_05_100000_create_users_table']['ran']);
        $this->assertNull($before['2026_11_05_100000_create_users_table']['batch']);

        $migrator->runPending();
        $this->workspace->write('2026_11_05_130000_add_status', RecordingMigration::class);

        $after = $migrator->getStatus();

        $this->assertTrue($after['2026_11_05_100000_create_users_table']['ran']);
        $this->assertSame(1, $after['2026_11_05_100000_create_users_table']['batch']);
        $this->assertFalse($after['2026_11_05_130000_add_status']['ran']);
        $this->assertSame('AddStatus', $after['2026_11_05_130000_add_status']['class']);
    }

    public function test_status_is_ordered_oldest_first(): void
    {
        $this->workspace->write('2026_11_05_130000_add_status', RecordingMigration::class);
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $this->assertSame([
            '2026_11_05_100000_create_users_table',
            '2026_11_05_130000_add_status',
        ], array_keys($this->migrator()->getStatus()));
    }

    public function test_missing_reports_recorded_migrations_with_no_file(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $migrator = $this->migrator();
        $migrator->runPending();

        $this->assertSame([], $migrator->missing());

        $this->workspace->delete('2026_11_05_100000_create_users_table');

        $this->assertSame(['2026_11_05_100000_create_users_table'], $migrator->missing());
    }

    // ---------------------------------------------------------------- failures

    /**
     * The central guarantee: a migration whose up() throws is NOT recorded, so
     * the next run retries it instead of believing it applied.
     */
    public function test_a_failing_migration_is_not_recorded_as_ran(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', ThrowingMigration::class);

        $migrator = $this->migrator();

        try {
            $migrator->runPending();
            $this->fail('The failing migration should have propagated.');
        } catch (\Framework\Database\Exceptions\MigrationException $e) {
            $this->assertStringContainsString('2026_11_05_120000_create_leads_table', $e->getMessage());
            $this->assertStringContainsString('failed to up', $e->getMessage());
        }

        $this->assertSame(
            ['2026_11_05_100000_create_users_table'],
            array_column($this->db->rows($this->table), 'migration')
        );
    }

    /**
     * A batch that fails partway stops there. Running later migrations against a
     * schema missing the one that failed is how a "successful" run leaves a
     * database that later migrations never expected.
     */
    public function test_a_failing_migration_stops_the_rest_of_the_batch(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', ThrowingMigration::class);
        $this->workspace->write('2026_11_05_130000_add_status', RecordingMigration::class);

        try {
            $this->migrator()->runPending();
        } catch (\Framework\Database\Exceptions\MigrationException) {
            // Expected.
        }

        $this->assertSame(
            ['2026_11_05_100000_create_users_table'],
            array_column($this->db->rows($this->table), 'migration'),
            'The migration after the failure must not have run.'
        );
    }

    /**
     * The original throwable must survive as `previous`, or the real cause is
     * lost behind the wrapper and the log line says nothing useful.
     */
    public function test_the_original_failure_is_preserved(): void
    {
        $this->workspace->write('2026_11_05_120000_create_leads_table', ThrowingMigration::class);

        try {
            $this->migrator()->runPending();
            $this->fail('Expected a MigrationException.');
        } catch (\Framework\Database\Exceptions\MigrationException $e) {
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            $this->assertSame('The table could not be created.', $e->getPrevious()->getMessage());
        }
    }

    /**
     * A failed batch is resumable: the migration that failed is still pending, so
     * a later run retries it — in a fresh batch, alongside whatever else is still
     * outstanding — rather than leaving it permanently unapplied.
     *
     * The retry uses a *different* migration to prove the point. A PHP class
     * cannot be redeclared once loaded, so "edit the failing file and re-run in
     * the same process" is not a thing the migrator could support; the realistic
     * recovery is that the developer fixes the file and the next *process* (the
     * next `wp wp_mini migrate`) picks it up. What this process must guarantee is
     * the part it can: the failure left the migration pending and the table
     * honest.
     */
    public function test_a_failed_batch_leaves_the_failed_migration_pending(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', ThrowingMigration::class);

        $migrator = $this->migrator();

        try {
            $migrator->runPending();
        } catch (\Framework\Database\Exceptions\MigrationException) {
            // Expected.
        }

        $this->assertSame(
            ['2026_11_05_120000_create_leads_table'],
            array_map(
                static fn (\Framework\Database\MigrationFile $m): string => $m->identifier,
                $migrator->pendingMigrations()
            )
        );

        // And the batch counter did not advance past the one that half-ran, so a
        // later successful run does not collide with it.
        $this->assertSame(
            [1],
            array_values(array_unique(array_map(
                static fn (array $row): int => (int) $row['batch'],
                $this->db->rows($this->table)
            )))
        );
    }

    /**
     * A migration that ran but could not be recorded is the one state that must
     * never pass silently: the schema change is real, and forgetting it would
     * re-run the migration on the next invocation.
     */
    public function test_a_run_that_cannot_be_recorded_is_reported(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $migrator = $this->migrator();
        $migrator->ensureTrackingTable();
        $this->db->failOn('INSERT INTO');

        $this->expectException(\Framework\Database\Exceptions\MigrationException::class);
        $this->expectExceptionMessage('recording it in');

        $migrator->runPending();
    }

    public function test_a_rollback_that_fails_stops_and_keeps_the_row(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', ThrowingMigration::class);

        $migrator = $this->migrator();
        $migrator->ensureTrackingTable();

        // Record it by hand: up() would throw, but the state under test is a
        // migration that applied and now cannot be reversed.
        $this->db->seed($this->table, ['migration' => '2026_11_05_100000_create_users_table', 'batch' => 1]);

        try {
            $migrator->rollback();
            $this->fail('Expected a MigrationException.');
        } catch (\Framework\Database\Exceptions\MigrationException $e) {
            $this->assertStringContainsString('failed to down', $e->getMessage());
        }

        $this->assertCount(
            1,
            $this->db->rows($this->table),
            'A migration whose down() failed must stay recorded.'
        );
    }

    /**
     * Migrations resolved through the container can declare dependencies. This
     * is what makes a migration able to take a `$wpdb` rather than reaching for
     * the global.
     */
    public function test_a_migration_may_declare_constructor_dependencies(): void
    {
        $this->workspace->writeRaw(
            '2026_11_05_110000_inject_dependency.php',
            "<?php\n\nnamespace Tests\\Support;\n\n"
            . "use Framework\\Database\\Migration;\n"
            . "use Tests\\Support\\FakeWpdb;\n\n"
            . "class InjectDependency extends Migration\n"
            . "{\n"
            . "    public function __construct(private readonly FakeWpdb \$db) {}\n\n"
            . "    public function up(): void\n"
            . "    {\n"
            . "        \$this->db->query('CREATE TABLE `injected` (`id` bigint)');\n"
            . "    }\n\n"
            . "    public function down(): void\n"
            . "    {\n"
            . "        \$this->db->query('DROP TABLE `injected`');\n"
            . "    }\n"
            . "}\n"
        );

        // Bound through `app()`, which is the container the migrator reaches.
        // Note this is NOT the same container the migrator's own $wpdb came from:
        // the migrator was handed its handle explicitly, and the migration gets
        // the container's copy. That the two agree is the point of the test.
        app()->instance(FakeWpdb::class, $this->db);

        $applied = $this->migrator()->runPending();

        $this->assertSame(['2026_11_05_110000_inject_dependency'], $applied);
        $this->assertTrue(
            $this->db->tableExists('injected'),
            'The migration should have been constructed with the container-bound FakeWpdb.'
        );
    }

    // ----------------------------------------------------------------- helpers

    private function migrator(?string $path = null): \Framework\Database\Migrator
    {
        return new \Framework\Database\Migrator($path ?? $this->workspace->path(), $this->db);
    }

    /**
     * @return array<string, int>
     */
    private function batchesByIdentifier(): array
    {
        $out = [];

        foreach ($this->db->rows($this->table) as $row) {
            $out[(string) $row['migration']] = (int) $row['batch'];
        }

        return $out;
    }

    /**
     * The distinct batch numbers currently recorded.
     *
     * @return list<int>
     */
    private function batches(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['batch'],
            $this->db->rows($this->table)
        )));
    }
}
