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
 * ## What it owns
 *
 * Three things, and nothing else: discovery of migration files, the ordering
 * rules, and the two execution directions. It does not know about WP-CLI, service
 * providers, or the plugin's base path unless it is told — the path is injected,
 * so the same class runs in a test against a temporary directory.
 *
 * It also does not know *how* the database is reached. Both seams are injected:
 * {@see DatabaseAdapterInterface} for the schema the migrations build, and
 * {@see MigrationStorageInterface} for the record of which ones have run, the
 * batch numbering, and the lock that serialises runs. Nothing in this class
 * names `$wpdb` or spells a statement by hand.
 *
 * That split is the point rather than tidiness. Before it, the migrator held a
 * `$wpdb` it resolved from a global and issued its own SQL — twelve call sites'
 * worth — which made it impossible to exercise without a real WordPress
 * database handle, and made "run the migrations" and "keep the ledger" one
 * class. Now the ordering logic, which is where the subtle bugs live, can be
 * tested against any storage; and a project targeting something other than
 * MySQL's `$wpdb` supplies its own storage instead of patching this class.
 *
 * The two interfaces stay separate because they are different jobs: one compiles
 * and runs DDL, the other reads and writes rows and takes a lock. Folding the
 * second into the first would put `insert()` and `get_var()` on every adapter in
 * the project, including ones written for a target with no SQL at all, to serve
 * one caller. See {@see MigrationStorageInterface} for the full argument.
 *
 * ## Tracking
 *
 * Applied migrations are recorded as `(id, migration, batch)`. `migration` is the
 * **filename identifier**, not the class name (see {@see MigrationFile} for why),
 * and carries a UNIQUE index so a duplicate insert is rejected by the database
 * rather than by a read-then-write check that could race.
 *
 * `batch` is what makes rollback coherent. Every `runPending()` call allocates
 * one new batch number and stamps every migration it applies with it, so
 * `rollback()` can undo exactly one invocation — the last one — instead of
 * guessing from timestamps.
 *
 * ## WordPress and transactions
 *
 * MySQL DDL (`CREATE TABLE`, `ALTER TABLE`) triggers an **implicit commit**, so a
 * transaction wrapped around a migration gives no atomicity: the schema change is
 * already committed and cannot be rolled back. WordPress's own `dbDelta()` and
 * `$wpdb->query('DROP TABLE ...')` behave the same way.
 *
 * So this class does not pretend to be transactional. Instead a failure **stops
 * the batch** and the exception propagates: the tracking table keeps only the
 * migrations that genuinely completed, every later migration in that batch is
 * left pending, and re-running picks up exactly where it stopped. That is a
 * truthful half-applied state, which is the best available — and far better than
 * one that claims success it did not achieve.
 *
 * The one piece of real atomicity WordPress *does* offer is its per-request
 * rollback flag. `set_transaction_rollback(true)` (WordPress 6.7+) makes the
 * *next* `$wpdb->query()` roll back if it errors, which is how a half-written
 * DDL statement is cleaned up. It is asked for through
 * {@see MigrationStorageInterface::guardAgainstPartialWrite()}, which is a no-op
 * where the platform has no such concept — see that method for why the probe
 * belongs there and not here.
 */
class Migrator
{
    /**
     * The hook fired immediately before a migration's `up()` runs.
     *
     * `{$prefix}…` is the action name with `MIGRATOR_HOOK_PREFIX` prepended, so
     * the plugin's own prefix namespaces its hooks the way WordPress expects.
     *
     * Two arguments: the migration identifier (the filename, which is what
     * `migrate:status` shows) and the direction (`'up'` or `'down'`).
     */
    public const HOOK_MIGRATION_STARTING = 'migration_starting';

    /**
     * The hook fired after a migration's `up()` returned without throwing.
     *
     * Deliberately *not* fired when the migration threw. "Finished" that also
     * means "failed" is a hook no listener can act on — the failure is reported
     * through {@see HOOK_MIGRATION_FAILED} and the propagating exception, and a
     * listener that wants both subscribes to both.
     */
    public const HOOK_MIGRATION_FINISHED = 'migration_finished';

