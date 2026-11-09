<?php
declare(strict_types=1);

namespace Framework\Database;

use Framework\Database\Adapters\DatabaseAdapterInterface;
use Framework\Database\Exceptions\MigrationException;
use Framework\Database\Schema\Schema;
use Framework\Database\Storage\MigrationStorageInterface;
use Throwable;

/**
 * Runs and reverses the plugin's database migrations.
 *
 * Owns discovery of migration files, the ordering rules, and the two execution
 * directions. Both seams are injected: {@see DatabaseAdapterInterface} for the
 * schema the migrations build, and {@see MigrationStorageInterface} for the
 * record of which ones have run, the batch numbering and the lock that
 * serialises runs. Nothing here names `$wpdb` or spells a statement by hand.
 * The migration path is injected too, so the class runs against a temporary
 * directory in a test.
 *
 * The two interfaces stay separate because they are different jobs: one
 * compiles and runs DDL, the other reads and writes rows and takes a lock.
 * Folding the second into the first would put `insert()` and `get_var()` on
 * every adapter in the project to serve one caller.
 *
 * Applied migrations are recorded as `(id, migration, batch)`. `migration` is
 * the filename identifier rather than the class name (see {@see MigrationFile})
 * and carries a UNIQUE index, so a duplicate insert is rejected by the database
 * rather than by a read-then-write check that could race. `batch` is what makes
 * rollback coherent: each `runPending()` call stamps every migration it applies
 * with one new number, so `rollback()` can undo exactly one invocation instead
 * of guessing from timestamps.
 *
 * MySQL DDL (`CREATE TABLE`, `ALTER TABLE`) triggers an implicit commit, so a
 * transaction wrapped around a migration gives no atomicity, and so do
 * WordPress's `dbDelta()` and `$wpdb->query('DROP TABLE ...')`. This class
 * therefore does not pretend to be transactional: a failure stops the batch and
 * propagates. The tracking table keeps only the migrations that completed,
 * every later one in the batch stays pending, and re-running resumes from where
 * it stopped.
 *
 * The one real atomicity WordPress offers is its per-request rollback flag.
 * `set_transaction_rollback(true)` (WordPress 6.7+) makes the next
 * `$wpdb->query()` roll back if it errors, cleaning up a half-written DDL
 * statement. It is requested through
 * {@see MigrationStorageInterface::guardAgainstPartialWrite()}, a no-op where
 * the platform has no such concept.
 */
class Migrator
{
    /**
     * The hook fired immediately before a migration's `up()` runs.
     *
     * `{$prefix}…` is the action name with `MIGRATOR_HOOK_PREFIX` prepended, so
     * the plugin's own prefix namespaces its hooks. Two arguments: the migration
     * identifier (the filename, as `migrate:status` shows) and the direction
     * (`'up'` or `'down'`).
     */
    public const HOOK_MIGRATION_STARTING = 'migration_starting';

    /**
     * The hook fired after a migration's `up()` returned without throwing.
     *
     * Not fired when the migration threw: "finished" that also means "failed"
     * is a hook no listener can act on. The failure is reported through
     * {@see HOOK_MIGRATION_FAILED} and the propagating exception, and a listener
     * that wants both subscribes to both.
     */
    public const HOOK_MIGRATION_FINISHED = 'migration_finished';

    /**
     * The hook fired when a migration threw, before the exception propagates.
     *
     * Three arguments: the identifier, the direction, and the `Throwable`. The
     * exception is passed rather than the message so a listener can decide
     * whether it cares.
     */
    public const HOOK_MIGRATION_FAILED = 'migration_failed';

    /**
     * How long to wait for the migration lock before giving up, in seconds.
     *
     * Long, because the competing holder is another migration run rather than a
     * stuck request: a short timeout would turn "the deploy is still running"
     * into "the deploy failed" for the second of two nearly simultaneous ones.
     */
    private const LOCK_TIMEOUT_SECONDS = 600;

    /**
     * Prefixed to each hook constant to form the action name.
     *
     * Read from `app.prefix`, the same value the rebrander rewrites, so a
     * rebranded plugin's hooks are namespaced to it without a listener knowing
     * the new name.
     *
     * Read through the global `config()` helper rather than constructor-injected
     * because this class is constructed by the container before WordPress is
     * necessarily up.
     */
    private function hookPrefix(): string
    {
        return (string) config('app.prefix', 'wp_mini_laravel_');
    }

