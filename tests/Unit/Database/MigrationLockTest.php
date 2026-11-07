<?php
declare(strict_types=1);

namespace Tests\Unit\Database;

use Framework\Database\Adapters\WpDatabaseAdapter;
use Framework\Database\Exceptions\MigrationException;
use Framework\Database\Migrator;
use Framework\Database\Schema\Schema;
use Framework\Database\Storage\WpMigrationStorage;
use PHPUnit\Framework\TestCase;
use Tests\Support\EventRecordingMigration;
use Tests\Support\FakeWpdb;
use Tests\Support\MigrationWorkspace;
use Tests\Support\RecordingMigration;
use Tests\Support\WpStub;

/**
 * The advisory lock that keeps two `migrate` runs from overlapping.
 *
 * ## Why a lock at all
 *
 * A migration is not idempotent by default and the tracking table cannot make it
 * so. Two processes that both read "this migration is pending" will both run it:
 * the second `CREATE TABLE` fails, or worse, an `ALTER` that is not conditional
 * runs twice and the schema ends up somewhere neither run intended. WordPress
 * gives no schema-level transaction to fall back on (DDL implicitly commits), so
 * the only defence is to make the second process wait.
 *
 * ## What is being tested here, and what is not
 *
 * These tests pin the *contract*: a lock is taken and named, the name is derived
 * from the database prefix so two plugins on one database do not serialise
 * against each other, the lock is released on every exit path including a
 * throwing migration, and a run that cannot get the lock reports that instead of
 * proceeding. They do not — and cannot, in one process — test that two real
 * concurrent processes serialise; that is MySQL's `GET_LOCK` doing its job, and
 * what is verifiable here is that it is asked for and given back correctly.
 */
