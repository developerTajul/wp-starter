<?php
declare(strict_types=1);

namespace Tests\Integration\Database;

use Framework\Database\Adapters\WpDatabaseAdapter;
use Framework\Database\Migrator;
use Framework\Database\Schema\Schema;
use Framework\Database\Storage\WpMigrationStorage;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;
use Tests\Support\MigrationWorkspace;
use Tests\Support\SchemaMigration;
use Tests\Support\WpStub;

/**
 * What `migrate --pretend` is and is not allowed to change.
 *
 * ## The promise `--pretend` makes
 *
 * A dry run is the one command an operator reaches for when they are unsure, and
 * its entire value is that it is *consequence-free*: you may point it at
 * production precisely because it is supposed to leave nothing behind. If it
 * writes anything — a row, a table, an option — it has silently broken the only
 * guarantee that made it safe to run.
 *
 * ## Why this is asserted against state and not against recorded SQL
 *
 * The suite's existing coverage of this read `$db->queries` and checked that no
 * `INSERT` had run. That is exactly the shape of assertion this audit exists to
 * remove: `FakeWpdb::query()` records a statement *before* parsing it, so a
 * fragment present in `queries` proves only that something tried. A tracking
 * table that was created and a batch option that was written leave no trace in
 * that list worth reading, and both were happening.
 *
 * So every assertion below asks the database and the option store directly: does
 * this table exist, does this option hold a value, are these rows present. That
 * is what an operator would check on the other side of a dry run, and it is the
 * only question whose answer means anything.
 *
 * ## The two side effects this pins
 *
 * 1. `ensureTrackingTable()` runs before anything asks whether the run is
 *    pretending, so a dry run *creates* `{prefix}plugin_migrations` — a table
 *    that did not exist, on a database the operator believed was untouched.
 * 2. `rememberBatch()` runs before the `isPretending()` guard that protects
 *    `record()`, so a dry run *writes* the batch high-water option. That one is
 *    worse than a stray row: the option never goes backwards, so a dry run
 *    permanently advances the numbering and the next real batch is numbered from
 *    a run that did nothing.
 */
final class MigratePretendTest extends TestCase
{
    private FakeWpdb $db;

    private MigrationWorkspace $workspace;

    private ?\wpdb $restoreGlobal = null;

    protected function setUp(): void
    {
        $this->db = new FakeWpdb();
        $this->workspace = new MigrationWorkspace();

        Schema::useAdapter(new WpDatabaseAdapter($this->db));

        $previous = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $this->db;
        $this->restoreGlobal = $previous;

        // The option store is global to the process, as WordPress's is. Cleared
        // so a batch number left by another test cannot look like one this run
        // wrote.
        WpStub::reset();
    }

    protected function tearDown(): void
    {
        Schema::stopPretending();
        Schema::useAdapter(null);

        if ($this->restoreGlobal instanceof \wpdb) {
            $GLOBALS['wpdb'] = $this->restoreGlobal;
        } else {
            unset($GLOBALS['wpdb']);
        }

        WpStub::reset();
        $this->workspace->cleanup();

        parent::tearDown();
    }

    /**
     * **Side effect 1.** A dry run must not create the tracking table.
     *
     * The table is bookkeeping for work that *happened*. A pretend run created no
     * work, so the table has nothing to hold — and creating it means a dry run
     * against production leaves a schema change behind, which is the one thing it
     * promised not to do.
     */
    public function test_a_pretend_run_creates_no_tracking_table(): void
    {
        $this->workspace->write('2026_11_07_100000_create_users_table', SchemaMigration::class);

        $this->runPretending();

        $this->assertFalse(
            $this->db->tableExists('wp_plugin_migrations'),
            'A dry run created the migrations tracking table. The operator asked what a migration '
            . 'would do, and got a schema change instead.'
        );
    }

    /**
     * **Side effect 2.** A dry run must not write the batch high-water mark.
     *
     * Asserted by value, not by presence: the option is only ever written when it
     * increases, so the failure this catches is a number that moved — and a test
     * that only asked "is the key set" would pass on a run that wrote the same
     * value back.
     */
    public function test_a_pretend_run_does_not_advance_the_batch_number(): void
    {
        $this->workspace->write('2026_11_07_100000_create_users_table', SchemaMigration::class);

        $before = get_option('wp_plugin_migrations_batch', null);

        $this->runPretending();

        $this->assertSame(
            $before,
            get_option('wp_plugin_migrations_batch', null),
            'A dry run advanced the batch high-water mark. That value never goes backwards, so the '
            . 'next real batch is numbered from a run that created nothing — and the gap is '
            . 'permanent and unexplained.'
        );
    }

    /**
     * And no rows, which is what the old assertion was reaching for.
     *
     * Checked as well as the two above rather than instead of them: the table
     * being absent and the table being empty are different states, and a fix that
     * only stopped the *writes* would leave an empty table to explain.
     */
    public function test_a_pretend_run_records_no_migrations_as_applied(): void
    {
        $this->workspace->write('2026_11_07_100000_create_users_table', SchemaMigration::class);

        $this->runPretending();

        $this->assertSame([], $this->db->rows('wp_plugin_migrations'));
    }

    /**
     * The thing `--pretend` *is* for keeps working: the statements are compiled
     * and reported.
     *
     * Without this, every assertion above passes trivially on an implementation
     * that records nothing at all — a dry run that does not tell you what it would
     * do is safe and useless.
     */
    public function test_a_pretend_run_still_reports_the_statements_it_would_issue(): void
    {
        $this->workspace->write('2026_11_07_100000_create_users_table', SchemaMigration::class);

        $recorded = $this->runPretending();

        $this->assertNotEmpty($recorded, 'A dry run reported nothing; it is safe because it is inert.');

        $this->assertStringContainsString(
            'CREATE TABLE',
            implode("\n", $recorded),
            'The reported statements do not include the CREATE the migration would have run.'
        );
    }

    /**
     * A real run *does* create the table and record the row.
     *
     * The control. Assertions that something did not happen are worth very little
     * on their own — they pass on a migrator that does nothing at all — so the
     * same setup is run without pretending and the opposite outcome required.
     */
    public function test_a_real_run_creates_the_tracking_table_and_records_the_migration(): void
    {
        $this->workspace->write('2026_11_07_100000_create_users_table', SchemaMigration::class);

        $migrator = new Migrator(
            $this->workspace->path(),
            new WpDatabaseAdapter($this->db),
            new WpMigrationStorage($this->db),
        );

        $applied = $migrator->up();

        $this->assertSame(['2026_11_07_100000_create_users_table'], $applied);
        $this->assertTrue($this->db->tableExists('wp_plugin_migrations'));
        $this->assertCount(1, $this->db->rows('wp_plugin_migrations'));
        $this->assertSame(1, (int) get_option('wp_plugin_migrations_batch', 0));
    }

    /**
     * Run the workspace's migrations under `Schema::pretend()`.
     *
     * The pretend window is opened and closed around the whole run, which is what
     * `MigrateCommand::handle()` does — so a side effect that happens outside the
     * window is a side effect the command also has.
     *
     * @return list<string> The statements the run would have issued.
     */
    private function runPretending(): array
    {
        $migrator = new Migrator(
            $this->workspace->path(),
            new WpDatabaseAdapter($this->db),
            new WpMigrationStorage($this->db),
        );

        Schema::pretend();

        try {
            $migrator->up();
        } finally {
            return Schema::stopPretending();
        }
    }
}