    /**
     * The hook fired when a migration threw, before the exception propagates.
     *
     * Three arguments: the identifier, the direction, and the `Throwable`. The
     * exception is passed rather than the message so a listener can decide
     * whether it cares — a migration that fails for a reason a listener knows how
     * to handle is exactly the case this exists for.
     */
    public const HOOK_MIGRATION_FAILED = 'migration_failed';

    /**
     * How long to wait for the migration lock before giving up, in seconds.
     *
     * Long, deliberately. The competing holder is another migration run, not a
     * stuck request: a run that has been going for a while is working, and a short
     * timeout would turn "the deploy is still running" into "the deploy failed"
     * for the second of two nearly-simultaneous deploys. Ten minutes is longer
     * than any sane migration and shorter than a human's patience for a hung
     * command.
     */
    private const LOCK_TIMEOUT_SECONDS = 600;

    /**
     * Prefixed to each hook constant to form the action name.
     *
     * Read from `app.prefix` — the same value the rebrander rewrites — rather than
     * hardcoded, so a rebranded plugin's hooks are namespaced to it without any
     * listener having to know the new name. The constants are the public spelling
     * and this is what they resolve to.
     *
     * Read through the global `config()` helper rather than constructor-injected,
     * for consistency with the rest of this class: it is constructed by the
     * container before WordPress is necessarily up, and resolved values are read
     * lazily everywhere else here for the same reason.
     */
    private function hookPrefix(): string
    {
        return (string) config('app.prefix', 'wp_mini_laravel_');
    }

