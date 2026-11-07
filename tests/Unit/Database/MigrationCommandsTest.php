<?php
declare(strict_types=1);

namespace Tests\Unit\Database;

use Framework\Config\Config;
use Framework\Database\Adapters\WpDatabaseAdapter;
use Framework\Database\Commands\MigrateCommand;
use Framework\Database\Commands\MigrateDumpCommand;
use Framework\Database\Commands\MigrateRefreshCommand;
use Framework\Database\Commands\MigrateResetCommand;
use Framework\Database\Commands\MigrateRollbackCommand;
use Framework\Database\Commands\MigrateStatusCommand;
use Framework\Database\Commands\MakeMigrationCommand;
use Framework\Database\Migrator;
use Framework\Database\Schema\Schema;
use Framework\Database\Schema\SchemaDumper;
use Framework\Database\Storage\WpMigrationStorage;
use PHPUnit\Framework\TestCase;
use Tests\Support\DownThrowingMigration;
use Tests\Support\EventRecordingMigration;
use Tests\Support\FakeWpdb;
use Tests\Support\MigrationWorkspace;
use Tests\Support\RecordingMigration;
use Tests\Support\SchemaMigration;
use Tests\Support\ThrowingMigration;
use Tests\Support\WpStub;

/**
 * The console commands, driven directly.
 *
 * The commands are WP-CLI-shaped but not WP-CLI-dependent: they take the
 * migrator by constructor injection and report through the base `Command`'s
 * helpers, which are no-ops when `\WP_CLI` is absent. So the suite can run them
 * for real and read the messages off the subclasses below.
 *
 * That the real command classes are exercised — rather than reimplementations —
 * is the point: a command whose output said "migrated" while doing nothing would
 * fail here.
 */
final class MigrationCommandsTest extends TestCase
{
    private MigrationWorkspace $workspace;
    private FakeWpdb $db;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->workspace = new MigrationWorkspace();
        $this->db = new FakeWpdb();

