<?php
declare(strict_types=1);

namespace Framework\Database\Storage;

/**
 * Everything the migrator needs to keep its own book-keeping.
 *
 * ## Why this exists
 *
 * {@see \Framework\Database\Migrator} ran migrations *and* kept the record of
 * which ones had run, and it kept that record by reaching for `$wpdb` directly:
 * an existence probe, a `SELECT MAX(batch)`, an `INSERT` per migration, a
 * `GET_LOCK` around the whole run. Twelve call sites, all of them spelling SQL
 * by hand against a global.
 *
 * That made the migrator impossible to exercise without a real WordPress
 * database handle, and it put row-level SQL in a class whose actual job is
 * ordering and running migration files. The two concerns were never separable
 * because they shared a connection rather than an interface.
 *
 * This is that interface. The migrator knows what it needs — *is this recorded*,
 * *what batch was last*, *take the lock* — and nothing about how any of it is
 * stored. The `$wpdb` version is one implementation; a version over a different
 * database, or an in-memory one for a test, is another.
 *
 * ## Why it is not {@see \Framework\Database\Adapters\DatabaseAdapterInterface}
 *
 * Because that interface is a *schema* seam and says so: it compiles and runs
 * DDL, and its contract is deliberately free of query building. Widening it to
 * carry row reads would put `get_var()` and `insert()` on every adapter in the
 * project — including ones written for a target that has no SQL at all — to
 * serve a single caller. The two seams are genuinely different jobs, so they are
 * two types.
 *
 * ## The lock is part of the contract, not an implementation detail
 *
 * A real `GET_LOCK` is a server-side concern with a real failure mode — another
 * deploy holding it — and the migrator's whole batch-numbering guarantee rests
 * on only one process running at a time. A version of this interface that
 * quietly no-opped the lock would let two concurrent runs interleave, so
 * {@see acquireLock()} is required to actually exclude, and to report when it
 * could not.
 */
interface MigrationStorageInterface
{
    /**
     * The table migration records live in, fully qualified.
     *
     * Returned qualified because it is what a message has to name when a write
     * fails: an operator told "recording in [plugin_migrations] failed" cannot
     * find the table, and one told `wp_plugin_migrations` can.
     */
    public function tableName(): string;

    /**
     * Whether the tracking table is present.
     *
     * A *catalogue probe*, not "attempt something and catch the failure". The
     * caller is deciding whether to create the table, and a probe that failed for
     * an unrelated reason — permissions, a dropped connection — must not be read
     * as an absence that justifies a `CREATE`.
     */
    public function trackingTableExists(): bool;

    /**
     * Whether the tracking table carries the given column.
     *
     * Asked because the `batch` column was not in the first cut of the table. A
     * plugin that upgraded in place would otherwise fail every write with
     * "Unknown column", so the caller adds it on demand — and it can only decide
     * that by asking.
     */
    public function trackingTableHasColumn(string $column): bool;

    /**
     * Create the tracking table.
     *
     * @throws \Framework\Database\Exceptions\MigrationException When it cannot be
     *         created, with the database's own error in the message.
     */
    public function createTrackingTable(): void;

    /**
     * Add a column to the tracking table.
     *
     * @throws \Framework\Database\Exceptions\MigrationException When it fails.
     */
    public function addTrackingTableColumn(string $column, string $definition): void;

    /**
     * Drop the tracking table if it is there.
     *
     * Tolerant of absence on purpose, and the caller relies on it: on a truly
     * fresh install the only thing that ever created the table is the code path
     * this is undoing, so requiring it to exist would strand exactly the case
     * this is called for.
     *
     * @throws \Framework\Database\Exceptions\MigrationException When the drop
     *         fails for a reason other than absence.
     */
    public function dropTrackingTable(): void;

    /**
     * Delete the remembered batch high-water mark.
     *
     * Separate from {@see dropTrackingTable()} because it is a different concern
     * that happens to be reset at the same moment: the table holds which
     * migrations ran and the mark holds how high batch numbers have gone, and a
     * `migrate:fresh` resets both.
     */
    public function forgetBatchHighWaterMark(): void;

    /**
     * Identifiers already recorded, mapped to their batch number.
     *
     * @return array<string, int>
     */
    public function appliedMigrations(): array;

    /**
     * The identifiers recorded in a batch, in the order they were recorded.
     *
     * @return list<string>
     */
    public function migrationsInBatch(int $batch): array;

    /**
     * The highest batch number currently recorded, or null when none is.
     *
     * Answers "the newest batch there is anything to reverse", which is *not* the
     * high-water mark: a batch that failed partway burns its number without
     * recording anything under it. Rollback needs this one, because targeting the
     * high-water mark would target an empty batch and silently reverse nothing.
     */
    public function maxRecordedBatch(): ?int;

    /**
     * The highest batch number ever handed out, remembered across rollbacks.
     *
     * Survives the recording rows being deleted, which is the whole point: using
     * the *recorded* maximum makes batch numbers go backwards after a rollback,
     * and a number that has meant two different sets of migrations is worse than
     * useless in a runbook.
     */
    public function batchHighWaterMark(): int;

    /**
     * Raise the high-water mark, if the given batch is higher than the current one.
     *
     * Monotonic by contract — it never moves down — because a caller that could
     * lower it would reintroduce the reuse the mark exists to prevent.
     */
    public function rememberBatch(int $batch): void;

    /**
     * Record a migration as applied in the given batch.
     *
     * @throws \Framework\Database\Exceptions\MigrationException When the row
     *         cannot be written. Checked rather than ignored because a silently
     *         dropped row means the migration runs again on the next invocation,
     *         and a migration that runs twice is how a column gets added twice.
     */
    public function record(string $identifier, int $batch): void;

    /**
     * Remove a migration's record.
     *
     * @throws \Framework\Database\Exceptions\MigrationException When the delete
     *         fails. A row that survives a rollback makes the migration look
     *         applied, so the next `migrate` skips the schema change it models.
     */
    public function forget(string $identifier): void;

    /**
     * Ask the database to roll back the next failing statement, where it can.
     *
     * Not a transaction — DDL implicitly commits in MySQL and cannot be wrapped
     * in one. This is the narrower protection the platform does offer, and it is
     * best-effort: an implementation on a target without the concept does
     * nothing, and nothing is lost, because the batch stop is what actually keeps
     * the record honest.
     */
    public function guardAgainstPartialWrite(): void;

    /**
     * Take the migration lock, waiting up to the given timeout.
     *
     * @param int $timeoutSeconds How long to wait for a holder to release.
     *
     * @throws \Framework\Database\Exceptions\MigrationException When the lock
     *         could not be taken — whether because another process holds it past
     *         the timeout or because the request itself errored. The two are
     *         distinguished in the message, because one means "wait" and the other
     *         means "something is wrong".
     */
    public function acquireLock(int $timeoutSeconds): void;

    /**
     * Release the migration lock.
     *
     * Deliberately silent about failure: this runs in a `finally`, on the way out
     * of a run that may already be failing, and throwing here would replace the
     * operator's real error with a message about the lock. A lock left held is
     * released when the connection closes.
     */
    public function releaseLock(): void;
}