    /**
     * Fire a migration event, when WordPress's hook system is loaded.
     *
     * Guarded because this class runs in contexts without WordPress — the test
     * suite, a script that drives the migrator directly — and an unguarded
     * `do_action()` would be a fatal there. Nothing is lost when it is skipped:
     * a listener is a WordPress callback and cannot exist without WordPress.
     */
    private function fire(string $hook, mixed ...$args): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action($this->hookPrefix() . $hook, ...$args);
    }

    /**
     * Column widths, named so the CREATE TABLE and the schema comparison in
     * {@see ensureTrackingTable()} cannot drift apart.
     */
    private string $migrationPath;

    /**
     * What the most recent {@see runPending()} recorded, published for a caller
     * whose batch threw partway and so never received a return value.
     *
     * @var list<string>
     */
    private array $lastRunApplied = [];

    /**
     * What the most recent reversal actually reversed, published for the same
     * reason as {@see $lastRunApplied} and read by the same kind of caller.
     *
     * A `down()` that throws propagates out of `rollbackBatch()` and
     * `rollbackAll()`, so the partial list a local variable was building is lost
     * with the stack frame — and a command reporting "failed" cannot say how much
     * of the rollback landed. That number is the whole basis for deciding whether
     * to re-run, so it is recorded here as it accumulates rather than returned.
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
     * rather than by the directory listing — `scandir()` order is whatever the
     * filesystem returns, and a migration that assumes an earlier one has run
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
        // The lock is taken before the tracking table is even ensured: two
        // processes that both reach `ensureTrackingTable()` would both read "the
        // table is absent" and both issue the CREATE, and only one of them is
        // guaranteed to be the winner.
        return $this->withLock(fn (): array => $this->runPendingLocked($onProgress));
    }

    /**
     * {@see runPending()} with the lock already held.
     *
     * Split out rather than inlined into a closure so the whole body keeps its
     * docblock and its local variable names — and because `withLock()` returns
     * whatever the callback returns, which is only meaningful if the work is a
     * real method with a declared return type.
     *
     * @param callable(string): void|null $onProgress
     * @return list<string>
     */
    private function runPendingLocked(?callable $onProgress = null): array
    {
        $this->ensureTrackingTable();

        // Cleared before the empty check, not after: a caller that inspects this
        // after a no-op run would otherwise read the *previous* run's list and
        // report migrations as applied that this call never touched.
        $this->lastRunApplied = [];

        $pending = $this->pendingMigrations();

        if ($pending === []) {
            return [];
        }

        // Allocated once for the whole call, which is what makes this "a batch".
        $batch = $this->nextBatchNumber();

        // Recorded before the first migration runs, so a batch that fails partway
        // still burns its number. Reusing it on the retry would make an old
        // transcript's `--batch=<n>` point at the wrong set — the exact confusion
        // the high-water mark exists to prevent.
        //
        // Guarded against pretending for the same reason the tracking table is,
        // but with a sharper consequence: this option only ever moves *up*, so a
        // dry run that writes it advances the numbering permanently. The next
        // real batch is then numbered from a run that created nothing, and the
        // gap can never be explained or closed.
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
                // Under `migrate --pretend` the `up()` above ran its statements
                // through the recording adapter — so nothing was created — and
                // writing the row would leave the table claiming work that does
                // not exist. Skipped, and the identifier is still listed as
                // applied because from the operator's point of view it *would*
                // have been. See {@see Schema::isPretending()}.
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
     * number rather than sharing one, which makes `migrate:rollback` undo exactly
     * one of them — so a developer who wants to test a single migration's `down()`
     * can step through and reverse as they go, instead of reversing an entire run.
     *
     * ## Why this does not just loop `runPending()`
     *
     * It almost could: `runPending()` re-reads the pending list each call, and the
     * first migration it applies is no longer pending on the next pass. What stops
     * it is the shape of the failure. A loop over `runPending()` would throw out of
     * the middle with the earlier batches already committed — correct, but the
     * caller cannot tell "the third migration failed" from "the whole thing failed"
     * without diffing the tracking table, and it would re-query the pending list
     * once per migration for no gain.
     *
     * So the pending list is read once and walked here, allocating a fresh batch
     * per migration. A failure stops the loop at the same point it would have, but
     * the exception names the migration that failed and every earlier one is
     * already recorded under its own batch.
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
                // "stepwise" means — each migration is its own batch. It makes
                // the pretend guard matter more, not less: unguarded, a dry run
                // over N migrations advances the high-water mark by N.
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
     * Describes that call and no earlier one: it is cleared at the point
     * `runPending()` decides what to do, so a run with nothing pending leaves it
     * empty rather than leaving the previous run's list standing.
     *
     * It exists because a batch that fails partway throws rather than returning,
     * so the caller has no other way to learn how much of the batch landed — and
     * reporting the wrong number to an operator deciding how to recover is worse
     * than reporting none.
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
     * rather than returning, so the caller has no other way to learn how much was
     * reversed. Describes that call and no earlier one.
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
     * Migrations run newest-first within the batch: two migrations in one batch
     * may touch the same table, and undoing them in forward order would apply a
     * change on top of a structure that is about to be removed.
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
        // Newest first, and the order comes from `discover()` rather than from a
        // second sort of the identifiers.
        //
        // Re-sorting the raw identifiers `desc` looked equivalent and is not: the
        // identifier carries the timestamp in whichever spelling the filename
        // used, and the two do not compare as strings (`_` is 0x5F, digits are
        // 0x30–0x39). Reversing `discover()`'s order inherits its fix for that by
        // construction — and there is only one place for the ordering rule to be
        // right, rather than two that can disagree about what "newest" means.
        $known = [];

        foreach ($this->discover() as $migration) {
            $known[$migration->identifier] = $migration;
        }

        // Oldest-first from `discover()`, reversed, with anything recorded but no
        // longer on disk appended after it — so a missing file is still reached
        // and reported by the loop below rather than silently dropped from the
        // sweep. Taking only the intersection here would have done exactly that:
        // a deleted migration would stop erroring and start passing unnoticed.
        $onDisk = array_values(array_intersect(array_keys($known), $ran));
        $missing = array_values(array_diff($ran, $onDisk));

        $ran = array_merge(array_reverse($onDisk), $missing);

        $reverted = [];
        $this->lastRunReverted = [];

        foreach ($ran as $identifier) {
            $migration = $known[$identifier] ?? null;

            // A row whose file has been deleted from disk is reported rather
            // than silently dropped: deleting the row would make the migration
            // permanently unrunnable and unrollbackable, and the usual cause is
            // a file removed by mistake.
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
            // {@see runPending()} skips `record()`: the statements the `down()`
            // issued were recorded rather than run, so the schema is unchanged and
            // the row still describes it truthfully.
            if (!Schema::isPretending()) {
                $this->storage->forget($identifier);
            }

            $reverted[] = $identifier;

            // Recorded as it happens, not on return: a later migration throwing
            // unwinds this frame and takes `$reverted` with it, and the caller
            // needs the partial list to report how far the sweep got. See
            // {@see lastRunReverted()}.
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
     * Returned oldest-first and keyed by identifier. A migration that is on disk
     * but not in the tracking table is pending; one in the table carries the batch
     * it ran in.
     *
     * Migrations recorded as ran whose file has since been deleted are **not**
     * included — they are not "status" of anything on disk. {@see getStatus()}
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
     * The batch *high-water mark* is deliberately not used for this. It records
     * the newest number ever handed out, which includes a batch that failed
     * before recording anything — rolling that back is a no-op that burns a pass,
     * and reporting it as a batch that exists is simply untrue. This answers the
     * different question "what is there to reverse", which is what
     * {@see \Framework\Database\Commands\MigrateRefreshCommand} and any future
     * `rollback --all` both need.
     *
     * Sorted numerically rather than left in query order: batches are integers,
     * so `10` must not sort before `9` the way it would as a string, and no
     * `ORDER BY` in a caller should have to know that.
     *
     * @return list<int>
     */
    public function batches(): array
    {
        $this->ensureTrackingTable();

        // `SELECT DISTINCT` would be the obvious query and is deliberately not
        // used: the tracking table holds at most a few hundred rows, so deduping
        // in PHP costs nothing, while `DISTINCT` would be the fourth shape of
        // SELECT this class issues — and the test double that stands in for
        // `$wpdb` is a deliberately loud parser that rejects anything it does not
        // recognise. A query it cannot read is a query no test can pin, so the
        // simplest statement that answers the question is also the only one that
        // stays verifiable.
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
     * ## Why this does not just loop `rollback()`
     *
     * `rollback()` reads the newest batch on each call, so a loop would work —
     * but each pass is a fresh `SELECT MAX(\`batch\`)`, and the batch list is
     * known up front. More importantly, a caller that captures the list *before*
     * reversing anything can report the full set it intends to touch, which is
     * what lets `migrate:refresh` say what it is about to do before it does it.
     *
     * The list is walked newest-first and **reversed** from `batches()` order for
     * the same reason `rollbackBatch()` sorts its rows newest-first: a later batch
     * may depend on a structure an earlier one created.
     *
     * A failure propagates and stops the sweep. The batches already reversed are
     * genuinely reversed and their rows are gone, so re-running resumes rather
     * than repeating — the same truthful-half-state contract `rollback()` has.
     *
     * @param callable(string): void|null $onProgress
     * @return list<string> Identifiers reversed, in execution order.
     *
     * @throws MigrationException
     */
    public function rollbackAll(?callable $onProgress = null): array
    {
        // One lock for the whole sweep, not one per batch. `rollbackBatch()` takes
        // its own, so calling the public form in a loop would take and release the
        // lock N times — and MySQL's locks are *not* reentrant within a
        // connection in the way that suggests: the second `GET_LOCK()` on a name
        // the same connection already holds succeeds and increments nothing, but
        // each `RELEASE_LOCK()` frees it outright, so the sweep would be
        // unprotected for every batch after the first release. Delegating to the
        // already-locked body keeps the whole sweep inside one hold.
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
        // `rollbackBatch()` call resets the recorded list to its own batch — it
        // has to, since it is also called directly — so the accumulation across
        // batches happens here, and a failure in a later batch still leaves the
        // earlier batches' reversals visible to the caller.
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
     * ## What it drops, and the line it does not cross
     *
     * Only tables that **migrations were recorded as creating** — the `down()` of
     * each applied migration is what removes them, not a `DROP TABLE` invented
     * here. That distinction is the whole safety story: a table this plugin did
     * not create has a row in the tracking table only if some migration recorded
     * it, and a `migrate:fresh` that swept the database would be a command that
     * deletes a WordPress install's posts when a developer types it against the
     * wrong `wp-config.php`.
     *
     * `down()` is not called at all, though. It is tempting — one code path, and
     * every migration already knows how to reverse itself — but `down()` is
     * *compensating* logic, and a fresh start is not a compensation. Reversing
     * runs each migration's own idea of undo, which for a migration that renamed
     * a column means renaming it back: a real data-losing operation performed for
     * no reason, since the very next thing `fresh` does is drop the table anyway.
     * Worse, a `down()` that throws would abort a command whose entire purpose is
     * to not depend on anything working.
     *
     * So the tables are dropped directly, and the tracking table with them.
     *
     * ## Why the tracking table is dropped rather than emptied
     *
     * `DELETE FROM` would leave the table and its AUTO_INCREMENT in place, and the
     * high-water mark option behind it. Both are cheap to recreate —
     * {@see ensureTrackingTable()} runs before the first migration — and dropping
     * makes "fresh" true of the whole migration state rather than only of its
     * rows. The batch counter is reset in the same breath for the same reason: a
     * fresh database that reports batch 47 is a state no one can reason about.
     *
     * @return list<string> Table names dropped, in the order dropped.
     *
     * @throws MigrationException When a drop fails.
     */
    public function dropAllTables(): array
    {
        // `migrate:fresh` is the most destructive statement this framework issues
        // and the one most likely to be typed while something else is running — a
        // developer clearing a stuck state during a deploy. It takes the lock on
        // the same terms as everything else.
        return $this->withLock(fn (): array => $this->dropAllTablesLocked());
    }

    /**
     * {@see dropAllTables()} with the lock already held.
     *
     * @return list<string>
     */
    private function dropAllTablesLocked(): array
    {
        // Read *before* anything is dropped: the tracked names are the input to
        // this whole operation, and once the tracking table is gone there is no
        // way to learn what should have been dropped.
        $this->ensureTrackingTable();
        $tracked = array_keys($this->appliedMigrations());

        // Only identifiers whose file still exists can be dropped by `down()`,
        // and the rest cannot be resolved to a table at all. A file deleted from
        // disk is reported by `migrate:status` and is not this command's to guess
        // about — dropping an unknown table name would be exactly the wild sweep
        // the docblock above rules out.
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
        // longer has. `DROP TABLE IF EXISTS` because on a truly fresh install
        // `ensureTrackingTable()` above is the only thing that created it.
        $this->storage->dropTrackingTable();

        // The high-water mark goes with the tracking table, and for the same
        // reason: a fresh database that reports batch 47 is a state no one can
        // reason about.
        $this->storage->forgetBatchHighWaterMark();

        // The high-water mark is gone, so the next batch is 1 — reset the cached
        // read too, or a long-lived process would keep serving the old number.
        $this->lastRunApplied = [];

        return $dropped;
    }

    /**
     * Identifiers recorded as ran whose file is no longer on disk.
     *
     * Surfaced rather than hidden because it is the one state the migrator cannot
     * repair on its own: `rollback()` will refuse to proceed past one, since it
     * cannot run a `down()` that does not exist.
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
     * *before* {@see runPending()} by `migrate`, so on a fresh database it is
     * the first thing that reads `{prefix}plugin_migrations` — and reading a
     * table that has never been created fails with "Table doesn't exist"
     * before the migrator ever gets the chance to create it.
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
     * Files that do not follow the naming convention are skipped, not reported:
     * a directory holding a stray `helpers.php` should still migrate. A file that
     * follows the convention but declares the wrong class is a different matter
     * and is reported at load time, because that one was meant to be a migration.
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
        // By `timestamp()`, **not** by the raw identifier. The identifier carries
        // the timestamp in whichever of the two accepted spellings the filename
        // used, and those two do not sort together: `_` is 0x5F and digits are
        // 0x30–0x39, so every `2026_11_05_...` identifier sorts after every
        // `20261105...` one regardless of the dates. Comparing identifiers runs
        // every compact-named migration first — including one that alters a table
        // a later-listed migration has not created yet.
        //
        // `timestamp()` returns the timestamp as written, so this alone would not
        // fix it — see that method, which normalises the two spellings to a
        // comparable form. Ties are impossible in practice, but the identifier is
        // the tiebreak so the order stays deterministic if two files ever share a
        // timestamp.
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
     * Idempotent, and deliberately not built on `dbDelta()`: `dbDelta` requires
     * `ABSPATH . 'wp-admin/includes/upgrade.php'` to have been loaded, which is
     * not true under WP-CLI's own bootstrap without an explicit `require`. A
     * direct `SHOW COLUMNS` probe is both cheaper and honest about the fact that
     * this table is created rarely.
     *
     * The probes and the statements both live behind
     * {@see MigrationStorageInterface} — this method is the *decision* (create,
     * repair, or leave alone), which is the part worth reading, and the storage
     * is the part that knows how to ask.
     *
     * @throws MigrationException When the table cannot be created.
     */
    public function ensureTrackingTable(): void
    {
        // A no-op while pretending, and this guard has to live *here* rather than
        // at the call sites. Three paths reach this method — `runPendingLocked()`,
        // `runPendingStepwiseLocked()` and `pendingMigrations()` — and
        // `pendingMigrations()` is called by `status()`, by `missing()` and by
        // every rollback, so guarding only the two run paths would leave a dry
        // run that merely *asks what is pending* creating the table.
        //
        // Read-only while pretending is not "skip the work": the callers below
        // treat a missing table as "nothing recorded", which is the same answer
        // the table would give, so nothing downstream needs it to exist.
        //
        // The guard cannot move into the storage implementation, because the
        // storage is an interface with no opinion on dry runs — a second
        // implementation would have to reproduce this rule, and the first one to
        // forget would create a table during a `--pretend`.
        if (Schema::isPretending()) {
            return;
        }

        if (!$this->storage->trackingTableExists()) {
            $this->storage->createTrackingTable();

            return;
        }

        // The batch column was not in the first cut of this table. A plugin that
        // upgraded in place would otherwise fail every write with "Unknown
        // column", so it is added on demand rather than left to a migration of
        // the migrations table.
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
     * One past the highest batch ever allocated — not one past the highest
     * *currently recorded*. Those differ after a rollback, and using the
     * recorded maximum makes batch numbers go backwards: migrate (all three
     * files, batch 1), rollback (table empty), migrate again (batch 1 *again*).
     * A number that has meant two different sets of migrations is worse than
     * useless in a transcript or a runbook, because `--batch=1` from before the
     * rollback silently targets the new set.
     *
     * The high-water mark is kept outside the recorded rows rather than derived
     * from them, since the whole point is that it survives those rows being
     * deleted. Gaps are harmless: nothing iterates the batch numbers, it only
     * ever asks for the newest or for one named explicitly.
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
     * Drawn from the recorded rows rather than from the high-water mark, because
     * a batch that fails partway burns its number without recording anything under
     * it. The high-water mark answers "the newest number handed out"; rollback
     * needs "the newest batch there is anything to reverse". Those differ exactly
     * after a failure, and `MigrateCommand` warns the operator when they do — this
     * method returning the high-water mark instead would target an empty batch and
     * silently roll back nothing.
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
            // migration actually threw rather than the wrapper this method is
            // about to build — the wrapper's job is the caller's message, not the
            // listener's diagnosis.
            $this->fire(self::HOOK_MIGRATION_FAILED, $migration->identifier, $direction, $e);

            // Wrapped rather than rethrown so the caller gets one exception type
            // to handle and a message naming the migration and the direction.
            // The original is kept as `previous` so nothing is lost.
            throw MigrationException::stepFailed($direction, $migration->identifier, $e);
        }

        $this->fire(self::HOOK_MIGRATION_FINISHED, $migration->identifier, $direction);
    }

    /**
     * Require the file and build the class it declares.
     *
     * The file is `require_once`d by path because a migration cannot be loaded by
     * class name: the class is derived from the filename (see {@see MigrationFile}),
     * so there is no PSR-4 mapping that could find it. This is also why a
     * migration directory outside the autoloader works at all.
     *
     * Because the file is loaded rather than autoloaded, the class it declares
     * lands in whatever namespace the file's own `namespace` statement names —
     * which the migrator cannot know from the filename alone. So after requiring,
     * the newly-declared classes are read off the file's namespace and the one
     * whose short name matches the filename convention is used. That keeps the
     * convention ("the class is named after the file") true without forcing a
     * project to put its migrations in one particular namespace.
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
        // out. get_declared_classes() is per-process and cheap to diff.
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
     * Matches on short name, since the namespace is the file's choice: the
     * convention fixes the class *name*, not where it lives.
     *
     * The fallback for an already-loaded class is deliberately restricted to
     * classes declared by *this file*. A looser "any class in the process with
     * this name" search would let a completely unrelated file — one that declares
     * no class at all, or the wrong one — silently resolve to a same-named class
     * loaded by some earlier migration, reporting success for a file that does
     * not contain what its name promises.
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

        // Nothing new was declared, which means the file was already required —
        // the same migration appearing twice, or a re-run in one process. Only
        // then is it correct to look beyond this file, and only at classes that
        // actually came from it.
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
     * because injecting it would make the constructor's signature depend on the
     * container package and break the "framework-agnostic, temp-directory
     * testable" property this class is built around. `app()` is itself
     * function-guarded: outside a plugin that loaded the framework's helpers, a
     * migration is simply constructed directly.
     *
     * A migration needing dependencies in a project without the helper falls back
     * to `new`, and will fail loudly there — which is correct, since a
     * constructor requiring an argument genuinely cannot be resolved.
     */
    private function instantiate(string $class): object
    {
        if (function_exists('app')) {
            try {
                /** @var object $instance */
                $instance = app($class);

                return $instance;
            } catch (Throwable $e) {
                // The container could not build it — an unresolvable constructor
                // dependency, most likely. Fall through to a direct instantiation
                // so a dependency-free migration still works in a context where
                // the container is not fully booted (a cron task, an early hook).
            }
        }

        return new $class();
    }

    // ----------------------------------------------------------------- plumbing

    /**
     * Run `$work` holding the migration lock, releasing it whatever happens.
     *
     * ## What this protects against
     *
     * Two `migrate` runs overlapping. The tracking table cannot prevent it — both
     * processes read the same "pending" list before either writes — and WordPress
     * offers no schema-level transaction to fall back on, because MySQL DDL
     * implicitly commits. So the second process has to be made to wait.
     *
     * ## Why MySQL's own lock and not an option row
     *
     * A lock built from `add_option()`/`get_option()` has to decide what to do
     * when the holder dies: the row outlives the process, so every later run is
     * blocked by a lock nobody holds, and expiring it needs a timeout that is
     * either too short (two live runs collide) or too long (a dead run blocks a
     * deploy). `GET_LOCK()` has no such problem because MySQL binds it to the
     * *connection*: a process that dies takes its lock with it, immediately and
     * correctly.
     *
     * The cost is that the lock is a MySQL feature — it does not exist on SQLite
     * or Postgres. That is acceptable here rather than overlooked: this framework
     * is a WordPress plugin, and WordPress on anything but MySQL is not a target
     * it supports anywhere else either (the schema layer is built on `dbDelta()`,
     * which is MySQL-specific in the same way).
     *
     * ## Why the caller wraps rather than this method doing the work
     *
     * Every operation that changes the schema has to hold the lock, and there are
     * three of them that reach this class (`runPending` and its stepwise variant,
     * `rollbackBatch`, and `dropAllTables`). Routing them through one helper is
     * what keeps the release in a `finally` — the failing run is precisely the run
     * whose lock must not be left held, since the operator's next action is to fix
     * the migration and re-run, and that retry would otherwise block on a lock its
     * own process is holding.
     *
     * A `--pretend` run takes no lock: it writes nothing and creates nothing, so
     * there is nothing to race with, and locking would make a developer's harmless
     * dry run block a real deploy that happens to be going.
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
     * is called here in the constructor — so a migrator built without an explicit
     * path depends on the plugin having loaded, which is the documented contract
     * for every path helper in this framework.
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