        // The migrations these tests run reach the schema layer through the
        // static `Schema` entry points, not through anything injected — that is
        // how a real migration is written. So the seam has to be installed here
        // for the same reason `WpDatabaseAdapterTest` installs it: without it
        // `Schema::` falls back to constructing its own adapter over the `$wpdb`
        // global, which no test has, and every migration that touches a table
        // fails with "No $wpdb instance is available".
        Schema::useAdapter(new WpDatabaseAdapter($this->db));
    }

    protected function tearDown(): void
    {
        // Handed back, or the next test class to call `Schema::` inherits this
        // one's fake. `stopPretending()` first, because a test that failed
        // mid-pretend would otherwise leave the *pinned* adapter in the slot and
        // the clear below would not be enough to reset the flag.
        Schema::stopPretending();
        Schema::useAdapter(null);

        $this->workspace->cleanup();
    }

    public function test_migrate_runs_pending_migrations_and_reports_the_count(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $command = $this->migrateCommand();
        $command->handle([], []);

        $this->assertStringContainsString('2 migration(s)', implode("\n", $command->messages));
        $this->assertCount(2, $this->db->rows('wp_plugin_migrations'));
    }

    /**
     * A second run must say so rather than reporting a successful migration of
     * zero things, which reads as "something happened".
     */
    public function test_migrate_reports_when_there_is_nothing_to_do(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $migrator = $this->migrator();
        $migrator->runPending();

        $command = $this->migrateCommand();
        $command->handle([], []);

        $this->assertStringContainsString('Nothing to migrate', implode("\n", $command->messages));
    }

    /**
     * A failing migration must be reported without a stack trace escaping to the
     * terminal, and the message must name the migration.
     */
    public function test_migrate_reports_a_failure_with_the_cause(): void
    {
        $this->workspace->write('2026_11_05_120000_create_leads_table', ThrowingMigration::class);

        $command = $this->migrateCommand();
        $command->handle([], []);

        $output = implode("\n", $command->messages);

        $this->assertStringContainsString('2026_11_05_120000_create_leads_table', $output);
        $this->assertStringContainsString('Caused by', $output);
        $this->assertStringContainsString(ThrowingMigration::MESSAGE, $output);
    }

    public function test_migrate_reports_a_missing_directory_rather_than_failing(): void
    {
        $migrator = new Migrator(
            $this->workspace->path() . '/absent',
            new WpDatabaseAdapter($this->db),
            new WpMigrationStorage($this->db),
        );

        $command = new TestableMigrateCommand($migrator);
        $command->handle([], []);

        $this->assertStringContainsString('does not exist', implode("\n", $command->messages));
    }

    public function test_rollback_reverses_the_last_batch(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $this->migrator()->runPending();

        $command = $this->rollbackCommand();
        $command->handle([], []);

        $this->assertStringContainsString('Rolled back 1 migration(s)', implode("\n", $command->messages));
        $this->assertSame([], $this->db->rows('wp_plugin_migrations'));
    }

    public function test_rollback_with_nothing_applied_reports_so(): void
    {
        $command = $this->rollbackCommand();
        $command->handle([], []);

        $this->assertStringContainsString('Nothing to roll back', implode("\n", $command->messages));
    }

    public function test_rollback_accepts_an_explicit_batch(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $command = $this->rollbackCommand();
        $command->handle([], ['batch' => '1']);

        $this->assertSame(
            ['2026_11_05_120000_create_leads_table'],
            array_column($this->db->rows('wp_plugin_migrations'), 'migration')
        );
    }

    /**
     * `--batch=all` would cast to 0 and silently match a batch that cannot exist,
     * reporting "nothing to roll back" for a command the operator believes
     * succeeded. It has to be rejected instead.
     */
    public function test_rollback_rejects_an_invalid_batch_argument(): void
    {
        $command = $this->rollbackCommand();
        $command->handle([], ['batch' => 'all']);

        $output = implode("\n", $command->messages);

        $this->assertStringContainsString('not a valid batch number', $output);
        $this->assertStringNotContainsString('Nothing to roll back', $output);
    }

    public function test_status_lists_every_migration_with_its_batch(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $command = $this->statusCommand();
        $command->handle([], []);

        $output = implode("\n", $command->messages);

        $this->assertStringContainsString('2026_11_05_100000_create_users_table', $output);
        $this->assertStringContainsString('2026_11_05_120000_create_leads_table', $output);
        $this->assertStringContainsString('CreateUsersTable', $output);
        $this->assertStringContainsString('1 pending', $output);
    }

    public function test_status_reports_migrations_whose_files_are_missing(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $this->workspace->delete('2026_11_05_100000_create_users_table');

        $command = $this->statusCommand();
        $command->handle([], []);

        $output = implode("\n", $command->messages);

        $this->assertStringContainsString('recorded as ran but their files are missing', $output);
    }

    public function test_status_handles_an_empty_directory(): void
    {
        $command = $this->statusCommand();
        $command->handle([], []);

        $this->assertStringContainsString('No migrations found', implode("\n", $command->messages));
    }

    // ------------------------------------------------------------------- step

    /**
     * The whole point of `--step`: three migrations produce three batches, not one.
     *
     * Asserted on the batch *column* rather than on the count, because "3
     * migrations recorded" is equally true of the non-step run — only distinct
     * batch numbers show that each is individually reversible.
     */
    public function test_step_records_each_migration_in_its_own_batch(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_140000_create_notes_table', RecordingMigration::class);

        $command = $this->migrateCommand();
        $command->handle([], ['step' => true]);

        $batches = array_column($this->db->rows('wp_plugin_migrations'), 'batch');

        $this->assertCount(3, $batches);
        $this->assertSame([1, 2, 3], array_map('intval', $batches));
    }

    /**
     * A plain run is still one batch — `--step` must not have become the default.
     */
    public function test_without_step_every_migration_shares_one_batch(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $this->migrateCommand()->handle([], []);

        $batches = array_map('intval', array_column($this->db->rows('wp_plugin_migrations'), 'batch'));

        $this->assertSame([1, 1], $batches);
    }

    /**
     * The behaviour `--step` exists to enable: roll back exactly one migration.
     */
    public function test_step_makes_a_single_migration_rollbackable(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $this->migrateCommand()->handle([], ['step' => true]);

        $this->rollbackCommand()->handle([], []);

        $this->assertSame(
            ['2026_11_05_100000_create_users_table'],
            array_column($this->db->rows('wp_plugin_migrations'), 'migration')
        );
    }

    /**
     * Batches are numbered oldest-first, so migrational order survives. A `--step`
     * run that handed out descending numbers would make `migrate:rollback` reverse
     * the oldest migration.
     */
    public function test_step_numbers_batches_in_migration_order(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $this->migrateCommand()->handle([], ['step' => true]);

        $rows = $this->db->rows('wp_plugin_migrations');

        $this->assertLessThan(
            (int) $rows[1]['batch'],
            (int) $rows[0]['batch'],
            'The older migration must be recorded in the lower batch.'
        );
    }

    /**
     * A failure mid-sweep stops there, and everything already stepped stays
     * recorded under its own batch — which is what makes the failure recoverable
     * by rolling back just the failed step's predecessor.
     */
    public function test_step_stops_at_a_failing_migration_and_keeps_earlier_batches(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', ThrowingMigration::class);

        $command = $this->migrateCommand();
        $command->handle([], ['step' => true]);

        $output = implode("\n", $command->messages);

        $this->assertStringContainsString(ThrowingMigration::MESSAGE, $output);
        $this->assertStringContainsString('Recorded 1 of 2 migration(s)', $output);
        $this->assertSame(
            ['2026_11_05_100000_create_users_table'],
            array_column($this->db->rows('wp_plugin_migrations'), 'migration')
        );
    }

    /**
     * `--step=false` is not a stepwise run, for the same reason `--pretend=false`
     * is not a pretend run.
     */
    public function test_step_ignores_an_explicit_false(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $this->migrateCommand()->handle([], ['step' => 'false']);

        $batches = array_map('intval', array_column($this->db->rows('wp_plugin_migrations'), 'batch'));

        $this->assertSame([1, 1], $batches);
    }

    /**
     * `--step --pretend` together must behave: the bodies run, nothing is written,
     * and no batch number is consumed by the dry run. A pretend run that burned
     * batch numbers would make the next real `migrate` start at a batch the
     * operator never saw.
     */
    public function test_step_and_pretend_together_write_nothing_and_say_so(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);

        $command = $this->migrateCommand();
        $command->handle([], ['step' => true, 'pretend' => true]);

        $output = implode("\n", $command->messages);

        $this->assertSame([], $this->db->rows('wp_plugin_migrations'));
        $this->assertStringContainsString('one batch each', $output);
        $this->assertStringContainsString('Pretended to migrate 1 migration(s)', $output);
    }

    // ----------------------------------------------------------------- pretend

    /**
     * The whole point of `--pretend`: the tracking table must come back empty.
     *
     * A pretend run that wrote rows would be worse than no pretend run at all —
     * it would mark migrations as applied that never ran, and the next real
     * `migrate` would skip them, leaving the schema permanently missing whatever
     * they were supposed to create.
     */
    public function test_pretend_leaves_the_tracking_table_empty(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);

        $command = $this->migrateCommand();
        $command->handle([], ['pretend' => true]);

        $this->assertSame([], $this->db->rows('wp_plugin_migrations'));
        $this->assertStringContainsString('Pretended to migrate 1 migration(s)', implode("\n", $command->messages));
    }

    /**
     * `up()` still runs — the statements are compiled and handed to the adapter,
     * they are simply recorded rather than executed. If the body were skipped
     * outright the output would be empty and the command would be describing
     * nothing.
     */
    public function test_pretend_runs_the_migration_body_but_records_the_sql(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);

        $command = $this->migrateCommand();
        $command->handle([], ['pretend' => true]);

        $output = implode("\n", $command->messages);

        $this->assertStringContainsString('CREATE TABLE', $output);
        $this->assertStringContainsString('wp_users', $output);
        $this->assertStringContainsString('1 statement(s) would have been issued', $output);
    }

    /**
     * The honest limit, asserted rather than only documented: the recording seam
     * is the schema adapter, so a migration's raw `$wpdb` calls are invisible. If
     * the command stopped saying so, an operator would read a pretend run as a
     * complete account of the work and be wrong.
     */
    public function test_pretend_states_that_raw_database_calls_are_not_covered(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);

        $command = $this->migrateCommand();
        $command->handle([], ['pretend' => true]);

        $this->assertStringContainsString(
            'Raw $wpdb',
            implode("\n", $command->messages)
        );
    }

    /**
     * A pretend run that issues nothing must not print the caveat about raw
     * queries: there is nothing to have missed, and a warning shown on every
     * empty run is one an operator learns to scroll past.
     */
    public function test_pretend_omits_the_caveat_when_nothing_was_recorded(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $command = $this->migrateCommand();
        $command->handle([], ['pretend' => true]);

        $output = implode("\n", $command->messages);

        $this->assertStringContainsString('0 statement(s) would have been issued', $output);
        $this->assertStringNotContainsString('Raw $wpdb', $output);
    }

    /**
     * A bare `--pretend` arrives as boolean true from WP-CLI, `--pretend=1` as the
     * string `'1'`, and both mean the same thing.
     */
    public function test_pretend_accepts_a_string_flag_value(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);

        $command = $this->migrateCommand();
        $command->handle([], ['pretend' => '1']);

        $this->assertSame([], $this->db->rows('wp_plugin_migrations'));
    }

    /**
     * `--pretend=false` must not pretend. A truthiness test would read the string
     * `'false'` as true and silently turn a real migration into a dry run — the
     * operator would believe the schema had been created.
     */
    public function test_pretend_ignores_an_explicit_false(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);

        $command = $this->migrateCommand();
        $command->handle([], ['pretend' => 'false']);

        $output = implode("\n", $command->messages);

        $this->assertStringNotContainsString('Pretending', $output);
        $this->assertCount(1, $this->db->rows('wp_plugin_migrations'));
    }

    /**
     * `--pretend=off` must not pretend, for the same reason `--pretend=false`
     * must not.
     *
     * `off` is in the same family as `false`/`no`/`0` and is what a shell script
     * or a CI variable spelling `PRETEND=off` produces. It was missing from the
     * falsy list while `migrate:fresh`'s `--force` check accepted it, so the same
     * spelling meant "no" in one command and "yes" in another — and here "yes"
     * writes nothing at all while reporting that it did.
     *
     * Every falsy spelling is checked rather than `off` alone, because the defect
     * is a list that drifted, not a single missing word.
     */
    public function test_pretend_ignores_every_falsy_spelling(): void
    {
        foreach (['false', '0', 'no', 'off', 'FALSE', 'Off', ''] as $spelling) {
            $this->setUp();

            $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);

            $command = $this->migrateCommand();
            $command->handle([], ['pretend' => $spelling]);

            $this->assertStringNotContainsString(
                'Pretending',
                implode("\n", $command->messages),
                sprintf('--pretend=%s was read as a dry run; it means "do not pretend".', $spelling)
            );
            $this->assertCount(
                1,
                $this->db->rows('wp_plugin_migrations'),
                sprintf('--pretend=%s recorded nothing; it should have run for real.', $spelling)
            );
        }
    }

    /**
     * A numeric spelling of zero means "no" even when it is not the string `'0'`.
     *
     * ## Why the word list could not cover this
     *
     * `FALSY_FLAG_VALUES` matches spellings, and the list can only hold the ones
     * somebody thought to write down. Zero has more spellings than `'0'`, and the
     * ones that actually reach WP-CLI come from whatever produced the value:
     * `--force=0.0` from a float-valued CI variable, `--pretend=00` from a
     * zero-padded id, `--force=-0` from a computed expression, `--pretend=0e1`
     * from a scientific-notation round trip. Each arrived as a string the list did
     * not know, fell through to the final truthiness test, and read as "yes".
     *
     * The direction of that mistake is what makes it worth a test rather than a
     * note: these flags guard destructive work, so a value whose numeric meaning
     * is zero being read as "proceed" does the opposite of what its author asked.
     * Reading it as a *number* rather than a string is what closes the set —
     * `'0.00'`, `'0.0e0'` and anything else PHP parses to `0.0` is covered by the
     * same comparison, so the check cannot drift the way the list did.
     */
    public function test_pretend_ignores_numeric_spellings_of_zero(): void
    {
        foreach (['0.0', '00', '-0', '0e1', '-0.0', '0.000', '00000'] as $spelling) {
            $this->setUp();

            $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);

            $command = $this->migrateCommand();
            $command->handle([], ['pretend' => $spelling]);

            $this->assertStringNotContainsString(
                'Pretending',
                implode("\n", $command->messages),
                sprintf('--pretend=%s was read as a dry run; it is numerically zero and means "no".', $spelling)
            );
            $this->assertCount(
                1,
                $this->db->rows('wp_plugin_migrations'),
                sprintf('--pretend=%s recorded nothing; it should have run for real.', $spelling)
            );
        }
    }

    /**
     * A *non-zero* number is still a yes.
     *
     * The mirror of the test above, and the reason the check is "parses to zero"
     * rather than "looks like a number". A rule that refused anything numeric
     * would silently disable `--batch=2`-style flags, and the remedy for a
     * too-narrow falsy list must not be a too-wide one.
     */
    public function test_pretend_still_accepts_a_non_zero_number(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);

        $command = $this->migrateCommand();
        $command->handle([], ['pretend' => '1']);

        $this->assertStringContainsString(
            'Pretending',
            implode("\n", $command->messages),
            '--pretend=1 is a non-zero number and means "yes".'
        );
        $this->assertCount(
            0,
            $this->db->rows('wp_plugin_migrations'),
            'A dry run must not record a migration.'
        );
    }

    /**
     * The real run afterwards must still be a real run.
     *
     * `Schema::pretend()` pins the adapter into a static slot and
     * `stopPretending()` hands it back; if that restore leaked, every later
     * migration in the process would silently become a dry run — a failure that
     * would only show up as a missing table much later.
     *
     * The adapter is re-installed before the second run, and that is not the test
     * working around a bug: `stopPretending()` restoring the *container-resolved*
     * adapter is the contract, and in a process with no container that means the
     * next `Schema::` call builds one over the `$wpdb` global. A test has no such
     * global, so it stands in for the container by re-supplying the same fake —
     * exactly what `useAdapter()` exists for.
     */
    public function test_pretending_is_not_left_on_after_handle_returns(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);

        $this->migrateCommand()->handle([], ['pretend' => true]);

        $this->assertFalse(Schema::isPretending());

        Schema::useAdapter(new WpDatabaseAdapter($this->db));

        $command = $this->migrateCommand();
        $command->handle([], []);

        $this->assertStringContainsString('Migrated 1 migration(s)', implode("\n", $command->messages));
        $this->assertCount(1, $this->db->rows('wp_plugin_migrations'));
    }

    /**
     * A pretend run against an up-to-date database reports that, rather than
     * pretending to have done nothing.
     */
    public function test_pretend_reports_when_there_is_nothing_pending(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $this->migrator()->runPending();

        $command = $this->migrateCommand();
        $command->handle([], ['pretend' => true]);

        $this->assertStringContainsString('Nothing to migrate', implode("\n", $command->messages));
    }

    // --------------------------------------------------------------- --pending

    /**
     * `--pending` narrows the listing to what has not run.
     */
    public function test_status_pending_lists_only_unrun_migrations(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $command = $this->statusCommand();
        $command->handle([], ['pending' => true]);

        $output = implode("\n", $command->messages);

        $this->assertStringContainsString('2026_11_05_120000_create_leads_table', $output);
        $this->assertStringNotContainsString('2026_11_05_100000_create_users_table', $output);
    }

    /**
     * The count on the last line describes what was listed, not the whole
     * directory — a filtered listing reporting "2 total" reads as a bug.
     */
    public function test_status_pending_counts_only_what_it_listed(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $command = $this->statusCommand();
        $command->handle([], ['pending' => true]);

        $this->assertStringContainsString(
            '1 migration(s) total, 1 pending',
            implode("\n", $command->messages)
        );
    }

    /**
     * An up-to-date database says so. "No migrations found" would be false — the
     * files are right there — and an empty table under a header reads as a
     * rendering fault rather than as the answer "nothing to do".
     */
    public function test_status_pending_says_nothing_pending_on_an_up_to_date_database(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $command = $this->statusCommand();
        $command->handle([], ['pending' => true]);

        $output = implode("\n", $command->messages);

        $this->assertStringContainsString('Nothing pending', $output);
        $this->assertStringNotContainsString('No migrations found', $output);
        $this->assertStringNotContainsString('Ran?', $output, 'An empty table must not be printed.');
    }

    /**
     * A row whose file was deleted is *not* pending — it cannot be run — but it is
     * the state most worth surfacing, and a filtered listing is exactly where it
     * would go unnoticed.
     */
    public function test_status_pending_still_reports_missing_files(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $this->workspace->delete('2026_11_05_100000_create_users_table');

        $command = $this->statusCommand();
        $command->handle([], ['pending' => true]);

        $this->assertStringContainsString(
            'recorded as ran but their files are missing',
            implode("\n", $command->messages)
        );
    }

    /**
     * Without the flag the full listing is unchanged — `--pending` must not have
     * become the default.
     */
    public function test_status_without_pending_still_lists_everything(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $command = $this->statusCommand();
        $command->handle([], []);

        $output = implode("\n", $command->messages);

        $this->assertStringContainsString('2026_11_05_100000_create_users_table', $output);
        $this->assertStringContainsString('2026_11_05_120000_create_leads_table', $output);
        $this->assertStringContainsString('2 migration(s) total, 1 pending', $output);
    }

    /**
     * `--pending=false` is not a request for the flag.
     */
    public function test_status_pending_ignores_an_explicit_false(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $command = $this->statusCommand();
        $command->handle([], ['pending' => 'false']);

        $this->assertStringContainsString(
            '2026_11_05_100000_create_users_table',
            implode("\n", $command->messages)
        );
    }

    // ----------------------------------------------------------------- events

    /**
     * The three hook constants must resolve to prefixed action names.
     *
     * Asserted on the resolved prefix rather than the literal string, because the
     * whole point of reading it from `app.prefix` is that a rebranded plugin fires
     * its own namespaced hooks. A test that hardcoded `wp_mini_laravel_` would pass
     * for a literal and still be wrong the moment the plugin is rebranded — the
     * failure this test exists to catch is a hardcoded prefix, so it must derive
     * the expectation from the same config the code reads.
     */
    public function test_migration_events_are_prefixed_with_the_app_prefix(): void
    {
        $prefix = (string) config('app.prefix', 'wp_mini_laravel_');

        $this->workspace->write('2026_11_05_100000_create_users_table', EventRecordingMigration::class);

        $this->migrator()->runPending();

        $this->assertContains($prefix . Migrator::HOOK_MIGRATION_STARTING, WpStub::$fired);
        $this->assertContains($prefix . Migrator::HOOK_MIGRATION_FINISHED, WpStub::$fired);
    }

    /**
     * The starting hook must fire *before* the migration body runs.
     *
     * Presence alone is not the contract. A listener subscribing to this hook
     * exists to do something the migration is about to depend on — open a log, take
     * a lock, snapshot a table — so a `starting` that fired after `up()` would
     * satisfy every "did it fire" assertion and be worthless for its one purpose.
     *
     * The migration leaves a marker in {@see WpStub::$fired} as its body runs, and
     * so does the hook, so both land in one ordered list and the real interleaving
     * is what gets compared. The alternative — a static on the migration class —
     * does not work: the workspace copies the template into a new class, so the
     * running migration's static is not the template's. See the migration's own
     * docblock.
     */
    public function test_the_starting_hook_fires_before_the_migration_body(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', EventRecordingMigration::class);

        $prefix = (string) config('app.prefix', 'wp_mini_laravel_');
        $starting = $prefix . Migrator::HOOK_MIGRATION_STARTING;

        add_action($starting, static function () use ($starting): void {
            WpStub::$fired[] = $starting . ':listener';
        }, 10, 0);

        $this->migrator()->runPending();

        $this->assertSame(
            [
                $starting,
                $starting . ':listener',
                EventRecordingMigration::UP_MARKER,
                $prefix . Migrator::HOOK_MIGRATION_FINISHED,
            ],
            WpStub::$fired,
            'The starting hook must run before the migration body, not after it.'
        );
    }

    /**
     * The finished hook must fire only after a successful `up()`.
     *
     * Paired with the ordering test by writing into the same list: "finished"
     * appearing before the body marker would mean the hook fires optimistically.
     */
    public function test_the_finished_hook_fires_after_a_successful_migration(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', EventRecordingMigration::class);

        $prefix = (string) config('app.prefix', 'wp_mini_laravel_');
        $finished = $prefix . Migrator::HOOK_MIGRATION_FINISHED;

        add_action($finished, static function () use ($finished): void {
            WpStub::$fired[] = $finished . ':listener';
        }, 10, 0);

        $this->migrator()->runPending();

        $this->assertSame(
            [
                $prefix . Migrator::HOOK_MIGRATION_STARTING,
                EventRecordingMigration::UP_MARKER,
                $finished,
                $finished . ':listener',
            ],
            WpStub::$fired
        );
    }

    /**
     * Hooks carry the identifier and the direction, in that order.
     *
     * The arguments are the whole reason a listener can do anything useful — an
     * unfiltered "a migration ran" tells it nothing it can act on — so the
     * *values* are asserted, not merely the arity.
     */
    public function test_the_starting_hook_carries_the_identifier_and_direction(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $prefix = (string) config('app.prefix', 'wp_mini_laravel_');

        /** @var list<array{0: mixed, 1: mixed}> $received */
        $received = [];

        add_action($prefix . Migrator::HOOK_MIGRATION_STARTING, static function () use (&$received): void {
            $received[] = func_get_args();
        }, 10, 2);

        $this->migrator()->runPending();

        $this->assertSame([['2026_11_05_100000_create_users_table', 'up']], $received);
    }

    /**
     * A listener declaring one parameter on a two-argument hook gets one.
     *
     * WordPress's default `acceptedArgs` of 1 is load-bearing, and this is the
     * assertion that proves the stub honours it: a listener that would receive
     * only the identifier in production must receive only the identifier here. If
     * the stub called every callback with every argument, a listener written
     * against the wrong signature would pass the suite and fatal on a real site.
     */
    public function test_a_listener_declared_with_one_parameter_receives_only_the_identifier(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $prefix = (string) config('app.prefix', 'wp_mini_laravel_');

        $seen = null;

        add_action($prefix . Migrator::HOOK_MIGRATION_STARTING, static function ($identifier) use (&$seen): void {
            $seen = $identifier;
        }, 10, 1);

        $this->migrator()->runPending();

        $this->assertSame('2026_11_05_100000_create_users_table', $seen);
    }

    /**
     * The finished hook must NOT fire when the migration threw.
     *
     * "Finished" that also means "failed" is a hook no listener can act on: a
     * listener that cleans up on success would clean up after a failure and mask
     * it. The failure is reported through its own hook, so this is the assertion
     * that keeps the two meanings from collapsing into one.
     */
    public function test_the_finished_hook_does_not_fire_when_the_migration_throws(): void
    {
        $this->workspace->write('2026_11_05_120000_create_leads_table', ThrowingMigration::class);

        $prefix = (string) config('app.prefix', 'wp_mini_laravel_');

        $this->expectException(\Framework\Database\Exceptions\MigrationException::class);

        try {
            $this->migrator()->runPending();
        } finally {
            $this->assertNotContains(
                $prefix . Migrator::HOOK_MIGRATION_FINISHED,
                WpStub::$fired,
                'A migration that threw must never be reported as finished.'
            );
        }
    }

    /**
     * The failed hook must fire, carrying the *original* Throwable.
     *
     * The exception is passed rather than a message because a listener's decision
     * is "do I know how to handle this", which needs the object. It must be the
     * migration's own exception, not the `MigrationException` the migrator wraps
     * it in — a listener matching on the wrapper's type would match *every*
     * failure and could not distinguish the one case it was written for.
     */
    public function test_the_failed_hook_carries_the_original_exception(): void
    {
        $this->workspace->write('2026_11_05_120000_create_leads_table', ThrowingMigration::class);

        $prefix = (string) config('app.prefix', 'wp_mini_laravel_');

        /** @var list<array{0: mixed, 1: mixed, 2: mixed}> $received */
        $received = [];

        add_action($prefix . Migrator::HOOK_MIGRATION_FAILED, static function () use (&$received): void {
            $received[] = func_get_args();
        }, 10, 3);

        try {
            $this->migrator()->runPending();
            $this->fail('The throwing migration should have propagated.');
        } catch (\Framework\Database\Exceptions\MigrationException) {
            // Expected: the failure propagates after the hook fires.
        }

        $this->assertCount(1, $received);
        $this->assertSame('2026_11_05_120000_create_leads_table', $received[0][0]);
        $this->assertSame('up', $received[0][1]);
        $this->assertInstanceOf(\RuntimeException::class, $received[0][2]);
        $this->assertSame(ThrowingMigration::MESSAGE, $received[0][2]->getMessage());
    }

    /**
     * The starting hook must fire *before* the failure, so a listener that
     * prepared something knows it has to undo it.
     */
    public function test_the_starting_hook_fires_before_a_failing_migration_too(): void
    {
        $this->workspace->write('2026_11_05_120000_create_leads_table', ThrowingMigration::class);

        $prefix = (string) config('app.prefix', 'wp_mini_laravel_');

        try {
            $this->migrator()->runPending();
        } catch (\Framework\Database\Exceptions\MigrationException) {
            // Expected.
        }

        $starting = $prefix . Migrator::HOOK_MIGRATION_STARTING;
        $failed = $prefix . Migrator::HOOK_MIGRATION_FAILED;

        $this->assertContains($starting, WpStub::$fired);
        $this->assertContains($failed, WpStub::$fired);
        $this->assertLessThan(
            array_search($failed, WpStub::$fired, true),
            array_search($starting, WpStub::$fired, true),
            'starting must precede failed, or a listener cannot know a prepare needs undoing.'
        );
    }

    /**
     * Rolling back fires the events with `'down'` as the direction.
     *
     * The direction is what lets one listener serve both paths — the alternative
     * is six hooks, three per direction — so it has to be correct on the reversal
     * side and not merely present.
     *
     * Written with {@see EventRecordingMigration} so the body marker proves the
     * `down()` method was the one bracketed, rather than the hook pair merely
     * firing in the right shape.
     */
    public function test_rollback_fires_the_events_with_the_down_direction(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', EventRecordingMigration::class);

        $this->migrator()->runPending();

        $prefix = (string) config('app.prefix', 'wp_mini_laravel_');

        /** @var list<array{0: mixed, 1: mixed}> $received */
        $received = [];

        add_action($prefix . Migrator::HOOK_MIGRATION_STARTING, static function () use (&$received): void {
            $received[] = func_get_args();
        }, 10, 2);

        // The run above fired 'up'; only what the rollback fires is asserted.
        WpStub::$fired = [];

        $this->migrator()->rollback();

        $this->assertSame([['2026_11_05_100000_create_users_table', 'down']], $received);
        $this->assertSame(
            [
                $prefix . Migrator::HOOK_MIGRATION_STARTING,
                EventRecordingMigration::DOWN_MARKER,
                $prefix . Migrator::HOOK_MIGRATION_FINISHED,
            ],
            WpStub::$fired,
            'A rollback must bracket down(), not up().'
        );
    }

    /**
     * Events fire once per migration, in migration order, interleaved with the
     * bodies.
     *
     * A `foreach` that fired the hooks outside the loop would fire them once for
     * the batch and pass every single-migration assertion above. Two migrations
     * and the full expected sequence is what rules that out: each migration's
     * `starting` and `finished` must bracket its own body, not the batch.
     */
    public function test_events_bracket_each_migration_in_execution_order(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', EventRecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', EventRecordingMigration::class);

        $prefix = (string) config('app.prefix', 'wp_mini_laravel_');

        $this->migrator()->runPending();

        $this->assertSame(
            [
                $prefix . Migrator::HOOK_MIGRATION_STARTING,
                EventRecordingMigration::UP_MARKER,
                $prefix . Migrator::HOOK_MIGRATION_FINISHED,
                $prefix . Migrator::HOOK_MIGRATION_STARTING,
                EventRecordingMigration::UP_MARKER,
                $prefix . Migrator::HOOK_MIGRATION_FINISHED,
            ],
            WpStub::$fired,
            'Each migration must be bracketed by its own start/finish pair, not by one pair for the batch.'
        );
    }

    /**
     * The bare constants are never what gets fired — only the prefixed names are.
     *
     * This is the assertion behind the `config('app.prefix')` read: a migrator
     * that fired `migration_starting` verbatim would work perfectly for this
     * plugin and collide with every other plugin on the site that names a hook
     * the same way. It is also the assertion that would fail if someone "fixed"
     * the prefix by hardcoding it back to the literal, since the expected prefix
     * here is read from the same config.
     */
    public function test_the_bare_hook_names_are_never_fired(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $this->migrator()->runPending();

        $this->assertNotContains(Migrator::HOOK_MIGRATION_STARTING, WpStub::$fired);
        $this->assertNotContains(Migrator::HOOK_MIGRATION_FINISHED, WpStub::$fired);
    }

    // ------------------------------------------------------------------ reset

    /**
     * `migrate:reset` reverses *every* batch, not just the last one.
     *
     * The difference from `rollback` is the whole reason it exists: a developer
     * who has run `migrate` four times has four batches, and `rollback` undoes one.
     * Asserted on the tracking table being empty rather than on the count alone,
     * because "reverted 4" with rows still present is exactly the half-done state
     * this command is supposed to avoid.
     */
    public function test_reset_reverses_every_batch(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $this->workspace->write('2026_11_05_140000_create_notes_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $this->assertCount(3, $this->db->rows('wp_plugin_migrations'));

        $command = $this->resetCommand();
        $command->handle([], []);

        $this->assertStringContainsString('Rolled back 3 migration(s)', implode("\n", $command->messages));
        $this->assertSame([], $this->db->rows('wp_plugin_migrations'));
    }

    /**
     * Newest-first, across batches.
     *
     * A later batch may depend on a structure an earlier one created, so the
     * reverse order is not cosmetic — reversing oldest-first would drop a table
     * another migration's `down()` is about to touch.
     */
    public function test_reset_reverses_newest_first(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $command = $this->resetCommand();
        $command->handle([], []);

        $output = implode("\n", $command->messages);

        $this->assertLessThan(
            strpos($output, '2026_11_05_100000_create_users_table'),
            strpos($output, '2026_11_05_120000_create_leads_table'),
            'The newest migration must be reported as reversed before the oldest.'
        );
    }

    public function test_reset_with_nothing_applied_reports_so(): void
    {
        $command = $this->resetCommand();
        $command->handle([], []);

        $this->assertStringContainsString('Nothing to roll back', implode("\n", $command->messages));
    }

    /**
     * A failed `down()` stops the sweep and reports how far it got.
     *
     * The migrations already reversed stay reversed and their rows are gone, so
     * re-running resumes rather than repeating. Reporting the count is what makes
     * that recoverable — "reset failed" alone would leave an operator unsure
     * whether the database is empty, full, or somewhere between.
     *
     * **Both migrations are written before the single `runPending()`,** so they
     * share a batch, and the failing one is the *older*. That is what makes the
     * count non-zero: reversal is newest-first, so the newer migration reverses
     * successfully and the older one then fails. Applied in two separate runs
     * they would be two batches and the failure would come first, reporting 0 —
     * a correct answer that would let a broken "always reports zero" pass.
     */
    public function test_reset_reports_how_far_it_got_when_a_down_fails(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', DownThrowingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $this->migrator()->runPending();

        $command = $this->resetCommand();
        $command->handle([], []);

        $output = implode("\n", $command->messages);

        $this->assertStringContainsString(DownThrowingMigration::MESSAGE, $output);
        $this->assertStringContainsString('Stopped after 1 migration(s)', $output);
        $this->assertStringContainsString(
            '2026_11_05_100000_create_users_table',
            $output,
            'The failure has to name the migration that could not be reversed.'
        );

        // The one that did reverse is genuinely gone, which is what "stays
        // reversed" means and what makes a re-run a resume rather than a repeat.
        $this->assertSame(
            ['2026_11_05_100000_create_users_table'],
            array_column($this->db->rows('wp_plugin_migrations'), 'migration')
        );
    }

    // ------------------------------------------------------------------ wiring

    /**
     * The commands must be resolvable from the container with the migrator
     * injected — that is how the console kernel builds them.
     */
    public function test_the_commands_resolve_through_the_container(): void
    {
        $container = $this->container();

        $this->assertInstanceOf(MigrateCommand::class, $container->resolve(MigrateCommand::class));
        $this->assertInstanceOf(MigrateRollbackCommand::class, $container->resolve(MigrateRollbackCommand::class));
        $this->assertInstanceOf(MigrateStatusCommand::class, $container->resolve(MigrateStatusCommand::class));
        $this->assertInstanceOf(MigrateResetCommand::class, $container->resolve(MigrateResetCommand::class));
        $this->assertInstanceOf(MigrateDumpCommand::class, $container->resolve(MigrateDumpCommand::class));
    }

    public function test_the_project_registers_the_migration_commands(): void
    {
        $commands = (new \App\Console\Kernel(new \DevLinkStudios\WPServiceContainer\Container()))->getCommands();

        $this->assertContains(MigrateCommand::class, $commands);
        $this->assertContains(MigrateRollbackCommand::class, $commands);
        $this->assertContains(MigrateStatusCommand::class, $commands);
        $this->assertContains(MakeMigrationCommand::class, $commands);
        $this->assertContains(MigrateResetCommand::class, $commands);
        $this->assertContains(MigrateDumpCommand::class, $commands);
    }

    public function test_command_names_are_prefixed(): void
    {
        $prefix = config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX);

        $this->assertSame("{$prefix} migrate", $this->migrateCommand()->getName());
        $this->assertSame("{$prefix} migrate:rollback", $this->rollbackCommand()->getName());
        $this->assertSame("{$prefix} migrate:status", $this->statusCommand()->getName());
        $this->assertSame("{$prefix} migrate:reset", $this->resetCommand()->getName());
        $this->assertSame("{$prefix} make:migration", (new MakeMigrationCommand())->getName());
        $this->assertSame("{$prefix} migrate:dump", $this->dumpCommand()->getName());
    }

    public function test_every_migration_command_declares_a_description(): void
    {
        foreach ([
            new MakeMigrationCommand(),
            $this->migrateCommand(),
            $this->rollbackCommand(),
            $this->statusCommand(),
            $this->resetCommand(),
            $this->dumpCommand(),
        ] as $command) {
            $this->assertNotSame('', $command->getDescription());
        }
    }

    // ----------------------------------------------------------------- helpers

    /**
     * A container with the same self-bindings the real one has, plus the
     * migration provider registered.
     */
    private function container(): \DevLinkStudios\WPServiceContainer\Container
    {
        $container = new \DevLinkStudios\WPServiceContainer\Container();
        $container->instance(
            \DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface::class,
            $container
        );
        $container->instance(\DevLinkStudios\WPServiceContainer\Container::class, $container);

        // The provider binds a default Migrator (real base_path, real $wpdb);
        // rebinding after it points the commands at this test's fixtures, which
        // is also how a project with a different layout would override it.
        (new \Framework\Database\MigrationServiceProvider($container))->register();
        $container->instance(Migrator::class, $this->migrator());

        return $container;
    }

    private function migrator(): Migrator
    {
        return new Migrator(
            $this->workspace->path(),
            new WpDatabaseAdapter($this->db),
            new WpMigrationStorage($this->db),
        );
    }

    /**
     * The dump command, over the same fake database as the migrator.
     *
     * Built directly rather than resolved from {@see container()} so it reads the
     * adapter this test's `Schema::useAdapter()` installed — the container's
     * default is a real `$wpdb`, which would make this command describe the
     * developer's own database while every other assertion here describes the
     * fixture.
     */
    private function dumpCommand(): MigrateDumpCommand
    {
        return new MigrateDumpCommand(new SchemaDumper(new WpDatabaseAdapter($this->db)));
    }

    private function migrateCommand(): TestableMigrateCommand
    {
        return new TestableMigrateCommand($this->migrator());
    }

    private function rollbackCommand(): TestableRollbackCommand
    {
        return new TestableRollbackCommand($this->migrator());
    }

    private function statusCommand(): TestableStatusCommand
    {
        return new TestableStatusCommand($this->migrator());
    }

    private function resetCommand(): TestableResetCommand
    {
        return new TestableResetCommand($this->migrator());
    }

    private function refreshCommand(): TestableRefreshCommand
    {
        return new TestableRefreshCommand($this->migrator());
    }

    /**
     * `--pretend` must be honoured by the commands that destroy things.
     *
     * `migrate:rollback`, `migrate:reset` and `migrate:refresh` were the three
     * commands with no dry run at all. That is the worst place to lack one: they
     * are exactly the commands an operator most wants to rehearse, and the
     * framework's own recovery path is `rollback --batch=<n>` after a failed
     * batch — a step you would like to preview before taking against a database
     * you are already worried about.
     *
     * Asserted against real state rather than `$db->queries`, because `FakeWpdb`
     * records a statement *before* parsing it, so a recorded DROP proves nothing
     * about whether the table survived.
     */
    public function test_rollback_pretends_without_touching_the_database(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);
        $this->migrateCommand()->handle([], []);

        $this->assertTrue($this->db->tableExists('wp_users'), 'Precondition: the table exists.');

        $command = $this->rollbackCommand();
        $command->handle([], ['pretend' => true]);

        $this->assertTrue($this->db->tableExists('wp_users'), 'A pretend rollback dropped the table.');
        $this->assertCount(1, $this->db->rows('wp_plugin_migrations'), 'A pretend rollback unrecorded the migration.');
        $this->assertStringContainsString('Pretending', implode("\n", $command->messages));
    }

    /**
     * `reset` reverses every batch, so a pretend run must leave every one of them
     * standing.
     */
    public function test_reset_pretends_without_touching_the_database(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);
        $this->migrateCommand()->handle([], []);

        $this->assertTrue($this->db->tableExists('wp_users'), 'Precondition: the table exists.');

        $command = $this->resetCommand();
        $command->handle([], ['pretend' => true]);

        $this->assertTrue($this->db->tableExists('wp_users'), 'A pretend reset dropped the table.');
        $this->assertCount(1, $this->db->rows('wp_plugin_migrations'), 'A pretend reset unrecorded the migration.');
        $this->assertStringContainsString('Pretending', implode("\n", $command->messages));
    }

    /**
     * `refresh` is the dangerous one: it reverses and then *re-runs*, so a pretend
     * run that leaked on either half would leave the schema either empty or
     * applied twice.
     */
    public function test_refresh_pretends_without_touching_the_database(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);
        $this->migrateCommand()->handle([], []);

        $before = $this->db->rows('wp_plugin_migrations');

        $command = $this->refreshCommand();
        $command->handle([], ['pretend' => true]);

        $this->assertTrue($this->db->tableExists('wp_users'), 'A pretend refresh dropped the table.');
        $this->assertSame($before, $this->db->rows('wp_plugin_migrations'), 'A pretend refresh rewrote the tracking rows.');
        $this->assertStringContainsString('Pretending', implode("\n", $command->messages));
    }

    /**
     * A pretend run leaves the process able to run for real afterwards.
     *
     * `Schema::pretend()` pins the adapter into a static slot. If a destructive
     * command forgot to hand it back, every later command in the same process
     * would silently become a dry run — and the operator would be told the
     * rollback succeeded while the tables were still there.
     */
    public function test_a_pretend_rollback_does_not_leak_into_the_next_real_run(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);
        $this->migrateCommand()->handle([], []);

        $this->rollbackCommand()->handle([], ['pretend' => true]);
        $this->assertFalse(Schema::isPretending(), 'The pretend adapter leaked.');

        $this->rollbackCommand()->handle([], []);
        $this->assertFalse($this->db->tableExists('wp_users'), 'The real rollback did not run.');
    }

    /**
     * `--pretend=off` on a destructive command means "do it", not "dry run".
     *
     * The same falsy list the plain `migrate` command uses, for the same reason —
     * but here the consequence of the old reading is worse, because the operator
     * would be told a destructive command succeeded when it had done nothing, and
     * then run the *next* step of a recovery against a database that was still in
     * the state they thought they had left.
     */
    public function test_a_destructive_command_reads_pretend_off_as_a_real_run(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);
        $this->migrateCommand()->handle([], []);

        $this->rollbackCommand()->handle([], ['pretend' => 'off']);

        $this->assertFalse($this->db->tableExists('wp_users'), '--pretend=off was read as a dry run.');
    }
}

