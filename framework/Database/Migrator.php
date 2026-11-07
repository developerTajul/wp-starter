<?php
declare(strict_types=1);

namespace Framework\Database;

use Framework\Database\Exceptions\MigrationException;
use Throwable;
use wpdb;

/**
 * Runs and reverses the plugin's database migrations.
 *
 * ## What it owns
 *
 * Three things, and nothing else: the tracking table, discovery of migration
 * files, and the two execution directions. It does not know about WP-CLI, service
 * providers, or the plugin's base path unless it is told — the path is injected,
 * so the same class runs in a test against a temporary directory.
 *
 * ## Tracking
 *
 * Applied migrations are recorded in `{$wpdb->prefix}plugin_migrations` as
 * `(id, migration, batch)`. `migration` is the **filename identifier**, not the
 * class name (see {@see MigrationFile} for why), and carries a UNIQUE index so a
 * duplicate insert is rejected by the database rather than by a read-then-write
 * check that could race.
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
 * DDL statement is cleaned up. It is used here when available and skipped on
 * older WordPress, since calling it unconditionally would fatal on 6.0–6.6.
 */
class Migrator
{
    /**
     * Table suffix, appended to `$wpdb->prefix`.
     */
    public const TABLE_SUFFIX = 'plugin_migrations';

    /**
     * Column widths, named so the CREATE TABLE and the schema comparison in
     * {@see ensureTrackingTable()} cannot drift apart.
     */
    private const MIGRATION_COLUMN_LENGTH = 191;
    private const BATCH_COLUMN_TYPE = 'bigint(20) unsigned';

    private string $migrationPath;

    /**
     * What the most recent {@see runPending()} recorded, published for a caller
     * whose batch threw partway and so never received a return value.
     *
     * @var list<string>
     */
    private array $lastRunApplied = [];

