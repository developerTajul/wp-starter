<?php
declare(strict_types=1);

namespace Framework\Database\Storage;

use Framework\Database\Exceptions\MigrationException;
use wpdb;

/**
 * The migration book-keeping, over `$wpdb`.
 *
 * This is where the migrator's SQL now lives, the tracking table's shape, its
 * probes, its reads and writes, the batch high-water mark in an option, and the
 * advisory lock that serialises runs.
 *
 * Why the handle is optional and resolved lazily
 *
 * `$wpdb` is a WordPress global that does not exist until WordPress has loaded,
 * and this is constructed during a service provider's `register()`, which runs
 * before that. Resolving in the constructor would make the binding order
 * load-bearing for no reason, so the handle is taken on first use instead. An
 * explicit handle is still accepted, because that is how a test drives this
 * against a fake with no WordPress present at all.
 */
class WpMigrationStorage implements MigrationStorageInterface
{
    /**
     * Table suffix, appended to the database prefix.
     *
     * The migrator's own record lives beside the tables it manages, so the
     * prefix keeps two plugins on one database from writing into each other's
     * history.
     */
    public const TABLE_SUFFIX = 'plugin_migrations';

    /**
     * The option the batch high-water mark is kept in, after the prefix.
     */
    public const HIGH_WATER_OPTION_SUFFIX = 'plugin_migrations_batch';

    /**
     * Width of the `migration` column.
     *
     * 191 is the utf8mb4 index limit on the oldest supported MySQL - 767 bytes at
     * four bytes per character - and the column carries a UNIQUE key, so a wider
     * one would fail to index on those servers.
     */
    private const MIGRATION_COLUMN_LENGTH = 191;

    /**
     * The `batch` column's type.
     *
     * Unsigned bigint matches what a migration id would be in a schema and costs
     * nothing: the column holds a counter, and a signed counter that could go
     * negative would be a bug looking for somewhere to happen.
     */
    private const BATCH_COLUMN_TYPE = 'bigint(20) unsigned';

    /**
     * Longest name MySQL will accept for an advisory lock, in characters.
     *
     * Server-side and not negotiable: a longer name is silently truncated rather
     * than rejected, which would let two unrelated locks share one.
     */
    private const MAX_LOCK_NAME_LENGTH = 64;

    private ?wpdb $wpdb;

    /**
     * @param wpdb|null $wpdb WordPress database handle. Defaults to the `$wpdb`
     *                       global, resolved on first use so this can be built
     *                       before WordPress has finished loading.
     */
    public function __construct(?wpdb $wpdb = null)
    {
        $this->wpdb = $wpdb;
    }

    public function tableName(): string
    {
        return $this->db()->prefix . self::TABLE_SUFFIX;
    }

    public function trackingTableExists(): bool
    {
        $table = $this->tableName();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- table existence probe
        // The pattern's `_` and `%` are LIKE metacharacters, and the WordPress
        // table prefix contains underscores - so `wp_plugin_migrations` would
        // match a sibling like `wpxpluginxmigrations` and a missing table would
        // be reported as present, skipping the CREATE and failing every later
        // query. Escaped with a backslash, which MySQL's LIKE honours by default.
        $found = $this->db()->get_var($this->db()->prepare(
            'SHOW TABLES LIKE %s',
            str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $table)
        ));

