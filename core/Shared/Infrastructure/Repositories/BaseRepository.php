<?php

declare(strict_types=1);

namespace Core\Shared\Infrastructure\Repositories;

use RuntimeException;
use Throwable;
use wpdb;

/**
 * Shared write helpers for module repositories that talk to `$wpdb`.
 *
 * Infrastructure only; entities are mapped by each module's own mapper.
 *
 * The handle is optional and resolved lazily, because a repository is built by
 * its provider during `register()` — before WordPress has loaded the global — and
 * a constructor that demanded one would make every repository unbuildable at that
 * point. See {@see db()}.
 */
abstract class BaseRepository
{
    /**
     * SQLSTATE MySQL reports for an integrity violation.
     *
     * Covers foreign keys as well as unique keys. Narrowing to the unique index
     * would mean parsing MySQL's message text, which is localised.
     */
    private const SQLSTATE_INTEGRITY_VIOLATION = '23000';

    /**
     * @param wpdb|null $wpdb Defaults to the `$wpdb` global, resolved lazily; see
     *                        {@see db()}. Optional so the container can build a
     *                        repository during `register()`.
     */
    public function __construct(
        protected ?wpdb $wpdb = null,
    ) {}

    /**
     * Runs a write and translates its failures.
     *
     * The callback signals failure by throwing, and the message it throws is the
     * driver's own text — this method adds the action that names the operation,
     * so a caller prefixing its own message would produce "Failed to X: Failed
     * to X: ...". The action arrives as an argument for that reason.
     *
     * A unique-index violation is rethrown as the module's conflict exception,
     * resolved through {@see duplicateException()}. Every other failure keeps
     * its own type, so a caller cannot mistake an outage for a duplicate.
     *
     * @template T
     *
     * @param string $action Phrased for the failure message.
     * @param callable(): T $operation
     *
     * @return T
     *
     * @throws RuntimeException When the write fails.
     */
    protected function executeQuery(string $action, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            $this->throwIfDuplicate($exception, $action);

            throw new RuntimeException(
                sprintf('Failed to %s: %s', $action, $exception->getMessage()),
                0,
                $exception
            );
        }
    }

    /**
     * The module's own conflict exception for a rejected unique index.
     *
     * Modules override this to name their exception, which keeps this shared
     * base from importing a module it must not know about. The default returns
     * the failure unchanged, so a module with no such exception still reports it.
     *
     * @param Throwable $exception
     *
     * @return Throwable
     */
    protected function duplicateException(Throwable $exception): Throwable
    {
        return $exception;
    }

    /**
     * Inserts a row and returns its new id.
     *
     * @param array<string, mixed> $values
     * @param string $table The table name, without the WordPress prefix.
     * @param string $action Phrased for the failure message.
     *
     * @return int
     *
     * @throws RuntimeException When the insert fails.
     */
    protected function insert(array $values, string $table, string $action = 'insert'): int
    {
        return $this->executeQuery($action, function () use ($values, $table): int {
            $db = $this->db();

            $ok = $db->insert($this->tableName($table), $values, $this->formatsFor($values));

            if ($ok === false) {
                throw new RuntimeException($this->lastErrorOrFallback());
            }

            $id = (int) $db->insert_id;

            // An id of zero means the row may well have been inserted, but a
            // caller given it would write to `WHERE id = 0` on its next call and
            // update nothing — so this is a failure, not a value to pass on.
            if ($id <= 0) {
                throw new RuntimeException(
                    'the row was written but the database reported no id, so the caller cannot '
                    . 'address the row it just created'
                );
            }

            return $id;
        });
    }

    /**
     * Updates one row and confirms the row was there.
     *
     * `$wpdb->update()` returns rows *changed* rather than rows matched, so a
     * write of the values already stored returns 0 without anything being wrong.
     * The row is confirmed with a separate existence check.
     *
     * **Not `$wpdb->update()`.** WordPress skips any column whose value is null,
     * because it builds its `SET` body from the format list and has no format
     * that means "the SQL keyword NULL". A cleared nullable field — a company
     * whose website was blanked — would therefore be silently left at its old
     * value while the caller was told the write succeeded. The statement is built
     * here instead, so a null compiles to `NULL` and an empty string stays a
     * string. This mirrors the Fluent builder's own `compileAssignment()`.
     *
     * @param int $id
     * @param array<string, mixed> $values
     * @param string $table The table name, without the WordPress prefix.
     * @param string $action Phrased for the failure message.
     *
     * @return void
     *
     * @throws RuntimeException When the row does not exist or the write fails.
     */
    protected function write(int $id, array $values, string $table, string $action): void
    {
        $changed = $this->executeQuery($action, function () use ($id, $values, $table): int {
            $db = $this->db();

            $placeholders = [];
            $args = [];

            foreach ($values as $column => $value) {
                $quoted = $this->quoteColumn((string) $column);

                if ($value === null) {
                    $placeholders[] = sprintf('%s = NULL', $quoted);

                    continue;
                }

                $placeholders[] = sprintf('%s = %s', $quoted, $this->placeholderFor($value));
                $args[] = $value;
            }

            // The id is appended last, matching the order its placeholder is
            // written in. It cannot come from `$values`: the WHERE names the row
            // rather than setting it.
            $args[] = $id;

            $affected = $db->query($db->prepare(
                sprintf(
                    'UPDATE `%s` SET %s WHERE `id` = %%d',
                    $this->escapeIdentifier($this->tableName($table)),
                    implode(', ', $placeholders)
                ),
                ...$args
            ));

            if ($affected === false) {
                throw new RuntimeException($this->lastErrorOrFallback());
            }

            return (int) $affected;
        });

        if ($changed === 0 && ! $this->rowExists($id, $table)) {
            throw new RuntimeException(sprintf('Failed to %s: row with ID %d does not exist', $action, $id));
        }
    }

    /**
     * Whether a row with the given id exists.
     *
     * @param int $id
     * @param string $table The table name, without the WordPress prefix.
     *
     * @return bool
     */
    protected function rowExists(int $id, string $table): bool
    {
        $db = $this->db();

        $count = $db->get_var($db->prepare(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE `id` = %%d', $this->escapeIdentifier($this->tableName($table))),
            $id
        ));

        // A null here is a failed statement, not a row count: `get_var()` returns
        // null both when nothing matched and when the query broke, and only
        // `last_error` tells them apart. Reporting false would answer "the row is
        // gone" for a database that is simply unreachable.
        if ($count === null && $db->last_error !== '') {
            throw new RuntimeException(sprintf(
                'Failed to check whether row %d in %s still exists: %s',
                $id,
                $table,
                $db->last_error
            ));
        }

        return (int) $count > 0;
    }

    /**
     * The current time as the UTC string the datetime columns store.
     *
     * Not `current_time()`: that is a WordPress function reading the site's
     * timezone, while every timestamp these modules write is UTC.
     *
     * @return string
     */
    protected function now(): string
    {
        return (new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * The fully-qualified table name.
     *
     * @param string $table The table name, without the WordPress prefix.
     *
     * @return string
     */
    protected function tableName(string $table): string
    {
        return $this->db()->prefix . $table;
    }

    /**
     * `$wpdb` format specifiers matching a column map, in the same order.
     *
     * Built from the values rather than declared alongside the columns, because
     * the two must stay index-aligned: `$wpdb->insert()` pairs the nth format with
     * the nth value, so a format list that drifted by one would bind an integer as
     * a string — silently, since MySQL coerces it — and the bug would surface only
     * as an index that stopped being used.
     *
     * @param array<string, mixed> $columns
     *
     * @return list<string>
     */
    protected function formatsFor(array $columns): array
    {
        return array_map(
            static fn (mixed $value): string => match (true) {
                $value === null => '%s',
                is_int($value) => '%d',
                is_float($value) => '%f',
                default => '%s',
            },
            array_values($columns)
        );
    }

    /**
     * Wrap a table name in backticks, escaping any backtick inside it.
     *
     * @param string $identifier
     *
     * @return string
     */
    protected function escapeIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }

    /**
     * Quote one column reference for a `SET` or `WHERE` clause.
     *
     * A bare name is quoted whole. A qualified `table.column` is quoted in two
     * parts, because quoting the whole reference as one identifier would produce
     * `` `table.column` ``, which MySQL reads as a column literally named that.
     *
     * @param string $column
     *
     * @return string
     */
    protected function quoteColumn(string $column): string
    {
        $dot = strpos($column, '.');

        if ($dot === false) {
            return '`' . $this->escapeIdentifier($column) . '`';
        }

        return '`' . $this->escapeIdentifier(substr($column, 0, $dot)) . '`'
            . '.'
            . '`' . $this->escapeIdentifier(substr($column, $dot + 1)) . '`';
    }

    /**
     * The `$wpdb->prepare()` placeholder for a value.
     *
     * `%d` for integers and booleans, `%f` for floats, `%s` for everything else.
     * Choosing by type rather than always using `%s` matters: `%s` quotes, so a
     * column declared `INT` compared against a quoted number is a string
     * comparison, and MySQL silently coerces while an indexed scan is lost — the
     * query is correct and the plan is not.
     *
     * A null never reaches here; {@see write()} writes it as the `NULL` keyword.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function placeholderFor(mixed $value): string
    {
        return match (true) {
            is_int($value) => '%d',
            is_float($value) => '%f',
            is_bool($value) => '%d',
            default => '%s',
        };
    }

    /**
     * Raise the module's conflict exception when the failure was a duplicate key.
     *
     * WordPress surfaces the SQLSTATE inside `last_error` as `... for query ...
     * Duplicate entry 'x' for key 'name'`, so the check is a substring test
     * rather than a structured field — `$wpdb` exposes none. The two phrases cover
     * MySQL's wording across the versions WordPress supports.
     *
     * @param Throwable $exception
     * @param string $action
     *
     * @return void
     *
     * @throws Throwable The module's conflict exception, when this was a duplicate.
     */
    protected function throwIfDuplicate(Throwable $exception, string $action): void
    {
        $error = $this->lastError();

        if ($error === '') {
            return;
        }

        $isIntegrityViolation = str_contains($error, self::SQLSTATE_INTEGRITY_VIOLATION)
            || stripos($error, 'Duplicate entry') !== false;

        if (! $isIntegrityViolation) {
            return;
        }

        $duplicate = $this->duplicateException($exception);

        // Nothing to translate means the module has no conflict exception of its
        // own, and rethrowing the original would report the duplicate as a generic
        // failure. The base raises a `RuntimeException` naming the constraint so
        // the failure still says what happened.
        if ($duplicate === $exception) {
            throw new RuntimeException(
                sprintf('Failed to %s: the database rejected the row as a duplicate.', $action),
                0,
                $exception
            );
        }

        throw $duplicate;
    }

    /**
     * The driver's last error message, or a phrase naming its absence.
     *
     * `last_error` is often empty — a statement can fail without the driver
     * populating it — so the absence is named rather than rendered as a bare
     * empty string.
     *
     * @return string
     */
    private function lastErrorOrFallback(): string
    {
        $error = $this->lastError();

        return $error !== '' ? $error : 'the database reported no error message';
    }

    /**
     * The driver's last error message, or an empty string.
     *
     * Read from the handle rather than from the exception, because `$wpdb`
     * reports a failed statement by returning false and setting `last_error`, and
     * raises nothing.
     *
     * @return string
     */
    private function lastError(): string
    {
        return isset($this->wpdb) && is_string($this->wpdb->last_error) ? $this->wpdb->last_error : '';
    }

    /**
     * The `$wpdb` handle, resolved on first use.
     *
     * Lazy rather than constructor-injected, because WordPress owns this object: a
     * constructor type-hint on `wpdb` would resolve through the container's
     * auto-wiring to a freshly constructed, unconnected instance rather than the
     * site's global one. Resolving here also means a repository built during
     * `register()` is safe, which is when every provider builds one.
     *
     * @return wpdb
     *
     * @throws RuntimeException When there is no handle to resolve.
     */
    protected function db(): wpdb
    {
        if (isset($this->wpdb) && $this->wpdb instanceof wpdb) {
            return $this->wpdb;
        }

        if (! isset($GLOBALS['wpdb']) || ! $GLOBALS['wpdb'] instanceof wpdb) {
            throw new RuntimeException(
                'No $wpdb instance is available. A repository cannot run outside WordPress, and it '
                . 'cannot run during a hook that fires before the database is loaded.'
            );
        }

        return $this->wpdb = $GLOBALS['wpdb'];
    }
}