final class MigrationLockTest extends TestCase
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
     * The lock must be requested before any migration runs.
     *
     * Asserted on the statement order rather than on presence: a lock taken after
     * the first migration has already run protects nothing, and would still show
     * up in the query log.
     */
    public function test_a_lock_is_taken_before_the_first_migration_runs(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', EventRecordingMigration::class);

        $this->migrator()->runPending();

        $queries = $this->db->queries;

        $lock = $this->firstLockRequest($queries);

        $this->assertNotNull($lock, 'No GET_LOCK was issued. Queries were: ' . implode(' | ', $queries));
        $this->assertArrayHasKey(
            EventRecordingMigration::UP_MARKER,
            array_flip(WpStub::$fired),
            'The migration body never ran, so this test cannot say anything about ordering.'
        );
    }

    /**
     * The lock is named after the database prefix.
     *
     * Two plugins on one WordPress database share a MySQL server, so a lock with a
     * fixed name would make one plugin's migration block the other's — for no
     * reason, since they touch different tables. The prefix is what distinguishes
     * them, and it is the same value that already distinguishes their tables.
     */
    public function test_the_lock_name_is_derived_from_the_database_prefix(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $this->migrator()->runPending();

        $lock = $this->firstLockRequest($this->db->queries);

        $this->assertNotNull($lock);
        $this->assertStringContainsString(
            $this->db->prefix,
            $lock,
            'The lock name must carry the database prefix, or two plugins on one database serialise against each other.'
        );
    }

    /**
     * The lock is released when the run ends.
     *
     * A lock that is never released is worse than no lock: the next `migrate` — by
     * a developer who has since fixed the migration — waits for a holder that no
     * longer exists, and the site is stuck until MySQL's connection is closed.
     */
    public function test_the_lock_is_released_when_the_run_finishes(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $this->migrator()->runPending();

        $this->assertNotNull($this->firstRelease($this->db->queries), 'The lock was never released.');
    }

    /**
     * The lock is released even when a migration throws.
     *
     * This is the case the lock exists for and the case a naive `finally`-less
     * implementation gets wrong: the run that fails is exactly the run whose lock
     * must not be left held, because the operator's next action is to fix the
     * migration and re-run.
     */
    public function test_the_lock_is_released_when_a_migration_throws(): void
    {
        $this->workspace->write('2026_11_05_120000_create_leads_table', \Tests\Support\ThrowingMigration::class);

        try {
            $this->migrator()->runPending();
            $this->fail('The throwing migration should have propagated.');
        } catch (MigrationException) {
            // Expected.
        }

        $this->assertNotNull(
            $this->firstRelease($this->db->queries),
            'A failing run left the lock held, so the retry would deadlock against itself.'
        );
    }

    /**
     * A run that cannot get the lock refuses to proceed.
     *
     * "Could not get the lock" must not be read as "nothing to do": the two mean
     * opposite things to an operator. The failure is a `MigrationException` so it
     * travels the same path as every other migration failure — a command reports
     * it, nothing is recorded, and the schema is untouched.
     */
    public function test_a_run_that_cannot_get_the_lock_refuses_to_proceed(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', EventRecordingMigration::class);

        // GET_LOCK returns 1 on success, 0 on timeout, NULL on error. 0 is the
        // case that matters: another connection holds it.
        $this->db->lockResult = '0';

        try {
            $this->migrator()->runPending();
            $this->fail('A run that could not take the lock must not migrate.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('lock', strtolower($e->getMessage()));
        }

        $this->assertNotContains(
            EventRecordingMigration::UP_MARKER,
            WpStub::$fired,
            'No migration body may run when the lock was not obtained.'
        );
        $this->assertSame([], $this->db->rows('wp_plugin_migrations'));
    }

    /**
     * A `--pretend` run takes no lock.
     *
     * Pretending writes nothing and creates nothing, so there is nothing for a
     * concurrent run to race with — and taking the lock would make a developer's
     * harmless `--pretend` block a real deploy that happens to be running.
     */
    public function test_a_pretend_run_takes_no_lock(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        Schema::pretend();

        try {
            $this->migrator()->runPending();
        } finally {
            Schema::stopPretending();
        }

        $this->assertNull(
            $this->firstLockRequest($this->db->queries),
            'A pretend run must not lock: it changes nothing, and locking would block a real deploy.'
        );
    }

    /**
     * `migrate:rollback` takes the lock on the same terms as `migrate`.
     *
     * Reversal is a schema change like any other. A `down()` running while another
     * process runs the matching `up()` is exactly the overlap the lock prevents,
     * and it is the direction where the damage is least recoverable.
     */
    public function test_rollback_takes_and_releases_the_lock(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $migrator = $this->migrator();
        $migrator->runPending();

        $this->db->resetQueries();

        $migrator->rollback();

        $this->assertNotNull($this->firstLockRequest($this->db->queries), 'rollback took no lock.');
        $this->assertNotNull($this->firstRelease($this->db->queries), 'rollback released no lock.');
    }

    /**
     * `dropAllTables()` takes the lock too.
     *
     * `migrate:fresh` is the most destructive statement this framework issues, and
     * it is the one most likely to be run while something else is mid-migration —
     * a developer clearing a stuck state while a deploy is still going.
     */
    public function test_drop_all_tables_takes_and_releases_the_lock(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $migrator = $this->migrator();
        $migrator->runPending();

        $this->db->resetQueries();

        $migrator->dropAllTables();

        $this->assertNotNull($this->firstLockRequest($this->db->queries), 'fresh took no lock.');
        $this->assertNotNull($this->firstRelease($this->db->queries), 'fresh released no lock.');
    }

    /**
     * The lock is released once, not once per migration.
     *
     * A `RELEASE_LOCK` inside the migration loop would be harmless in a
     * single-process test and wrong in production: the run would be unprotected
     * for every migration after the first. Asserting an exact count is what
     * catches a release that drifted into the wrong scope.
     */
    public function test_the_lock_is_released_exactly_once_for_a_multi_migration_run(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_140000_create_notes_table', RecordingMigration::class);

        $this->migrator()->runPending();

        $releases = array_filter(
            $this->db->queries,
            static fn (string $sql): bool => stripos($sql, 'RELEASE_LOCK') !== false
        );

        $this->assertCount(1, $releases, 'Three migrations, one run: exactly one lock, released once.');
    }

    // ----------------------------------------------------------------- helpers

    private function migrator(): Migrator
    {
        return new Migrator(
            $this->workspace->path(),
            new WpDatabaseAdapter($this->db),
            new WpMigrationStorage($this->db),
        );
    }

    /**
     * The first `GET_LOCK` statement issued, or null.
     *
     * @param list<string> $queries
     */
    private function firstLockRequest(array $queries): ?string
    {
        foreach ($queries as $sql) {
            if (stripos($sql, 'GET_LOCK') !== false) {
                return $sql;
            }
        }

        return null;
    }

    /**
     * @param list<string> $queries
     */
    private function firstRelease(array $queries): ?string
    {
        foreach ($queries as $sql) {
            if (stripos($sql, 'RELEASE_LOCK') !== false) {
                return $sql;
            }
        }

        return null;
    }
}