        return $found !== null && $found !== '';
    }

    public function trackingTableHasColumn(string $column): bool
    {
        // No prepare() here: the query has no placeholder, and WordPress 6.2+
        // reports a placeholder-less prepare() as doing-it-wrong on every call.
        // The identifier is escaped by hand instead, which prepare() would not
        // have done for a table name anyway.
        $columns = $this->db()->get_col(
            'SHOW COLUMNS FROM `' . $this->escapeIdentifier($this->tableName()) . '`',
            0
        );

        return is_array($columns) && in_array($column, $columns, true);
    }

    public function createTrackingTable(): void
    {
        $table = $this->tableName();

        $this->guardAgainstPartialWrite();

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
        // signal available - and on a CREATE TABLE it returns false for "the
        // statement failed", never for "zero rows affected". Checking it is
        // therefore meaningful here in a way it is not for a DELETE.
        if ($this->db()->query($sql) === false) {
            throw new MigrationException(sprintf(
                'Unable to create the migration tracking table [%s]: %s',
                $table,
                $this->error()
            ));
        }
    }

    public function addTrackingTableColumn(string $column, string $definition): void
    {
        $table = $this->tableName();

        $failed = $this->db()->query(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `%s` %s NOT NULL DEFAULT 0',
            $this->escapeIdentifier($table),
            $this->escapeIdentifier($column),
            $definition
        )) === false;

        if ($failed) {
            throw new MigrationException(sprintf(
                'Unable to add the [%s] column to the migration tracking table [%s]: %s',
                $column,
                $table,
                $this->error()
            ));
        }
    }

    public function dropTrackingTable(): void
    {
        $table = $this->tableName();

        if ($this->db()->query(sprintf('DROP TABLE IF EXISTS `%s`', $this->escapeIdentifier($table))) === false) {
            throw new MigrationException(sprintf(
                'Unable to drop the migration tracking table [%s]: %s',
                $table,
                $this->error()
            ));
        }
    }

    public function forgetBatchHighWaterMark(): void
    {
        if (function_exists('delete_option')) {
            delete_option($this->highWaterOption());
        }
    }

    public function appliedMigrations(): array
    {
        /** @var list<array{migration: string, batch: string}>|null $rows */
        $rows = $this->db()->get_results(
            sprintf('SELECT `migration`, `batch` FROM `%s`', $this->escapeIdentifier($this->tableName())),
            ARRAY_A
        );

        $applied = [];

        foreach ((array) $rows as $row) {
            $applied[(string) $row['migration']] = (int) $row['batch'];
        }

        return $applied;
    }

    public function migrationsInBatch(int $batch): array
    {
        $db = $this->db();

        /** @var list<array{migration: string}>|null $rows */
        $rows = $db->get_results(
            $db->prepare(
                sprintf(
                    'SELECT `migration` FROM `%s` WHERE `batch` = %%d',
                    $this->escapeIdentifier($this->tableName())
                ),
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

    public function maxRecordedBatch(): ?int
    {
        $max = $this->db()->get_var(sprintf(
            'SELECT MAX(`batch`) FROM `%s`',
            $this->escapeIdentifier($this->tableName())
        ));

        return $max === null ? null : (int) $max;
    }

    public function batchHighWaterMark(): int
    {
        if (!function_exists('get_option')) {
            return 0;
        }

        return (int) get_option($this->highWaterOption(), 0);
    }

    public function rememberBatch(int $batch): void
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

    public function record(string $identifier, int $batch): void
    {
        $table = $this->tableName();

        $this->guardAgainstPartialWrite();

        // Checked because a silently dropped row means the migration re-runs on
        // the next invocation, and a migration that runs twice is how a column
        // gets added twice or a table gets dropped when it should not be.
        if ($this->db()->insert($table, ['migration' => $identifier, 'batch' => $batch], ['%s', '%d']) === false) {
            throw new MigrationException(sprintf(
                'Migration [%s] ran, but recording it in [%s] failed: %s. '
                . 'The schema change IS applied. Fix the cause, then insert the row '
                . '(`migration` = %s, `batch` = %d) by hand so it is not run again.',
                $identifier,
                $table,
                $this->error(),
                $identifier,
                $batch
            ));
        }
    }

    public function forget(string $identifier): void
    {
        $db = $this->db();

        $this->guardAgainstPartialWrite();

        $sql = $db->prepare(
            sprintf('DELETE FROM `%s` WHERE `migration` = %%s', $this->escapeIdentifier($this->tableName())),
            $identifier
        );

        // Checked rather than ignored: a row that survives a rollback makes the
        // migration look applied, so the next `migrate` skips the schema change
        // it models and the schema and the record disagree from then on.
        if ($db->query($sql) === false) {
            throw new MigrationException(sprintf(
                'Unable to unrecord the migration [%s] from [%s]: %s. The migration\'s `down()` has run, '
                . 'so the schema change IS reversed, delete the row by hand before re-running `migrate`.',
                $identifier,
                $this->tableName(),
                $this->error()
            ));
        }
    }

    public function guardAgainstPartialWrite(): void
    {
        $db = $this->db();

        if (method_exists($db, 'set_transaction_rollback')) {
            $db->set_transaction_rollback(true);
        }
    }

    public function acquireLock(int $timeoutSeconds): void
    {
        $name = $this->lockName();

        // Bound rather than interpolated: the name is built from the prefix, and
        // a prefix set to something odd in wp-config.php should fail at the
        // database rather than inject into the statement.
        $result = $this->db()->get_var($this->db()->prepare(
            'SELECT GET_LOCK(%s, %d)',
            $name,
            $timeoutSeconds
        ));

        if ((string) $result === '1') {
            return;
        }

        // `GET_LOCK()` is three-valued and each value means something different
        // to the operator, so none of them is collapsed into a boolean: `1` is
        // taken, `0` timed out against another connection, NULL means the request
        // itself errored. The last two are reported separately so the message
        // does not send the operator looking for a competing process that does
        // not exist.
        if ($result === null) {
            throw new MigrationException(sprintf(
                'Could not request the migration lock [%s]: the database reported an error%s. '
                . 'No migration was run.',
                $name,
                $this->db()->last_error !== '' ? ' (' . $this->db()->last_error . ')' : ''
            ));
        }

        throw new MigrationException(sprintf(
            'Timed out after %d seconds waiting for the migration lock [%s], which another process holds. '
            . 'No migration was run, this usually means another deploy or `migrate` is still in progress. '
            . 'Wait for it to finish and try again. If nothing is running, the holder has a hung connection; '
            . '`SELECT IS_USED_LOCK(\'%s\')` names the connection id.',
            $timeoutSeconds,
            $name,
            $name
        ));
    }

    public function releaseLock(): void
    {
        $db = $this->db();

        // Deliberately silent about failure. `RELEASE_LOCK()` returns NULL when
        // this connection does not hold the named lock, which happens when the
        // connection was dropped mid-run - and in that case MySQL has *already*
        // released it, so there is nothing to report and nothing to fix. Throwing
        // here would turn a successful migration into a failed command for a
        // reason that is not a problem. A lock that genuinely outlives its holder
        // is impossible: it is bound to the connection.
        $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', $this->lockName()));
    }

    /**
     * The name this database's migration lock is taken under.
     *
     * Derived from the table prefix so two plugins sharing a database do not
     * serialise against each other - they touch different tables and have no
     * reason to wait for one another. The prefix is the same value that already
     * distinguishes their tables, so it distinguishes their locks for the same
     * reason.
     *
     * `GET_LOCK()` names are capped at 64 characters server-side and are silently
     * truncated rather than rejected, so the name is built short: the prefix plus
     * a fixed suffix, with no attempt to make it descriptive. A truncated name
     * would still be *a* lock, but two different long names that truncate to the
     * same 64 characters would share one - which is why length is checked rather
     * than assumed.
     */
    private function lockName(): string
    {
        $name = $this->db()->prefix . 'plugin_migrations_lock';

        if (strlen($name) > self::MAX_LOCK_NAME_LENGTH) {
            throw new MigrationException(sprintf(
                'The database prefix [%s] is too long: the migration lock name [%s] is %d characters, '
                . 'over MySQL\'s limit of %d. MySQL truncates rather than refusing, which would let two '
                . 'unrelated locks share a name. Shorten $table_prefix.',
                $this->db()->prefix,
                $name,
                strlen($name),
                self::MAX_LOCK_NAME_LENGTH
            ));
        }

        return $name;
    }

    /**
     * The option the high-water mark is kept in.
     */
    private function highWaterOption(): string
    {
        return $this->db()->prefix . self::HIGH_WATER_OPTION_SUFFIX;
    }

    /**
     * The database's last error, or a stand-in when it reported none.
     *
     * A failure with an empty `last_error` is possible - a driver that lost the
     * connection before it could set one - and "unknown database error" in the
     * message is far better than an empty pair of brackets that reads like a
     * formatting bug.
     */
    private function error(): string
    {
        $error = $this->db()->last_error;

        return $error !== '' ? $error : 'unknown database error';
    }

    /**
     * Make an identifier safe to interpolate inside backticks.
     *
     * Identifiers cannot be bound as parameters - `prepare()` only quotes
     * *values*, and a quoted table name is a syntax error - so they are escaped
     * here instead. Every name this class builds comes from the prefix and a
     * constant, so this is belt-and-braces rather than a live injection path; it
     * exists so that no caller has to reason about that.
     */
    private function escapeIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }

    /**
     * The WordPress database handle, resolved on first use.
     *
     * Resolved lazily rather than in the constructor so this can be built and
     * injected before WordPress has finished loading - which is exactly what a
     * provider's `register()` does.
     */
    private function db(): wpdb
    {
        if ($this->wpdb instanceof wpdb) {
            return $this->wpdb;
        }

        if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof wpdb) {
            throw new MigrationException(
                'No $wpdb instance is available. The migration storage needs a WordPress database handle; '
                . 'pass one to the constructor when running outside a loaded WordPress.'
            );
        }

        return $this->wpdb = $GLOBALS['wpdb'];
    }
}