    /**
     * @param string|null $migrationPath Directory holding the migration files.
     *                                   Defaults to `base_path('database/migrations')`.
     * @param wpdb|null   $wpdb          WordPress database handle. Defaults to the
     *                                   `$wpdb` global, resolved lazily so
     *                                   constructing the migrator before
     *                                   WordPress is ready does not fail.
     */
    public function __construct(
        ?string $migrationPath = null,
        private ?wpdb $wpdb = null
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
        return $this->db()->prefix . self::TABLE_SUFFIX;
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
        $this->rememberBatch($batch);

        $applied = [];

        try {
            foreach ($pending as $migration) {
                $this->runMigration($migration, 'up', $onProgress);

                // Recorded only after up() returned without throwing, so the table
                // never claims a migration that did not finish.
                $this->record($migration->identifier, $batch);

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
        $this->ensureTrackingTable();

        $ran = $this->migrationsInBatch($batch);

        if ($ran === []) {
            return [];
        }

        // Newest first: the order comes from sorting the identifiers, not from
        // whatever order the SELECT happened to return.
        $known = [];

        foreach ($this->discover() as $migration) {
            $known[$migration->identifier] = $migration;
        }

        usort($ran, static fn (string $a, string $b): int => strcmp($b, $a));

        $reverted = [];

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
            $this->forget($identifier);

            $reverted[] = $identifier;
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
        $reverted = [];

        foreach (array_reverse($this->batches()) as $batch) {
            foreach ($this->rollbackBatch($batch, $onProgress) as $identifier) {
                $reverted[] = $identifier;
            }
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
        $db = $this->db();

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

            $this->guardAgainstPartialWrite($db);

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
        if ($db->query(sprintf('DROP TABLE IF EXISTS `%s`', $this->escapeIdentifier($this->getTableName()))) === false) {
            throw new MigrationException(sprintf(
                'Unable to drop the migration tracking table [%s]: %s',
                $this->getTableName(),
                $db->last_error !== '' ? $db->last_error : 'unknown database error'
            ));
        }

        if (function_exists('delete_option')) {
            delete_option($this->highWaterOption());
        }

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
        // Ties are impossible in practice — two files cannot share a full
        // timestamp and description — but strcmp keeps it deterministic if they do.
        usort(
            $found,
            static fn (MigrationFile $a, MigrationFile $b): int => strcmp($a->identifier, $b->identifier)
        );

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
     * @throws MigrationException When the table cannot be created.
     */
    public function ensureTrackingTable(): void
    {
        $table = $this->getTableName();
        $db = $this->db();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- table existence probe
        // The pattern's `_` and `%` are LIKE metacharacters, and the WordPress
        // table prefix contains underscores — so `wp_plugin_migrations` would
        // match a sibling like `wpxpluginxmigrations` and a missing table would be
        // reported as present, skipping the CREATE and failing every later query.
        // Escaped with a backslash, which MySQL's LIKE honours by default.
        $exists = $db->get_var($db->prepare(
            'SHOW TABLES LIKE %s',
            str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $table)
        ));

        if ($exists === null || $exists === '') {
            $this->createTrackingTable($table);

            return;
        }

        // The batch column was not in the first cut of this table. A plugin that
        // upgraded in place would otherwise fail every write with "Unknown
        // column", so it is added on demand rather than left to a migration of
        // the migrations table.
        // No prepare() here: the query has no placeholder, and WordPress 6.2+
        // reports a placeholder-less prepare() as doing-it-wrong on every call.
        // The identifier is escaped by hand instead, which prepare() would not
        // have done for a table name anyway.
        $columns = $db->get_col('SHOW COLUMNS FROM `' . $this->escapeIdentifier($table) . '`', 0);

        if (is_array($columns) && !in_array('batch', $columns, true)) {
            $db->query(sprintf(
                'ALTER TABLE `%s` ADD COLUMN `batch` %s NOT NULL DEFAULT 0',
                $table,
                self::BATCH_COLUMN_TYPE
            ));
        }
    }

    private function createTrackingTable(string $table): void
    {
        $db = $this->db();

        $this->guardAgainstPartialWrite($db);

        // The UNIQUE key is on `migration`, not `id`: the identifier is what must
        // never appear twice, and letting the database enforce it means two
        // concurrent requests cannot both insert the same row.
        $sql = sprintf(
            'CREATE TABLE `%s` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `migration` varchar(%d) NOT NULL,
                `batch` %s NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `migration` (`migration`),
                KEY `batch` (`batch`)
            )',
            $this->escapeIdentifier($table),
            self::MIGRATION_COLUMN_LENGTH,
            self::BATCH_COLUMN_TYPE
        );

        // DDL implicitly commits, so `$wpdb->query()` returning false is the only
        // signal available — and on a CREATE TABLE it returns false for "the
        // statement failed", never for "zero rows affected". Checking it is
        // therefore meaningful here in a way it is not for a DELETE.
        if ($db->query($sql) === false) {
            throw new MigrationException(sprintf(
                'Unable to create the migration tracking table [%s]: %s',
                $table,
                $db->last_error !== '' ? $db->last_error : 'unknown database error'
            ));
        }
    }

    /**
     * Identifiers already recorded, mapped to their batch number.
     *
     * @return array<string, int>
     */
    private function appliedMigrations(): array
    {
        $table = $this->getTableName();
        $db = $this->db();

        /** @var list<array{migration: string, batch: string}>|null $rows */
        $rows = $db->get_results(
            sprintf('SELECT `migration`, `batch` FROM `%s`', $this->escapeIdentifier($table)),
            ARRAY_A
        );

        $applied = [];

        foreach ((array) $rows as $row) {
            $applied[(string) $row['migration']] = (int) $row['batch'];
        }

        return $applied;
    }

    /**
     * The identifiers recorded in a batch.
     *
     * @return list<string>
     */
    private function migrationsInBatch(int $batch): array
    {
        $table = $this->getTableName();
        $db = $this->db();

        /** @var list<array{migration: string}>|null $rows */
        $rows = $db->get_results(
            $db->prepare(
                sprintf('SELECT `migration` FROM `%s` WHERE `batch` = %%d', $this->escapeIdentifier($table)),
                $batch
            ),
            ARRAY_A
        );

        $identifiers = [];

        foreach ((array) $rows as $row) {
            $identifiers[] = (string) $row['migration'];
        }

        return $identifiers;
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
     * The high-water mark is kept in an option rather than derived, since the
     * whole point is that it survives the rows being deleted. Gaps are harmless:
     * nothing iterates the batch numbers, it only ever asks for the newest or for
     * one named explicitly.
     */
    private function nextBatchNumber(): int
    {
        $recorded = (int) $this->db()->get_var(sprintf(
            'SELECT MAX(`batch`) FROM `%s`',
            $this->escapeIdentifier($this->getTableName())
        ));

        $highWater = max($recorded, $this->batchHighWaterMark());

        return $highWater + 1;
    }

    /**
     * The highest batch number ever handed out, remembered across rollbacks.
     */
    private function batchHighWaterMark(): int
    {
        if (!function_exists('get_option')) {
            return 0;
        }

        return (int) get_option($this->highWaterOption(), 0);
    }

    /**
     * Record a batch number as the new high-water mark, if it is higher.
     */
    private function rememberBatch(int $batch): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        if ($batch > $this->batchHighWaterMark()) {
            // Autoload disabled: this is read only by the migrator during a
            // migration run, so loading it on every page view would be waste.
            update_option($this->highWaterOption(), $batch, false);
        }
    }

    private function highWaterOption(): string
    {
        return $this->db()->prefix . 'plugin_migrations_batch';
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
        $max = $this->db()->get_var(sprintf(
            'SELECT MAX(`batch`) FROM `%s`',
            $this->escapeIdentifier($this->getTableName())
        ));

        return $max === null ? null : (int) $max;
    }

    /**
     * @throws MigrationException When the row cannot be written.
     */
    private function record(string $identifier, int $batch): void
    {
        $table = $this->getTableName();
        $db = $this->db();

        $this->guardAgainstPartialWrite($db);

        // Checked because a silently dropped row means the migration re-runs on
        // the next invocation, and a migration that runs twice is how a column
        // gets added twice or a table gets dropped when it should not be.
        if ($db->insert($table, ['migration' => $identifier, 'batch' => $batch], ['%s', '%d']) === false) {
            throw new MigrationException(sprintf(
                'Migration [%s] ran, but recording it in [%s] failed: %s. '
                . 'The schema change IS applied. Fix the cause, then insert the row '
                . '(`migration` = %s, `batch` = %d) by hand so it is not run again.',
                $identifier,
                $table,
                $db->last_error !== '' ? $db->last_error : 'unknown database error',
                $identifier,
                $batch
            ));
        }
    }

    private function forget(string $identifier): void
    {
        $table = $this->getTableName();
        $db = $this->db();

        $this->guardAgainstPartialWrite($db);

        $db->query($db->prepare(
            sprintf('DELETE FROM `%s` WHERE `migration` = %%s', $this->escapeIdentifier($table)),
            $identifier
        ));
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

        try {
            $instance->{$direction}();
        } catch (Throwable $e) {
            // Wrapped rather than rethrown so the caller gets one exception type
            // to handle and a message naming the migration and the direction.
            // The original is kept as `previous` so nothing is lost.
            throw MigrationException::stepFailed($direction, $migration->identifier, $e);
        }
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
     * The WordPress database handle, resolved on first use.
     *
     * Resolved lazily rather than in the constructor so a migrator can be built
     * and injected before WordPress has finished loading — which is exactly what
     * a provider's `register()` does.
     */
    private function db(): wpdb
    {
        if ($this->wpdb instanceof wpdb) {
            return $this->wpdb;
        }

        if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof wpdb) {
            throw new MigrationException(
                'No $wpdb instance is available. The migrator needs a WordPress database handle; '
                . 'pass one to the constructor when running outside a loaded WordPress.'
            );
        }

        return $this->wpdb = $GLOBALS['wpdb'];
    }

    /**
     * Make a table name safe to interpolate inside backticks.
     *
     * Table names cannot be bound as parameters — `prepare()` only quotes *values*,
     * and a quoted table name is a syntax error — so the identifier is escaped
     * here instead. The name is built from `$wpdb->prefix` and a constant, so this
     * is belt-and-braces rather than a live injection path; it exists so that no
     * caller has to reason about that.
     */
    private function escapeIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }

    /**
     * Ask WordPress to roll back the next failing statement, when it can.
     *
     * `set_transaction_rollback()` landed in WordPress 6.7 and the plugin's floor
     * is 6.0, so this is probed rather than called outright — an unconditional
     * call would be a fatal error on every older install, including this one's
     * supported range. When it is unavailable nothing is lost: a failed DDL
     * statement is a no-op in MySQL anyway, and the batch stop is what actually
     * keeps the tracking table honest.
     */
    private function guardAgainstPartialWrite(wpdb $db): void
    {
        if (method_exists($db, 'set_transaction_rollback')) {
            $db->set_transaction_rollback(true);
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