    /**
     * Fire a migration event, when WordPress's hook system is loaded.
     *
     * Guarded because this class also runs without WordPress, in the test suite
     * or a script driving the migrator directly, where an unguarded
     * `do_action()` would be fatal. Nothing is lost when it is skipped: a
     * listener is a WordPress callback and cannot exist without WordPress.
     */
    private function fire(string $hook, mixed ...$args): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action($this->hookPrefix() . $hook, ...$args);
    }

    /**
     * Directory holding the migration files.
     */
    private string $migrationPath;

    /**
     * What the most recent {@see runPending()} recorded, for a caller whose
     * batch threw partway and so never received a return value.
     *
     * @var list<string>
     */
    private array $lastRunApplied = [];

    /**
     * What the most recent reversal actually reversed, for the same reason as
     * {@see $lastRunApplied}.
     *
     * A `down()` that throws propagates out of `rollbackBatch()` and takes a
     * local list with the frame, so a command reporting "failed" could not say
     * how much of the rollback landed. That number decides whether to re-run, so
     * it is recorded as it accumulates.
     *
     * @var list<string>
     */
    private array $lastRunReverted = [];

    /**
     * @param string|null                $migrationPath Directory holding the migration files.
     *                                                  Defaults to `base_path('database/migrations')`.
     * @param DatabaseAdapterInterface   $adapter       The schema this runs migrations against. Also
     *                                                  the source of the table prefix, which is what
     *                                                  makes a migration's `Schema::` calls and the
     *                                                  tracking table agree on a database.
     * @param MigrationStorageInterface  $storage       Where "which migrations have run" is kept, and
     *                                                  where the lock is taken. Injected rather than
     *                                                  derived from the adapter, because reading rows
     *                                                  is not what the schema seam is for.
     */
    public function __construct(
        ?string $migrationPath,
        private readonly DatabaseAdapterInterface $adapter,
        private readonly MigrationStorageInterface $storage,
    ) {
        $this->migrationPath = $migrationPath ?? $this->defaultMigrationPath();
    }

    /**
     * The path migrations are read from.
     */
    public function getMigrationPath(): string
    {
        return $this->migrationPath;
    }

    /**
     * The fully-qualified tracking table name.
     */
    public function getTableName(): string
    {
        return $this->storage->tableName();
    }

    // ------------------------------------------------------------------ running

    /**
     * Apply every pending migration as a single new batch.
     *
     * Migrations run oldest-first, ordered by the timestamp in their filename
     * rather than by the directory listing, since `scandir()` order is whatever
     * the filesystem returns and a migration that assumes an earlier one has run
     * must actually have run.
     *
     * @param callable(string): void|null $onProgress Called with each identifier
     *                                               immediately before it runs, so
     *                                               a console command can stream.
     * @return list<string> Identifiers that were applied, in execution order.
     *
     * @throws MigrationException When a migration cannot be loaded or fails.
     */
    public function runPending(?callable $onProgress = null): array
    {
        // The lock is taken before the tracking table is ensured: two processes
        // that both reached `ensureTrackingTable()` would both read "the table is
        // absent" and both issue the CREATE.
        return $this->withLock(fn (): array => $this->runPendingLocked($onProgress));
    }

    /**
     * {@see runPending()} with the lock already held.
     *
     * Split out rather than inlined into a closure so the whole body keeps its
     * docblock and its local variable names.
     *
     * @param callable(string): void|null $onProgress
     * @return list<string>
     */
    private function runPendingLocked(?callable $onProgress = null): array
    {
        $this->ensureTrackingTable();

        // Cleared before the empty check: a caller inspecting this after a no-op
        // run would otherwise read the previous run's list.
        $this->lastRunApplied = [];

        $pending = $this->pendingMigrations();

        if ($pending === []) {
            return [];
        }

        // Allocated once for the whole call, which is what makes this a batch.
        $batch = $this->nextBatchNumber();

        // Recorded before the first migration runs, so a batch that fails partway
        // still burns its number. Reusing it on the retry would make an old
        // transcript's `--batch=<n>` point at the wrong set.
        //
        // Guarded against pretending because this option only ever moves up, so a
        // dry run that wrote it would advance the numbering permanently and the
        // gap could never be explained.
        if (!Schema::isPretending()) {
            $this->storage->rememberBatch($batch);
        }

        $applied = [];

        try {
            foreach ($pending as $migration) {
                $this->runMigration($migration, 'up', $onProgress);

                // Recorded only after up() returned without throwing, so the table
                // never claims a migration that did not finish.
                //
                // Under `migrate --pretend` the `up()` above ran through the
                // recording adapter, so nothing was created and writing the row
                // would leave the table claiming work that does not exist. The
                // identifier is still listed as applied, because from the
                // operator's point of view it would have been.
                if (!Schema::isPretending()) {
                    $this->storage->record($migration->identifier, $batch);
                }

                $applied[] = $migration->identifier;
                $this->lastRunApplied = $applied;
            }
        } finally {
            $this->lastRunApplied = $applied;
        }

        return $applied;
    }

    /**
     * Apply every pending migration, one per batch.
     *
     * The `--step` form of {@see runPending()}. Each migration gets its own batch
     * number, so `migrate:rollback` undoes exactly one of them and a developer
     * can step through and reverse as they go.
     *
     * The pending list is read once here rather than looping `runPending()`,
     * which would re-query it per migration. A failure stops the loop at the same
     * point, but the exception names the migration that failed and every earlier
     * one is already recorded under its own batch.
     *
     * @param callable(string): void|null $onProgress
     * @return list<string> Identifiers applied, in execution order.
     *
     * @throws MigrationException
     */
    public function runPendingStepwise(?callable $onProgress = null): array
    {
        return $this->withLock(fn (): array => $this->runPendingStepwiseLocked($onProgress));
    }

    /**
     * {@see runPendingStepwise()} with the lock already held.
     *
     * @param callable(string): void|null $onProgress
     * @return list<string>
     */
    private function runPendingStepwiseLocked(?callable $onProgress = null): array
    {
        $this->ensureTrackingTable();

        $this->lastRunApplied = [];

        $pending = $this->pendingMigrations();

        if ($pending === []) {
            return [];
        }

        $applied = [];

        try {
            foreach ($pending as $migration) {
                $batch = $this->nextBatchNumber();

                // Per-migration here rather than once per run, which is what
                // "stepwise" means. It makes the pretend guard matter more: a dry
                // run over N migrations would advance the high-water mark by N.
                if (!Schema::isPretending()) {
                    $this->storage->rememberBatch($batch);
                }

                $this->runMigration($migration, 'up', $onProgress);

                if (!Schema::isPretending()) {
                    $this->storage->record($migration->identifier, $batch);
                }

                $applied[] = $migration->identifier;
                $this->lastRunApplied = $applied;
            }
        } finally {
            $this->lastRunApplied = $applied;
        }

        return $applied;
    }

    /**
     * The migrations the most recent {@see runPending()} actually recorded.
     *
     * Describes that call and no earlier one: it is cleared before `runPending()`
     * reads the pending list, so a run with nothing pending leaves it empty.
     *
     * It exists because a batch that fails partway throws rather than returning,
     * so the caller has no other way to learn how much landed.
     *
     * @return list<string>
     */
    public function lastRunApplied(): array
    {
        return $this->lastRunApplied;
    }

    /**
     * The migrations the most recent reversal actually reversed.
     *
     * The counterpart to {@see lastRunApplied()} for the other direction, and it
     * exists for the identical reason: a rollback that fails partway throws
     * rather than returning. Describes that call and no earlier one.
     *
     * @return list<string>
     */
    public function lastRunReverted(): array
    {
        return $this->lastRunReverted;
    }

    /**
     * Alias for {@see runPending()}, matching the artisan verb.
     *
     * @param callable(string): void|null $onProgress
     * @return list<string>
     */
    public function up(?callable $onProgress = null): array
    {
        return $this->runPending($onProgress);
    }

    /**
     * Reverse the most recent batch.
     *
     * Migrations run newest-first within the batch: two in one batch may touch
     * the same table, and undoing them in forward order would apply a change on
     * top of a structure that is about to be removed.
     *
     * @param callable(string): void|null $onProgress
     * @return list<string> Identifiers that were reversed, in execution order.
     *
     * @throws MigrationException When a migration cannot be loaded or fails.
     */
    public function rollback(?callable $onProgress = null): array
    {
        $this->ensureTrackingTable();

        $batch = $this->lastBatch();

        if ($batch === null) {
            return [];
        }

        return $this->rollbackBatch($batch, $onProgress);
    }

    /**
     * Reverse a specific batch. The escape hatch for recovering from a batch
     * that failed partway: its number is visible in `getStatus()`.
     *
     * @param callable(string): void|null $onProgress
     * @return list<string>
     *
     * @throws MigrationException
     */
    public function rollbackBatch(int $batch, ?callable $onProgress = null): array
    {
        return $this->withLock(fn (): array => $this->rollbackBatchLocked($batch, $onProgress));
    }

    /**
     * {@see rollbackBatch()} with the lock already held.
     *
     * @param callable(string): void|null $onProgress
     * @return list<string>
     */
    private function rollbackBatchLocked(int $batch, ?callable $onProgress = null): array
    {
        $this->ensureTrackingTable();

        $ran = $this->migrationsInBatch($batch);

        if ($ran === []) {
            return [];
        }
        // Newest first, taken from `discover()` rather than from a second sort of
        // the identifiers.
        //
        // Re-sorting the raw identifiers `desc` looked equivalent and is not: the
        // identifier carries the timestamp in whichever spelling the filename
        // used, and the two do not compare as strings (`_` is 0x5F, digits are
        // 0x30–0x39). Reversing `discover()`'s order inherits its fix for that,
        // and keeps the ordering rule in one place.
        $known = [];

        foreach ($this->discover() as $migration) {
            $known[$migration->identifier] = $migration;
        }

        // Oldest-first from `discover()`, reversed, with anything recorded but no
        // longer on disk appended after it, so a missing file is still reached
        // and reported by the loop below. Taking only the intersection here would
        // drop it silently, and a deleted migration would stop erroring.
        $onDisk = array_values(array_intersect(array_keys($known), $ran));
        $missing = array_values(array_diff($ran, $onDisk));

        $ran = array_merge(array_reverse($onDisk), $missing);

        $reverted = [];
        $this->lastRunReverted = [];

        foreach ($ran as $identifier) {
            $migration = $known[$identifier] ?? null;

            // A row whose file has been deleted from disk is reported rather than
            // silently dropped: deleting the row would make the migration
            // permanently unrunnable and unrollbackable, and the usual cause is a
            // file removed by mistake.
            if ($migration === null) {
                throw new MigrationException(sprintf(
                    'Migration [%s] is recorded as ran but its file is missing from [%s]. '
                    . 'Restore the file, or delete its row from the tracking table to forget it.',
                    $identifier,
                    $this->migrationPath
                ));
            }

            $this->runMigration($migration, 'down', $onProgress);

            // Skipped while pretending, for the mirror of the reason
            // {@see runPending()} skips `record()`: the statements `down()`
            // issued were recorded rather than run, so the schema is unchanged
            // and the row still describes it truthfully.
            if (!Schema::isPretending()) {
                $this->storage->forget($identifier);
            }

            $reverted[] = $identifier;

            // Recorded as it happens, not on return: a later migration throwing
            // unwinds this frame and takes `$reverted` with it, and the caller
            // needs the partial list to report how far the sweep got.
            $this->lastRunReverted = $reverted;
        }

        return $reverted;
    }

    /**
     * Alias for {@see rollback()}, matching the artisan verb.
     *
     * @param callable(string): void|null $onProgress
     * @return list<string>
     */
    public function down(?callable $onProgress = null): array
    {
        return $this->rollback($onProgress);
    }

    // ------------------------------------------------------------------ reading

    /**
     * Every migration file on disk with its current state.
     *
     * Returned oldest-first and keyed by identifier. A migration on disk but not
     * in the tracking table is pending; one in the table carries the batch it ran
     * in.
     *
     * Migrations recorded as ran whose file has since been deleted are not
     * included, since they are not status of anything on disk. {@see getStatus()}
     * reports them separately as missing.
     *
     * @return array<string, array{identifier: string, class: string, path: string, batch: int|null, ran: bool}>
     */
    public function status(): array
    {
        $this->ensureTrackingTable();

        $tracked = $this->appliedMigrations();
        $status = [];

        foreach ($this->discover() as $migration) {
            $batch = $tracked[$migration->identifier] ?? null;

            $status[$migration->identifier] = [
                'identifier' => $migration->identifier,
                'class' => $migration->class,
                'path' => $migration->path,
                'batch' => $batch,
                'ran' => $batch !== null,
            ];
        }

        return $status;
    }

    /**
     * {@see status()} under the name in the brief, for terminal reporting.
     *
     * @return array<string, array{identifier: string, class: string, path: string, batch: int|null, ran: bool}>
     */
    public function getStatus(): array
    {
        return $this->status();
    }

    /**
     * Every batch number that has at least one recorded migration, oldest first.
     *
     * The batch high-water mark is deliberately not used: it records the newest
     * number ever handed out, including a batch that failed before recording
     * anything, and rolling that back is a no-op. This answers the different
     * question "what is there to reverse", which
     * {@see \Framework\Database\Commands\MigrateRefreshCommand} and any future
     * `rollback --all` both need.
     *
     * Sorted numerically, since batches are integers and `10` must not sort
     * before `9`.
     *
     * @return list<int>
     */
    public function batches(): array
    {
        $this->ensureTrackingTable();

        // `SELECT DISTINCT` would be the obvious query and is deliberately not
        // used: the tracking table holds at most a few hundred rows, so deduping
        // in PHP costs nothing, while `DISTINCT` would be a fourth shape of
        // SELECT this class issues. The test double that stands in for `$wpdb` is
        // a loud parser that rejects anything it does not recognise, so the
        // simplest statement that answers the question is the only one that stays
        // verifiable.
        $rows = $this->appliedMigrations();

        $batches = array_values(array_unique(array_map(
            static fn (int $batch): int => $batch,
            array_values($rows)
        )));

        sort($batches);

        return $batches;
    }

    /**
     * Reverse every applied migration, newest batch first.
     *
     * The batch list is read once up front rather than looping `rollback()`,
     * which would issue a fresh `SELECT MAX(\`batch\`)` per pass and could not
     * report the full set it intends to touch before it starts.
     *
     * The list is walked newest-first, reversed from `batches()` order for the
     * same reason `rollbackBatch()` sorts its rows newest-first: a later batch
     * may depend on a structure an earlier one created.
     *
     * A failure propagates and stops the sweep. The batches already reversed are
     * genuinely reversed and their rows are gone, so re-running resumes rather
     * than repeating, the same contract `rollback()` has.
     *
     * @param callable(string): void|null $onProgress
     * @return list<string> Identifiers reversed, in execution order.
     *
     * @throws MigrationException
     */
    public function rollbackAll(?callable $onProgress = null): array
    {
        // One lock for the whole sweep, not one per batch. MySQL's locks are not
        // reentrant in the way calling `rollbackBatch()` in a loop would assume:
        // a second `GET_LOCK()` on a name the same connection already holds
        // succeeds, but each `RELEASE_LOCK()` frees it outright, so the sweep
        // would be unprotected after the first release.
        return $this->withLock(fn (): array => $this->rollbackAllLocked($onProgress));
    }

    /**
     * {@see rollbackAll()} with the lock already held.
     *
     * @param callable(string): void|null $onProgress
     * @return list<string>
     */
    private function rollbackAllLocked(?callable $onProgress = null): array
    {
        $reverted = [];

        // Reset once for the whole sweep, then read back after each batch. Each
        // `rollbackBatch()` call resets the recorded list to its own batch, since
        // it is also called directly, so the accumulation across batches happens
        // here and a failure in a later batch still leaves the earlier batches'
        // reversals visible to the caller.
        $this->lastRunReverted = [];

        foreach (array_reverse($this->batches()) as $batch) {
            foreach ($this->rollbackBatchLocked($batch, $onProgress) as $identifier) {
                $reverted[] = $identifier;
            }

            $this->lastRunReverted = $reverted;
        }

        return $reverted;
    }

    /**
     * Drop the migration-created tables and forget that anything ran.
     *
     * Only tables migrations were recorded as creating: each applied migration's
     * `down()` removes them, rather than a `DROP TABLE` invented here. That
     * distinction is the safety story, since a `migrate:fresh` that swept the
     * database would delete a WordPress install's posts when a developer typed
     * it against the wrong `wp-config.php`.
     *
     * `down()` is called with no compensating intent behind it. Reversing runs
     * each migration's own idea of undo, which for a migration that renamed a
     * column means renaming it back: real data loss performed for no reason,
     * since the next thing `fresh` does is drop the table anyway.
     *
     * The tracking table is dropped rather than emptied. `DELETE FROM` would
     * leave the table, its AUTO_INCREMENT and the high-water mark option in
     * place; dropping makes "fresh" true of the whole migration state. The batch
     * counter is reset in the same breath, since a fresh database reporting
     * batch 47 is a state no one can reason about.
     *
     * @return list<string> Table names dropped, in the order dropped.
     *
     * @throws MigrationException When a drop fails.
     */
    public function dropAllTables(): array
    {
        // `migrate:fresh` is the most destructive statement this framework issues
        // and the one most likely to be typed while something else is running, so
        // it takes the lock on the same terms as everything else.
        return $this->withLock(fn (): array => $this->dropAllTablesLocked());
    }

    /**
     * {@see dropAllTables()} with the lock already held.
     *
     * @return list<string>
     */
    private function dropAllTablesLocked(): array
    {
        // Read before anything is dropped: the tracked names are the input to
        // this operation, and once the tracking table is gone there is no way to
        // learn what should have been dropped.
        $this->ensureTrackingTable();
        $tracked = array_keys($this->appliedMigrations());

        // Only identifiers whose file still exists can be resolved to a table. A
        // file deleted from disk is reported by `migrate:status` and is not this
        // command's to guess about, since dropping an unknown table name is the
        // wild sweep the docblock above rules out.
        $known = [];

        foreach ($this->discover() as $migration) {
            $known[$migration->identifier] = $migration;
        }

        $dropped = [];

        // Newest first for the same reason rollback is: a dependent table is
        // created after the table it references.
        usort($tracked, static fn (string $a, string $b): int => strcmp($b, $a));

        foreach ($tracked as $identifier) {
            $migration = $known[$identifier] ?? null;

            if ($migration === null) {
                continue;
            }

            $instance = $this->resolve($migration);

            $this->storage->guardAgainstPartialWrite();

            try {
                $instance->down();
            } catch (Throwable $e) {
                throw MigrationException::stepFailed('down', $identifier, $e);
            }

            $dropped[] = $identifier;
        }

        // Last, and unconditionally: even with nothing tracked, a fresh database
        // must not be left holding a tracking table that describes a schema it no
        // longer has. `DROP TABLE IF EXISTS` because on a truly fresh install the
        // `ensureTrackingTable()` above is the only thing that created it.
        $this->storage->dropTrackingTable();

        // The high-water mark goes with the tracking table, for the same reason: a
        // fresh database that reports batch 47 is a state no one can reason about.
        $this->storage->forgetBatchHighWaterMark();

        // The high-water mark is gone, so the next batch is 1. The cached read is
        // reset too, or a long-lived process would keep serving the old number.
        $this->lastRunApplied = [];

        return $dropped;
    }

    /**
     * Identifiers recorded as ran whose file is no longer on disk.
     *
     * Surfaced rather than hidden because it is the one state the migrator cannot
     * repair on its own: `rollback()` refuses to proceed past one, having no
     * `down()` to run.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $this->ensureTrackingTable();

        $onDisk = [];

        foreach ($this->discover() as $migration) {
            $onDisk[$migration->identifier] = true;
        }

        return array_values(array_diff(array_keys($this->appliedMigrations()), array_keys($onDisk)));
    }

    /**
     * Migration files that have not been run, oldest-first.
     *
     * Ensures the tracking table first. This method is public and is called
     * before {@see runPending()} by `migrate`, so on a fresh database it is the
     * first thing to read `{prefix}plugin_migrations`; reading a table that has
     * never been created fails with "Table doesn't exist" before the migrator
     * gets the chance to create it.
     *
     * @return list<MigrationFile>
     *
     * @throws MigrationException When the directory is missing.
     */
    public function pendingMigrations(): array
    {
        $this->ensureTrackingTable();

        $tracked = $this->appliedMigrations();

        return array_values(array_filter(
            $this->discover(),
            static fn (MigrationFile $migration): bool => !array_key_exists($migration->identifier, $tracked)
        ));
    }

    /**
     * Every migration file in the directory, oldest-first.
     *
     * Files that do not follow the naming convention are skipped, not reported: a
     * directory holding a stray `helpers.php` should still migrate. A file that
     * follows the convention but declares the wrong class is reported at load
     * time, because that one was meant to be a migration.
     *
     * @return list<MigrationFile>
     *
     * @throws MigrationException When the directory does not exist.
     */
    public function discover(): array
    {
        if (!is_dir($this->migrationPath)) {
            throw MigrationException::directoryMissing($this->migrationPath);
        }

        $found = [];

        foreach (glob($this->migrationPath . '/*.php') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $migration = MigrationFile::fromPath($path);

            if ($migration !== null) {
                $found[] = $migration;
            }
        }

        // Sorted here rather than trusted from glob(), which documents no order.
        //
        // By `timestamp()`, not by the raw identifier. The identifier carries the
        // timestamp in whichever of the two accepted spellings the filename used,
        // and those two do not sort together: `_` is 0x5F and digits are
        // 0x30-0x39, so every `2026_11_05_...` identifier sorts after every
        // `20261105...` one regardless of the dates. Comparing identifiers runs
        // every compact-named migration first, including one that alters a table
        // a later-listed migration has not created yet.
        //
        // `timestamp()` normalises the two spellings to a comparable form. Ties
        // are impossible in practice, but the identifier is the tiebreak so the
        // order stays deterministic if two files ever share a timestamp.
        usort($found, static function (MigrationFile $a, MigrationFile $b): int {
            return strcmp($a->timestamp(), $b->timestamp())
                ?: strcmp($a->identifier, $b->identifier);
        });

        return $found;
    }

    // --------------------------------------------------------------- tracking

    /**
     * Create the tracking table when it is absent, or repair it when an older
     * version of it is missing a column.
     *
     * Idempotent, and not built on `dbDelta()`: `dbDelta` requires
     * `ABSPATH . 'wp-admin/includes/upgrade.php'` to have been loaded, which is
     * not true under WP-CLI's own bootstrap without an explicit `require`. A
     * direct `SHOW COLUMNS` probe is cheaper and this table is created rarely.
     *
     * The probes and the statements live behind
     * {@see MigrationStorageInterface}; this method is the decision (create,
     * repair, or leave alone) and the storage is what knows how to ask.
     *
     * @throws MigrationException When the table cannot be created.
     */
    public function ensureTrackingTable(): void
    {
        // A no-op while pretending, and the guard has to live here rather than at
        // the call sites. Three paths reach this method, and `pendingMigrations()`
        // is called by `status()`, `missing()` and every rollback, so guarding
        // only the two run paths would leave a dry run that merely asks what is
        // pending creating the table.
        //
        // Read-only while pretending is not "skip the work": the callers below
        // treat a missing table as "nothing recorded", which is the answer the
        // table would give.
        if (Schema::isPretending()) {
            return;
        }

        if (!$this->storage->trackingTableExists()) {
            $this->storage->createTrackingTable();

            return;
        }

        // The batch column was not in the first cut of this table. A plugin that
        // upgraded in place would fail every write with "Unknown column", so it is
        // added on demand rather than left to a migration of the migrations table.
        if (!$this->storage->trackingTableHasColumn('batch')) {
            $this->storage->addTrackingTableColumn('batch', 'bigint(20) unsigned');
        }
    }

    /**
     * Identifiers already recorded, mapped to their batch number.
     *
     * @return array<string, int>
     */
    private function appliedMigrations(): array
    {
        return $this->storage->appliedMigrations();
    }

    /**
     * The identifiers recorded in a batch.
     *
     * @return list<string>
     */
    private function migrationsInBatch(int $batch): array
    {
        return $this->storage->migrationsInBatch($batch);
    }

    /**
     * The batch number to stamp the next run with.
     *
     * One past the highest batch ever allocated, not one past the highest
     * currently recorded. The two differ after a rollback, and using the recorded
     * maximum makes batch numbers go backwards: migrate (batch 1), rollback
     * (records empty), migrate again (batch 1 again). `--batch=1` from before the
     * rollback would then silently target a different set.
     *
     * The high-water mark is kept outside the recorded rows since the point is
     * that it survives those rows being deleted. Gaps are harmless: nothing
     * iterates the batch numbers.
     */
    private function nextBatchNumber(): int
    {
        $recorded = $this->storage->maxRecordedBatch() ?? 0;

        return max($recorded, $this->storage->batchHighWaterMark()) + 1;
    }

    /**
     * The most recent batch that recorded at least one migration, or null when
     * nothing has been recorded.
     *
     * Drawn from the recorded rows rather than the high-water mark, because a
     * batch that fails partway burns its number without recording anything under
     * it. The high-water mark answers "the newest number handed out"; rollback
     * needs "the newest batch there is anything to reverse", and those differ
     * exactly after a failure. Returning the high-water mark would target an
     * empty batch and silently roll back nothing.
     */
    private function lastBatch(): ?int
    {
        return $this->storage->maxRecordedBatch();
    }

    // --------------------------------------------------------------- execution

    /**
     * Load a migration file, instantiate its class, and call up() or down().
     *
     * @param 'up'|'down' $direction
     * @param callable(string): void|null $onProgress
     *
     * @throws MigrationException
     */
    private function runMigration(MigrationFile $migration, string $direction, ?callable $onProgress): void
    {
        $instance = $this->resolve($migration);

        if ($onProgress !== null) {
            $onProgress($migration->identifier);
        }

        $this->fire(self::HOOK_MIGRATION_STARTING, $migration->identifier, $direction);

        try {
            $instance->{$direction}();
        } catch (Throwable $e) {
            // Fired before the wrap, so a listener sees the exception the
            // migration actually threw rather than this method's wrapper.
            $this->fire(self::HOOK_MIGRATION_FAILED, $migration->identifier, $direction, $e);

            // Wrapped rather than rethrown so the caller gets one exception type
            // and a message naming the migration and the direction. The original
            // is kept as `previous`.
            throw MigrationException::stepFailed($direction, $migration->identifier, $e);
        }

        $this->fire(self::HOOK_MIGRATION_FINISHED, $migration->identifier, $direction);
    }

    /**
     * Require the file and build the class it declares.
     *
     * The file is `require_once`d by path because a migration cannot be loaded by
     * class name: the class is derived from the filename (see
     * {@see MigrationFile}), so there is no PSR-4 mapping that could find it. This
     * is also why a migration directory outside the autoloader works.
     *
     * The class lands in whatever namespace the file's own `namespace` statement
     * names, which the migrator cannot know from the filename. So after requiring,
     * the newly declared classes are read off the file's namespace and the one
     * whose short name matches the filename convention is used, which keeps the
     * convention true without forcing a project into one namespace.
     *
     * @throws MigrationException
     */
    private function resolve(MigrationFile $migration): Migration
    {
        if (!is_file($migration->path)) {
            throw new MigrationException(sprintf(
                'Migration file [%s] disappeared before it could be loaded.',
                $migration->path
            ));
        }

        // Snapshot the declared classes so the one this file adds can be picked
        // out.
        $before = get_declared_classes();

        require_once $migration->path;

        $fqcn = $this->locateClass($migration->class, $migration->path, $before);

        if ($fqcn === null) {
            throw MigrationException::classNotFound($migration->class, $migration->path);
        }

        $instance = $this->instantiate($fqcn);

        if (!$instance instanceof Migration) {
            throw MigrationException::notAMigration($fqcn, $migration->path);
        }

        return $instance;
    }

    /**
     * Find the fully-qualified name of the class the migration file declared.
     *
     * Matched on short name, since the namespace is the file's choice: the
     * convention fixes the class name, not where it lives.
     *
     * The fallback for an already-loaded class is restricted to classes declared
     * by this file. A looser "any class in the process with this name" search
     * would let an unrelated file silently resolve to a same-named class loaded
     * by some earlier migration, reporting success for a file that does not
     * contain what its name promises.
     *
     * @param list<class-string> $before Classes declared before the require.
     * @return class-string|null
     */
    private function locateClass(string $shortName, string $path, array $before): ?string
    {
        foreach (array_diff(get_declared_classes(), $before) as $class) {
            if ($this->shortName($class) === $shortName) {
                return $class;
            }
        }

        // Nothing new was declared, which means the file was already required: the
        // same migration appearing twice, or a re-run in one process. Only then is
        // it correct to look beyond this file, and only at classes that came from
        // it.
        foreach (get_declared_classes() as $class) {
            if ($this->shortName($class) !== $shortName) {
                continue;
            }

            $declaredIn = (new \ReflectionClass($class))->getFileName();

            if ($declaredIn !== false && $this->samePath($declaredIn, $path)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Whether two paths name the same file, tolerating separator differences.
     */
    private function samePath(string $a, string $b): bool
    {
        $normalize = static fn (string $path): string => str_replace('\\', '/', $path);

        return $normalize($a) === $normalize($b);
    }

    /**
     * The class name without its namespace.
     */
    private function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    /**
     * Build the migration, through the container when one is reachable.
     *
     * The container is reached through the `app()` helper rather than injected,
     * because injecting it would make the constructor depend on the container
     * package and break the "framework-agnostic, temp-directory testable"
     * property. `app()` is itself function-guarded: outside a plugin that loaded
     * the framework's helpers, a migration is constructed directly.
     *
     * A migration needing dependencies without the helper falls back to `new` and
     * fails loudly there, which is correct: a constructor requiring an argument
     * genuinely cannot be resolved.
     */
    private function instantiate(string $class): object
    {
        if (function_exists('app')) {
            try {
                /** @var object $instance */
                $instance = app($class);

                return $instance;
            } catch (Throwable $e) {
                // The container could not build it, most likely an unresolvable
                // constructor dependency. Fall through to a direct instantiation
                // so a dependency-free migration still works where the container
                // is not fully booted (a cron task, an early hook).
            }
        }

        return new $class();
    }

    // ----------------------------------------------------------------- plumbing

    /**
     * Run `$work` holding the migration lock, releasing it whatever happens.
     *
     * Two `migrate` runs overlapping is what this guards against. The tracking
     * table cannot prevent it, since both processes read the same pending list
     * before either writes, and WordPress offers no schema-level transaction
     * because MySQL DDL implicitly commits.
     *
     * MySQL's `GET_LOCK()` rather than an option row, because MySQL binds the lock
     * to the connection: a process that dies takes its lock with it. A lock built
     * from `add_option()`/`get_option()` outlives the process that took it, so
     * every later run blocks on a lock nobody holds, and expiring it needs a
     * timeout that is either too short (two live runs collide) or too long (a dead
     * run blocks a deploy).
     *
     * The cost is that the lock is a MySQL feature, absent on SQLite and Postgres.
     * That is acceptable for a WordPress plugin: the schema layer is built on
     * `dbDelta()` and assumes MySQL throughout.
     *
     * Every schema-changing operation holds it, which is why the callers wrap
     * their work in this rather than doing it inline: routing them through one
     * helper is what keeps the release in a `finally`, and the failing run is
     * precisely the one whose lock must not be left held.
     *
     * A `--pretend` run takes no lock: it writes nothing and creates nothing, and
     * locking would make a harmless dry run block a real deploy.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     *
     * @throws MigrationException When the lock cannot be taken.
     */
    private function withLock(callable $work): mixed
    {
        if (Schema::isPretending()) {
            return $work();
        }

        $this->storage->acquireLock(self::LOCK_TIMEOUT_SECONDS);

        try {
            return $work();
        } finally {
            $this->storage->releaseLock();
        }
    }

    /**
     * The default migrations directory.
     *
     * `base_path()` throws when the entry file has not bound `path.base`, and it
     * is called here in the constructor, so a migrator built without an explicit
     * path depends on the plugin having loaded.
     */
    private function defaultMigrationPath(): string
    {
        if (!function_exists('base_path')) {
            throw new MigrationException(
                'No migration path was given and base_path() is unavailable. Pass an explicit path to the '
                . 'migrator when running outside the plugin.'
            );
        }

        return base_path('database/migrations');
    }
}