/**
 * The real commands with their output captured.
 *
 * Only the reporting helpers are overridden — the behaviour under test is the
 * command's own `handle()`, which is inherited unchanged.
 */
final class TestableMigrateCommand extends MigrateCommand
{
    /** @var list<string> */
    public array $messages = [];

    protected function success(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function error(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function info(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function warning(string $message): void
    {
        $this->messages[] = $message;
    }
}

final class TestableRollbackCommand extends MigrateRollbackCommand
{
    /** @var list<string> */
    public array $messages = [];

    protected function success(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function error(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function info(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function warning(string $message): void
    {
        $this->messages[] = $message;
    }
}

final class TestableStatusCommand extends MigrateStatusCommand
{
    /** @var list<string> */
    public array $messages = [];

    protected function success(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function error(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function info(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function warning(string $message): void
    {
        $this->messages[] = $message;
    }
}

final class TestableResetCommand extends MigrateResetCommand
{
    /** @var list<string> */
    public array $messages = [];

    protected function success(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function error(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function info(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function warning(string $message): void
    {
        $this->messages[] = $message;
    }
}

final class TestableRefreshCommand extends MigrateRefreshCommand
{
    /** @var list<string> */
    public array $messages = [];

    protected function success(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function error(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function info(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function warning(string $message): void
    {
        $this->messages[] = $message;
    }
}
