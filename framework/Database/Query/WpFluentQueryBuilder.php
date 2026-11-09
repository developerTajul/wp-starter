<?php
declare(strict_types=1);

namespace Framework\Database\Query;

use Framework\Database\Query\Exceptions\QueryException;
use Framework\Database\Query\Exceptions\UniqueConstraintException;
use wpdb;

/**
 * The immutable fluent query builder, over `$wpdb`.
 *
 * Every method that changes the query returns a **clone** and leaves `$this` as
 * it was, as {@see QueryBuilderInterface} promises. The mutable state is three
 * arrays and a few scalars, never an object with behaviour, so a shallow clone is
 * a full copy: the clause arrays are value types and cannot share a mutation with
 * their origin. The one object held is `?wpdb`, shared on purpose (cloning a
 * connection per `where()` would be absurd) and never mutated. Nothing is cached
 * on `$this`; a memo would be a mutation a clone would inherit stale.
 *
 * Each clause stores its SQL fragment and its argument separately
 * (`` [`status` = %s, ['new']] ``) and nothing calls `prepare()` until
 * {@see prepare()} is reached at execution. See {@see QueryBuilderInterface} for
 * why preparing eagerly corrupts values. The fragments a caller can influence are
 * column names and direction words, and neither can be bound (`prepare()` quotes
 * *values*, and a quoted column name is a syntax error), so identifiers are
 * validated here instead; see {@see column()} and {@see direction()}.
 *
 * Single-row writes go through `$wpdb->insert()`/`update()`/`delete()`, which do
 * the column and value escaping; reimplementing that would be a second escaper to
 * keep correct. Reads are compiled, where a builder earns its keep by composing a
 * `WHERE`, `ORDER BY` and `LIMIT` the caller did not have to assemble.
 * `$wpdb->delete()` takes a where-array of exact values rather than a fragment,
 * which suits this interface: {@see delete()} refuses anything but equality.
 */
final class WpFluentQueryBuilder implements QueryBuilderInterface
{
    /**
     * The comparison operators a caller may pass to {@see where()} and
     * {@see join()}.
     *
     * A closed list, not a passthrough: an operator is SQL structure, and a caller
     * who could pass any string could close the clause and open another, which is
     * the injection this class exists to prevent.
     *
     * Held uppercase, and the caller's value is uppercased before the lookup, so
     * `like` and `LIKE` are one operator rather than two entries a caller has to
     * guess between. `LIKE` carries no `%` of its own - the wildcards are the
     * caller's, see {@see QueryBuilderInterface::where()}.
     */
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];

    /** The orderings a caller may pass to {@see orderBy()}. */
    private const DIRECTIONS = ['asc' => 'ASC', 'desc' => 'DESC'];

    /**
     * The joins a caller may pass to {@see join()} and {@see leftJoin()}.
     *
     * Mapped from the method name to the SQL keyword, so the keyword that enters
     * the statement comes from this constant and never from a caller's word.
     */
    private const JOIN_TYPES = ['join' => 'INNER JOIN', 'leftJoin' => 'LEFT JOIN'];

    /**
     * `$wpdb`'s "return an associative array" flag.
     *
     * Named so the intent reads at the call site rather than `ARRAY_A` appearing
     * bare in the middle of a read.
     */
    private const OUTPUT_ASSOC = ARRAY_A;

    /** The bare table name, with no prefix, or null before {@see table()}. */
    private ?string $table = null;

    /**
     * The columns to select, empty meaning `*`.
     *
     * @var list<string>
     */
    private array $columns = [];

    /**
     * The conditions, in the order they were added, each with its connective.
     *
     * Each entry carries its SQL fragment and its argument separately, so a clause
     * is compiled and bound without ever being quoted; see {@see compileWheres()}.
     *
     * `boolean` is `AND` or `OR`, and the first entry's is ignored: there is
     * nothing for it to join to. It is stored rather than inferred from position
     * because `orWhere()` and `where()` differ in nothing else, and reading the
     * connective off the entry is what lets the compiler stay a loop.
     *
     * A group is a fourth shape - `fragment` already parenthesised, `args` holding
     * its bindings in order - so the compiler treats it as one term and never has
     * to recurse into a nested list.
     *
     * @var list<array{boolean: string, fragment: string, args: list<mixed>}>
     */
    private array $wheres = [];

    /**
     * The joins, in the order they were added.
     *
     * Emitted between `FROM` and `WHERE`, which is where MySQL's grammar puts
     * them. Each keeps its keyword, its prefixed table and its two validated
     * column sides, so the fragment is assembled at compile time from parts that
     * were each checked when they were given.
     *
     * @var list<array{type: string, table: string, first: string, operator: string, second: string}>
     */
    private array $joins = [];

    /**
     * The sort clauses, in the order they were added.
     *
     * Held as `[column, 'ASC'|'DESC']` pairs rather than assembled fragments: the
     * direction comes from a map at build time, and a validated pair cannot carry
     * a stray clause the way a pre-joined string could.
     *
     * @var list<array{column: string, direction: string}>
     */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /**
     * Whether the `SELECT` ends with `FOR UPDATE`.
     *
     * A scalar rather than an array entry because it is a flag, not a clause: no
     * column, no bound value, and setting it more than once has one effect.
     */
    private bool $lockForUpdate = false;

    /**
     * How many frames are currently open.
     *
     * Instance state, deliberately not static: two builders are two independent
     * units of work, and a static counter would let one builder's nesting extend
     * another's.
     */
    private int $transactionDepth = 0;

    /**
     * @param wpdb|null $wpdb The database handle. Defaults to the `$wpdb` global,
     *                        resolved lazily on first use so a builder can be
     *                        constructed while WordPress is still loading.
     */
    public function __construct(private ?wpdb $wpdb = null)
    {
    }

    // ------------------------------------------------------------------ building

    /**
     * {@inheritDoc}
     *
     * The name is not validated here. It is a `string` a repository wrote, not a
     * client's input, and refusing it at build time would report the mistake
     * against a query that has not run - `table()` is called before anything knows
     * whether the statement will be a read or a write. Escaping happens at build
     * time in {@see escapeIdentifier()}, where the name is about to enter SQL.
     */
    public function table(string $table): static
    {
        $clone = clone $this;
        $clone->table = trim($table);

        return $clone;
    }

    public function select(string ...$columns): static
    {
        $clone = clone $this;
        $clone->columns = [];

        foreach ($columns as $column) {
            $clone->columns[] = $this->column($column);
        }

        return $clone;
    }

    public function where(string $column, string|int|float|bool|null $value, string $operator = '='): static
    {
        $normalized = $this->operator($operator);

        // A comparison against null is refused rather than compiled.
        //
        // `= NULL` is never true in SQL, not even false: it is unknown. The
        // method that means this is whereNull(), and honouring the call here
        // would mean silently compiling `IS NULL` for a caller who wrote `where`
        // - or, worse, emitting `= NULL` and returning no rows with no error to
        // explain why.
        if ($value === null) {
            throw QueryException::couldNotBuild(sprintf(
                'where() was given null for [%s]. Comparing a column to null does not mean what it '
                . 'looks like: `= NULL` is unknown in SQL and matches nothing. Use whereNull() or '
                . 'whereNotNull(), which compile to `IS NULL` / `IS NOT NULL` and say what was meant.',
                $column
            ));
        }

        return $this->addWhere('AND', [
            'column' => $this->column($column),
            'operator' => $normalized,
            // Stored raw: a placeholder at build time, a bound argument at
            // execution, never quoted here.
            'value' => $value,
        ]);
    }

    public function orWhere(string $column, string|int|float|bool|null $value, string $operator = '='): static
    {
        $normalized = $this->operator($operator);

        if ($value === null) {
            throw QueryException::couldNotBuild(sprintf(
                'orWhere() was given null for [%s]. Comparing a column to null does not mean what it '
                . 'looks like: `= NULL` is unknown in SQL and matches nothing. Use whereNull() or '
                . 'whereNotNull(), which compile to `IS NULL` / `IS NOT NULL` and say what was meant.',
                $column
            ));
        }

        return $this->addWhere('OR', [
            'column' => $this->column($column),
            'operator' => $normalized,
            'value' => $value,
        ]);
    }

    /**
     * {@inheritDoc}
     *
     * The callback is handed a bare builder - no table, no projection, no join -
     * because the group is a predicate, not a query. Handing it `clone $this`
     * would carry the outer conditions into the brackets and produce
     * `a AND (a AND b)`, which is not wrong so much as evidence the grouping was
     * not understood.
     *
     * The compiled fragment is stored with its bindings already collected, so
     * {@see compileWheres()} treats the group as one opaque term and no recursion
     * is needed to emit it.
     */
    public function whereGroup(callable $callback): static
    {
        $group = $callback(new self($this->wpdb));

        [$fragment, $args] = $group->compileWheres();

        if ($fragment === '') {
            throw QueryException::couldNotBuild(
                'whereGroup() was given a callback that added no conditions, which would compile to '
                . 'an empty pair of brackets. A group exists to hold an OR that cannot leak into the '
                . 'conditions around it; with nothing inside, there is nothing to group.'
            );
        }

        return $this->addWhere('AND', ['fragment' => '(' . $fragment . ')', 'args' => $args]);
    }

    public function whereIn(string $column, array $values): static
    {
        if ($values === []) {
            throw QueryException::couldNotBuild(sprintf(
                'whereIn() was called for [%s] with an empty list. `IN ()` is a syntax error, and an '
                . 'empty list here is almost always a filter that matched nothing rather than an '
                . 'intent to match nothing.',
                $column
            ));
        }

        return $this->addWhere('AND', $this->inFragment($column, $values, negated: false));
    }

    /**
     * {@inheritDoc}
     *
     * An empty list drops the clause entirely rather than compiling it, which is
     * why this cannot share {@see whereIn()}'s body: the two disagree about what
     * an empty list means, and the disagreement is the point.
     */
    public function whereNotIn(string $column, array $values): static
    {
        if ($values === []) {
            return $this;
        }

        return $this->addWhere('AND', $this->inFragment($column, $values, negated: true));
    }

    public function whereNull(string $column): static
    {
        return $this->addWhere('AND', [
            'fragment' => $this->quoteColumn($this->column($column)) . ' IS NULL',
            'args' => [],
        ]);
    }

    public function whereNotNull(string $column): static
    {
        return $this->addWhere('AND', [
            'fragment' => $this->quoteColumn($this->column($column)) . ' IS NOT NULL',
            'args' => [],
        ]);
    }

    /**
     * {@inheritDoc}
     *
     * The join is stored, not compiled: the prefixes and the `AS` alias a caller
     * wants are all known here, but the statement the join belongs to is not -
     * the same builder may be read from or counted, and both need the join.
     */
    public function join(string $table, string $first, string $operator, string $second): static
    {
        return $this->addJoin('join', $table, $first, $operator, $second);
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->addJoin('leftJoin', $table, $first, $operator, $second);
    }

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $clone = clone $this;
        $clone->orders[] = [
            'column' => $this->column($column),
            'direction' => $this->direction($direction),
        ];

        return $clone;
    }

    /**
     * {@inheritDoc}
     *
     * Goes through {@see orderBy()} rather than appending a `'DESC'` literal, so
     * the direction still comes from the {@see DIRECTIONS} map and one place
     * decides what a direction word is.
     */
    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function lockForUpdate(): static
    {
        $clone = clone $this;
        $clone->lockForUpdate = true;

        return $clone;
    }

    public function limit(int $limit): static
    {
        if ($limit < 1) {
            throw QueryException::couldNotBuild(sprintf(
                'A limit must be at least 1; [%d] was given. A non-positive limit is a computed '
                . 'number that came out wrong, and the two backends disagree about what it means, '
                . 'MySQL rejects it and SQLite reads it as zero.',
                $limit
            ));
        }

        $clone = clone $this;
        $clone->limit = $limit;

        return $clone;
    }

    public function offset(int $offset): static
    {
        if ($offset < 0) {
            throw QueryException::couldNotBuild(sprintf(
                'An offset cannot be negative; [%d] was given. A negative offset is rejected by '
                . 'MySQL and silently read as zero by SQLite, so the same request would return '
                . 'two different pages depending on the database.',
                $offset
            ));
        }

        $clone = clone $this;
        $clone->offset = $offset;

        return $clone;
    }

    // ------------------------------------------------------------------ reading

    public function get(): array
    {
        [$sql, $args] = $this->compileSelect();

        $rows = $this->db()->get_results($this->prepare($sql, $args), self::OUTPUT_ASSOC);

        if (!is_array($rows)) {
            throw QueryException::statementFailed('read from ' . $this->describedTable(), $this->lastError());
        }

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    public function first(): ?array
    {
        // A limit of 1 is applied to a *clone*, so asking a builder for its first
        // row does not cap every later `get()` on the same instance; that mutation
        // would be invisible, the builder still looking unlimited. `limit()` is
        // used rather than an override so the caller's own limit cannot fight it:
        // `LIMIT 1` is what "first" means.
        $rows = (clone $this)->limit(1)->get();

        return $rows === [] ? null : $rows[0];
    }

    public function count(): int
    {
        // Projection and ordering are dropped from a clone: neither can change a
        // count, and `ORDER BY` on a `COUNT(*)` is at best wasted work and at
        // worst rejected by a backend with strict grouping.
        $counting = clone $this;
        $counting->columns = [];
        $counting->orders = [];

        [$sql, $args] = $counting->compileSelect(aggregate: 'COUNT(*)');

        $total = $this->db()->get_var($this->prepare($sql, $args));

        if ($total === null) {
            // get_var() returns null both for "no rows" and for a failed
            // statement. A COUNT always returns exactly one row, so a null here is
            // never an absence; last_error distinguishes the two and is read
            // rather than assumed.
            if ($this->lastError() !== '') {
                throw QueryException::statementFailed('count rows in ' . $this->describedTable(), $this->lastError());
            }

            // No error and still null: the statement returned no aggregate row,
            // which a real database does not do. Reported rather than returned as
            // 0, because 0 is a claim about the data and this is a claim about the
            // query having failed.
            throw QueryException::couldNotBuild(sprintf(
                'Counting rows in [%s] returned no value and reported no error. A COUNT always '
                . 'returns one row, so this is a fault in the statement rather than an empty table.',
                $this->describedTable()
            ));
        }

        return (int) $total;
    }

    // ------------------------------------------------------------------ writing

    public function insertGetId(array $values): int
    {
        $table = $this->requireTable('insert into');

        if ($values === []) {
            throw QueryException::couldNotBuild(sprintf(
                'insertGetId() was called for [%s] with no columns. An INSERT with no columns has '
                . 'no statement to compile; the caller has almost certainly built the value array '
                . 'from a filter that removed everything.',
                $table
            ));
        }

        $db = $this->db();

        // Values are normalized before they are handed over, and the format list
        // is derived from the *normalized* array so the two cannot drift apart.
        //
        // Why the cast is needed here rather than left to `$wpdb`: `insert()`
        // builds `INSERT ... VALUES (%d)` and runs it through `prepare()`, which
        // escapes every argument with `mysqli_real_escape_string()` **before**
        // `vsprintf()` sees it. A `false` reaches that as the empty string, so the
        // `%d` is formatting `''` rather than `0`. It happens to yield `0` today,
        // but only through a lossy round-trip that also turns a non-scalar into
        // `''` and raises `_doing_it_wrong()`. Casting first makes the value that
        // gets escaped the value that was meant.
        $safeValues = array_map($this->boundValue(...), $values);

        $ok = $db->insert(
            $this->prefix() . $table,
            $safeValues,
            array_map([$this, 'formatFor'], array_values($safeValues))
        );

        if ($ok === false) {
            throw $this->writeFailure('insert into ' . $this->describedTable(), $table);
        }

        $id = (int) $db->insert_id;

        if ($id <= 0) {
            // The row exists but its id was not reported. Zero would give the
            // caller an entity whose `exists()` is false, so its next save would
            // insert a second copy instead of updating this one.
            throw QueryException::couldNotBuild(sprintf(
                'The row was inserted into [%s] but no id was reported. Returning zero would give '
                . 'the caller a record it believes is unsaved, so its next save would insert a '
                . 'duplicate rather than update this row.',
                $table
            ));
        }

        return $id;
    }

    public function update(array $values): int
    {
        $table = $this->requireTable('update');

        if ($values === []) {
            throw QueryException::couldNotBuild(sprintf(
                'update() was called for [%s] with no columns to change.',
                $table
            ));
        }

        [$where, $args] = $this->compileWheres();

        // No condition is refused rather than applied to every row: an UPDATE with
        // no WHERE is legal SQL, almost never intended, and overwrites the whole
        // table.
        if ($where === '') {
            throw QueryException::couldNotBuild(sprintf(
                'update() was called for [%s] with no conditions. Refusing rather than overwriting '
                . 'every row, an unqualified UPDATE is almost always a forgotten where().',
                $table
            ));
        }

        // The predicate uses the same placeholder form as the reads and is
        // prepared once, so a value cannot escape into the statement.
        [$assignments, $assignmentArgs] = $this->compileAssignment($values);

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $this->escapeIdentifier($this->prefix() . $table),
            $assignments,
            $where
        );

        // The assignment arguments are concatenated with the predicate's, in that
        // order: placeholders are filled positionally, and the `SET` body precedes
        // the `WHERE`. They cannot be taken from `$values` directly - a null
        // assignment binds nothing, so the two lists are not the same length.
        $affected = $this->db()->query($this->prepare($sql, array_merge($assignmentArgs, $args)));

        if ($affected === false) {
            throw $this->writeFailure('update ' . $this->describedTable(), $table);
        }

        return (int) $affected;
    }

    public function delete(): int
    {
        $table = $this->requireTable('delete from');

        [$where, $args] = $this->compileWheres();

        // Refused for the same reason as an unqualified UPDATE, and more
        // strongly: `DELETE FROM t` empties the table, and the consequence of the
        // forgotten `where()` is unbounded rather than merely wrong.
        if ($where === '') {
            throw QueryException::couldNotBuild(sprintf(
                'delete() was called for [%s] with no conditions. Refusing rather than emptying '
                . 'the table, an unqualified DELETE is almost always a forgotten where().',
                $table
            ));
        }

        $sql = sprintf(
            'DELETE FROM `%s` WHERE %s',
            $this->escapeIdentifier($this->prefix() . $table),
            $where
        );

        $affected = $this->db()->query($this->prepare($sql, $args));

        if ($affected === false) {
            throw $this->writeFailure('delete from ' . $this->describedTable(), $table);
        }

        return (int) $affected;
    }

    // ------------------------------------------------------------------ transactions

    public function transaction(callable $callback): mixed
    {
        $db = $this->db();

        // An inner call joins. The callback runs in the caller's frame and no SQL
        // is issued: a second `START TRANSACTION` here would *implicitly commit*
        // the open one, which is worse than an error because nothing reports it.
        if ($this->transactionDepth > 0) {
            return $callback();
        }

        $this->beginTransaction();

        try {
            $result = $callback();
        } catch (\Throwable $exception) {
            // Depth is reset before the rollback so a `ROLLBACK` that itself fails
            // and throws out of here does not leave the builder believing a
            // transaction is still open.
            $this->transactionDepth = 0;

            $db->query('ROLLBACK');

            // Re-thrown, never swallowed: a transaction that reported a failed
            // unit of work as a success is the one outcome that must not happen.
            throw $exception;
        }

        // Not reset here. `commit()` owns that transition and returns early on a
        // depth of zero, so clearing the counter first would make the COMMIT a
        // silent no-op and leave the unit of work open until the connection
        // ended, the exact failure this method exists to prevent.
        $this->commit();

        return $result;
    }

    public function beginTransaction(): void
    {
        // The depth is incremented on every call, so a nested frame is counted
        // and joins the open transaction. Only the transition from 0 to 1 issues
        // `START TRANSACTION`: a second one would *implicitly commit* the open
        // transaction, which is the half-written state this class exists to
        // prevent. Counting rather than refusing nested begins is what makes the
        // counter agree with `commit()` and `rollback()`, which decrement it -
        // a begin that did not count would let the first `commit()` close a
        // transaction that is still two frames deep.
        $this->transactionDepth++;

        if ($this->transactionDepth === 1) {
            $this->db()->query('START TRANSACTION');
        }
    }

    public function commit(): void
    {
        // Nothing open: a `COMMIT` here would be an error on the server, and
        // raising it would replace the caller's failure with an unrelated one.
        if ($this->transactionDepth === 0) {
            return;
        }

        // One frame closes at a time. An inner commit only decrements - the
        // outermost frame owns the real `COMMIT`, because a commit issued from
        // inside would end a unit of work the outer frame still holds.
        $this->transactionDepth--;

        if ($this->transactionDepth === 0) {
            $this->db()->query('COMMIT');
        }
    }

    public function rollback(): void
    {
        // A bare `ROLLBACK` with no transaction is an error on the server, and
        // raising it here would replace the caller's failure with an unrelated
        // one while they are handling it.
        if ($this->transactionDepth === 0) {
            return;
        }

        // Rollback abandons the *whole* unit of work, not one frame: MySQL has no
        // savepoints here, so there is no inner scope to unwind to. The depth is
        // reset to zero *before* the rollback, for the same reason
        // `transaction()` does it - a `ROLLBACK` that fails throws from here, and
        // leaving the depth raised would make the next call join a frame that no
        // longer exists.
        $this->transactionDepth = 0;

        $this->db()->query('ROLLBACK');
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    // ------------------------------------------------------------------ compiling

    /**
     * Assemble a `SELECT` and its bound arguments.
     *
     * @param string|null $aggregate An aggregate to project instead of the
     *                              column list, e.g. `COUNT(*)`. Used by
     *                              {@see count()}, which needs the same `WHERE`
     *                              and join behaviour as a read.
     *
     * @return array{0: string, 1: list<mixed>} The statement, and its arguments
     *                                          in placeholder order.
     */
    private function compileSelect(?string $aggregate = null): array
    {
        $table = $this->requireTable('read from');

        $projection = $aggregate ?? ($this->columns === []
            ? '*'
            : implode(', ', array_map($this->quoteProjection(...), $this->columns)));

        $sql = sprintf('SELECT %s FROM `%s`', $projection, $this->escapeIdentifier($this->prefix() . $table));

        // Joins sit between FROM and WHERE, which is the only position MySQL's
        // grammar accepts them in - and the reason this is emitted here rather
        // than after the projection is built.
        $joins = $this->compileJoins();

        if ($joins !== '') {
            $sql .= ' ' . $joins;
        }

        [$where, $args] = $this->compileWheres();

        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        // ORDER BY and LIMIT are skipped entirely for an aggregate: neither can
        // change a count.
        if ($aggregate === null) {
            if ($this->orders !== []) {
                $sql .= ' ORDER BY ' . implode(', ', array_map(
                    fn (array $order): string => sprintf(
                        '%s %s',
                        $this->quoteColumn($order['column']),
                        $order['direction']
                    ),
                    $this->orders
                ));
            }

            if ($this->limit !== null) {
                // Inlined rather than bound. A `LIMIT` placeholder is supported by
                // MySQL and by `$wpdb->prepare()`, but a limit is an `int` this
                // class validated in `limit()`, so there is no value to escape -
                // and inlining keeps the placeholder list aligned with `$args`.
                $sql .= sprintf(' LIMIT %d', $this->limit);
            }

            if ($this->offset !== null) {
                // OFFSET without LIMIT is invalid in MySQL, so one is supplied.
                // A caller asking to skip rows with no cap is asking for
                // everything after a point.
                if ($this->limit === null) {
                    $sql .= ' LIMIT 18446744073709551615';
                }

                $sql .= sprintf(' OFFSET %d', $this->offset);
            }
        }

        // `FOR UPDATE` is appended last, which is where MySQL's grammar puts it:
        // after ORDER BY and LIMIT. Emitted for aggregates too - a locking read
        // that counts rows is unusual, but dropping the lock there would make
        // `lockForUpdate()` silently not apply, and silence is the failure this
        // method's docblock warns about.
        if ($this->lockForUpdate) {
            $sql .= ' FOR UPDATE';
        }

        return [$sql, $args];
    }

    /**
     * Assemble the `WHERE` body and its arguments.
     *
     * The conditions are joined by the connective each one carries, with the
     * first emitted bare: a leading `AND` is a syntax error, so the loop tracks
     * whether anything has been written yet rather than reading the first entry's
     * `boolean`. That field is still stored on every entry - it is how the entry
     * says what it is - but the first one is never consulted.
     *
     * `OR` is emitted exactly as given, with no added brackets. Grouping is the
     * caller's decision, expressed by {@see whereGroup()}, and inserting brackets
     * the caller did not ask for would change what `a AND b OR c` means.
     *
     * @return array{0: string, 1: list<mixed>} The clause body - empty when there
     *                                          are no conditions - and the
     *                                          arguments it introduced, in
     *                                          placeholder order.
     */
    private function compileWheres(): array
    {
        $sql = '';
        $args = [];

        foreach ($this->wheres as $where) {
            // A fragment entry is either a comparison or a group. A comparison
            // compiles here; a group arrives already parenthesised and carries its
            // own bindings, which were collected when the group was built.
            if (isset($where['fragment'])) {
                $fragment = $where['fragment'];
                $bound = $where['args'];
            } else {
                [$fragment, $bound] = $this->compileComparison($where['column'], $where['operator'], $where['value']);
            }

            $sql .= $sql === '' ? $fragment : sprintf(' %s %s', $where['boolean'], $fragment);
            $args = array_merge($args, $bound);
        }

        return [$sql, $args];
    }

    /**
     * Compile one comparison into a fragment and its bound arguments.
     *
     * @param string $column   An already-validated column name.
     * @param string $operator An already-normalized operator.
     * @param mixed  $value    The value to bind. Never null - both callers refuse
     *                         one before reaching here.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileComparison(string $column, string $operator, mixed $value): array
    {
        $quoted = $this->quoteColumn($column);

        // A bool has no `%` placeholder that means it; `%d` is the documented
        // spelling and a bool must be cast, since `prepare()` does not accept one.
        if (is_bool($value)) {
            return [sprintf('%s %s %%d', $quoted, $operator), [$this->boundValue($value)]];
        }

        return [
            sprintf('%s %s %s', $quoted, $operator, $this->placeholderFor($value)),
            [$this->boundValue($value)],
        ];
    }

    /**
     * Compile an `IN`/`NOT IN` term into a fragment and its bound arguments.
     *
     * The placeholder is chosen per value rather than one `%s` for the whole list,
     * because `%s` quotes: an `INT` column compared against quoted numbers is a
     * string comparison, and MySQL silently coerces while the index scan is lost.
     *
     * @param string                    $column  Bare column name.
     * @param list<string|int|float|bool> $values The values to bind.
     * @param bool                      $negated True for `NOT IN`.
     *
     * @return array{fragment: string, args: list<mixed>}
     */
    private function inFragment(string $column, array $values, bool $negated): array
    {
        $placeholders = [];
        $args = [];

        foreach ($values as $value) {
            // A null inside the list is refused rather than bound. SQL's `NOT IN`
            // is unknown - matching nothing - as soon as one element is null, so
            // a null would silently invert the clause's meaning, and `IN` has the
            // same silent behaviour in the other direction.
            if ($value === null) {
                throw QueryException::couldNotBuild(sprintf(
                    'whereIn()/whereNotIn() was given null among the values for [%s]. A null in the '
                    . 'list makes the whole comparison unknown in SQL, so it matches nothing whatever '
                    . 'the other values are. Filter nulls out, or use whereNull()/whereNotNull() to '
                    . 'say what the null case should do.',
                    $column
                ));
            }

            $placeholders[] = $this->placeholderFor($value);
            $args[] = $this->boundValue($value);
        }

        return [
            'fragment' => sprintf(
                '%s %s (%s)',
                $this->quoteColumn($this->column($column)),
                $negated ? 'NOT IN' : 'IN',
                implode(', ', $placeholders)
            ),
            'args' => $args,
        ];
    }

    /**
     * Append a condition to a clone of this builder.
     *
     * The one place a condition enters {@see $wheres}. Both condition shapes go
     * through it, so the immutability guarantee is implemented once rather than
     * once per condition method.
     *
     * @param string $boolean `AND` or `OR`.
     * @param array{fragment: string, args: list<mixed>}|array{column: string, operator: string, value: mixed} $condition
     *
     * @return static
     */
    private function addWhere(string $boolean, array $condition): static
    {
        $clone = clone $this;
        $clone->wheres[] = ['boolean' => $boolean] + $condition;

        return $clone;
    }

    /**
     * Store a join on a clone of this builder.
     *
     * @param string $type     The key into {@see JOIN_TYPES}.
     * @param string $table    Bare table name.
     * @param string $first    The left-hand column.
     * @param string $operator The comparison.
     * @param string $second   The right-hand column.
     *
     * @return static
     */
    private function addJoin(string $type, string $table, string $first, string $operator, string $second): static
    {
        $clone = clone $this;
        $clone->joins[] = [
            'type' => self::JOIN_TYPES[$type],
            'table' => trim($table),
            'first' => $this->column($first),
            'operator' => $this->operator($operator),
            'second' => $this->column($second),
        ];

        return $clone;
    }

    /**
     * Assemble the join clauses.
     *
     * The joined table goes through {@see escapeIdentifier()} and is wrapped in
     * backticks here, where it is about to enter the statement - the same
     * treatment {@see compileSelect()} gives the `FROM` table, and for the same
     * reason: `table()` accepts the name before anything knows whether the query
     * will run.
     */
    private function compileJoins(): string
    {
        $clauses = [];

        foreach ($this->joins as $join) {
            $clauses[] = sprintf(
                '%s `%s` ON %s %s %s',
                $join['type'],
                $this->escapeIdentifier($this->prefix() . $join['table']),
                $this->quoteColumn($join['first']),
                $join['operator'],
                $this->quoteColumn($join['second'])
            );
        }

        return implode(' ', $clauses);
    }

    /**
     * Assemble a `SET` body for an `UPDATE`, with the arguments it binds.
     *
     * The arguments are returned rather than taken from `$values` by the caller,
     * because the two arrays are no longer the same length: a `null` compiles to
     * a literal `NULL` and binds nothing, so pairing the statement with
     * `array_values($values)` would shift every later placeholder by one and bind
     * each value to the wrong column.
     *
     * @param array<string, mixed> $values
     *
     * @return array{0: string, 1: list<mixed>} The `SET` body, and its arguments in
     *                                          placeholder order.
     */
    private function compileAssignment(array $values): array
    {
        $assignments = [];
        $args = [];

        foreach ($values as $column => $value) {
            // The column comes from an array key, so it is attacker-adjacent in a
            // way a literal is not; it is escaped like every other identifier.
            $quoted = $this->quoteColumn($this->column((string) $column));

            // A null is written as the SQL keyword and binds nothing. It has no
            // placeholder that means "null": `%s` would bind the empty string and
            // store that instead, silently turning "clear this column" into
            // "write an empty value".
            if ($value === null) {
                $assignments[] = sprintf('%s = NULL', $quoted);

                continue;
            }

            $assignments[] = sprintf('%s = %s', $quoted, $this->placeholderFor($value));
            $args[] = $this->boundValue($value);
        }

        return [implode(', ', $assignments), $args];
    }

    // ------------------------------------------------------------------ internals

    /**
     * The placeholder `$wpdb->prepare()` should use for a value.
     *
     * `%d` for integers and for booleans, `%f` for floats, `%s` for everything
     * else. Choosing by type rather than always using `%s` matters: `%s` quotes,
     * so a column declared `INT` compared against a quoted number is a
     * string comparison, and MySQL silently coerces while an indexed scan is
     * lost - the query is correct and the plan is not.
     *
     * A `null` never reaches here: {@see where()} refuses one outright, and
     * {@see compileAssignment()} writes it as the `NULL` keyword.
     */
    private function placeholderFor(mixed $value): string
    {
        return match (true) {
            is_int($value) => '%d',
            is_float($value) => '%f',
            is_bool($value) => '%d',
            default => '%s',
        };
    }

    /**
     * A value as `$wpdb->prepare()` should receive it, beside its placeholder.
     *
     * A bool is cast to `1`/`0` here rather than left to `%d`, because
     * `prepare()` runs `false` through a `%d` that reads it as the *empty
     * string*: the column is updated to `''` on MySQL's non-strict path, and on
     * the strict path the statement is rejected. Either way `false` is what the
     * caller meant and `0` is what a boolean column stores, so the cast belongs
     * at the point the argument is chosen - not in {@see placeholderFor()},
     * which only names the format.
     *
     * The counterpart of {@see placeholderFor()}, and the two are kept together
     * so a format and the value beside it cannot drift apart.
     */
    private function boundValue(mixed $value): mixed
    {
        return is_bool($value) ? ($value ? 1 : 0) : $value;
    }

    /**
     * The `$wpdb` format specifier for a value, for the write helpers.
     *
     * The counterpart to {@see placeholderFor()} for the paths that hand values
     * to `$wpdb->insert()` rather than preparing a statement.
     */
    private function formatFor(mixed $value): string
    {
        return match (true) {
            is_int($value) => '%d',
            is_float($value) => '%f',
            is_bool($value) => '%d',
            default => '%s',
        };
    }

    /**
     * Bind a statement's placeholders and set its arguments, exactly once.
     *
     * Called immediately before execution and nowhere else, which is the whole
     * point - see {@see QueryBuilderInterface}. `prepare()` is skipped when
     * there is nothing to bind, because `prepare()` on an argument-free
     * statement in some WordPress versions emits a `_doing_it_wrong()` notice
     * for being called without placeholders.
     *
     * @param list<mixed> $args
     */
    private function prepare(string $sql, array $args): string
    {
        if ($args === []) {
            return $sql;
        }

        // `prepare()` returns null on a malformed statement; it is filtered to
        // a string so the failure reaches `$wpdb` as an empty statement and is
        // reported through `last_error` rather than as a fatal on a `string`
        // type declaration.
        $prepared = $this->db()->prepare($sql, ...$args);

        return $prepared === null || $prepared === '' ? $sql : $prepared;
    }

    /**
     * The bare table name, or a refusal naming the missing call.
     *
     * @param string $operation Phrased so the failure message can use it -
     *                          "read from", "insert into".
     */
    private function requireTable(string $operation): string
    {
        if ($this->table === null || $this->table === '') {
            throw QueryException::couldNotBuild(sprintf(
                'A query cannot %s a table until table() has named one.',
                $operation
            ));
        }

        return $this->table;
    }

    /**
     * The table as a failure message should name it.
     *
     * The bare name, not the prefixed one: the caller wrote `leads`, and a
     * message about `wp_leads` sends them looking for a call that does not
     * exist in their code.
     */
    private function describedTable(): string
    {
        return $this->table === null || $this->table === '' ? 'the table' : $this->table;
    }

    /**
     * Validate a column name.
     *
     * Why a column name is checked at all
     *
     * `prepare()` quotes *values*; a column name is structure and cannot be
     * bound. So an identifier is the one place a caller's string reaches the
     * statement unparameterised, and it is checked here rather than trusted.
     *
     * Most call sites pass a literal, and this is belt-and-braces for those -
     * but a repository building a column list from configuration, or a
     * `SELECT` built from a client's `?fields=`, passes a string this class did
     * not write. A name containing a backtick or a space would otherwise close
     * the identifier and open a clause.
     *
     * Permitted: letters, digits, underscores, and a single dot for a
     * `table.column` reference. The dot is allowed because a qualified name is
     * how a caller disambiguates a joined read - `deals.id` rather than an
     * ambiguous `id` - which is the shape {@see join()} introduces.
     *
     * An `AS` alias is permitted too, because a joined read needs one: two
     * tables in one statement can both have an `id`, and without `AS` the second
     * would overwrite the first in the returned row. The alias is matched by the
     * same grammar as the column, so neither half can carry a stray clause; only
     * the two-word form `… AS …` is accepted, not a bare trailing word, which
     * would make `select('name city')` a silently different projection.
     *
     * @throws QueryException When the name is not a bare identifier, optionally
     *                       qualified and optionally aliased.
     */
    private function column(string $column): string
    {
        $column = trim($column);

        $identifier = '[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?';
        $pattern = '/^' . $identifier . '(?:\s+AS\s+' . $identifier . ')?$/i';

        if (preg_match($pattern, $column) !== 1) {
            throw QueryException::couldNotBuild(sprintf(
                'The column name [%s] is not usable in a query. A column name may only contain '
                . 'letters, digits and underscores, optionally qualified with one dot '
                . '(`table.column`) and optionally aliased with `AS`; a value can be bound as a '
                . 'parameter, but a column name is part of the statement and cannot be.',
                $column
            ));
        }

        return $column;
    }

    /**
     * Split a column reference into its parts, for the contexts that quote each
     * part separately.
     *
     * `select()` emits a list where each identifier and each alias is quoted on
     * its own - `` `users`.`display_name` AS `user_name` `` - which is not the
     * same string as quoting the whole reference, and is what MySQL parses and
     * what a reader expects to see. {@see column()} has already established the
     * shape, so this only splits it.
     *
     * @return array{0: string, 1: string|null} The column reference, and the
     *                                          alias when one was given.
     */
    private static function splitAlias(string $column): array
    {
        $parts = preg_split('/\s+AS\s+/i', $column, 2);

        if ($parts === false || count($parts) === 1) {
            return [$column, null];
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * Quote one column reference, including its table qualifier if any.
     *
     * `table.column` becomes `` `wp_table`.`column` ``. Quoting the whole
     * reference as one identifier would produce `` `table.column` ``, which MySQL
     * reads as a column literally named `table.column` - a different, and usually
     * nonexistent, column.
     *
     * The qualifier is prefixed, and it has to be: {@see table()} and
     * {@see join()} emit the tables they name as `` `wp_deals` ``, so a
     * qualifier that stayed bare would name a table the statement never
     * mentioned. MySQL does not alias a table to its unprefixed name, so
     * `` `deals`.`id` `` after `FROM `wp_deals`` is error 1054, "Unknown column
     * 'deals.id'". Verified against MySQL 5.7: the bare form fails, the prefixed
     * form returns rows.
     *
     * A caller can only ever write a real table name here, which is what makes
     * prefixing unconditionally safe. {@see table()} and {@see join()} take a
     * bare name and apply the prefix themselves; neither accepts an `AS` alias,
     * so there is no alias this could wrongly prefix. A column *alias* is a
     * separate thing and is not touched - see {@see quoteProjection()}, which
     * quotes the alias on its own.
     */
    private function quoteColumn(string $column): string
    {
        $dot = strpos($column, '.');

        if ($dot === false) {
            return self::quoteIdentifier($column);
        }

        return self::quoteIdentifier($this->prefix() . substr($column, 0, $dot))
            . '.'
            . self::quoteIdentifier(substr($column, $dot + 1));
    }

    /**
     * Validate a comparison operator and return the spelling that enters SQL.
     *
     * The caller's word is uppercased and trimmed before the lookup, so `like`,
     * `Like` and `LIKE` are one entry rather than three a caller has to guess
     * between. The value returned is the one from {@see OPERATORS}, not the
     * caller's string: a returned string would be safe *because* it was checked,
     * and this one is safe because it was replaced - the same reasoning
     * {@see direction()} uses.
     *
     * @throws QueryException When the operator is not in the closed list.
     */
    private function operator(string $operator): string
    {
        $normalized = strtoupper(trim($operator));

        if (!in_array($normalized, self::OPERATORS, true)) {
            throw QueryException::couldNotBuild(sprintf(
                'The operator [%s] is not supported by the query builder. Use one of: %s.',
                $operator,
                implode(', ', self::OPERATORS)
            ));
        }

        return $normalized;
    }

    /**
     * Validate a sort direction.
     *
     * Mapped rather than validated-and-returned, so the value that enters the
     * statement comes from the constant and not from the caller at all. A
     * returned caller string would be safe *because* it was checked; a mapped
     * one is safe because it was replaced.
     */
    private function direction(string $direction): string
    {
        $key = strtolower(trim($direction));

        if (!array_key_exists($key, self::DIRECTIONS)) {
            throw QueryException::couldNotBuild(sprintf(
                'The sort direction [%s] is not supported. Use asc or desc.',
                $direction
            ));
        }

        return self::DIRECTIONS[$key];
    }

    /**
     * Quote one projection entry, alias included.
     *
     * An aliased entry becomes `` `users`.`display_name` AS `user_name` ``: the
     * reference is quoted part by part and the alias is quoted as its own
     * identifier, so a joined read can name two same-named columns apart without
     * either name entering the statement unquoted.
     */
    private function quoteProjection(string $column): string
    {
        [$reference, $alias] = self::splitAlias($column);

        $quoted = $this->quoteColumn($reference);

        return $alias === null ? $quoted : $quoted . ' AS ' . self::quoteIdentifier($alias);
    }

    /**
     * Wrap an identifier in backticks, escaping any backtick inside it.
     *
     * The same treatment {@see \Core\Lead\Infrastructure\Repositories\LeadWriteRepository}
     * gives a table name, and for the same reason: no caller should have to reason
     * about whether a particular identifier is safe.
     */
    private static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Make a table name safe to interpolate inside backticks.
     *
     * The prefix is applied before this, and the prefix is WordPress's, so this
     * is belt-and-braces - it exists so that no caller has to check.
     */
    private function escapeIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }

    /**
     * The table prefix, read from the live connection.
     *
     * Taken from `$wpdb->prefix` rather than from configuration: a multisite
     * subsite has its own prefix on the same connection, so a value read from
     * `config()` would address the wrong site's tables on every subsite but the
     * first.
     */
    private function prefix(): string
    {
        return $this->db()->prefix;
    }

    /**
     * `$wpdb`'s last error, cleared first so a stale one cannot be read.
     */
    private function lastError(): string
    {
        return $this->db()->last_error;
    }

    /**
     * The exception a failed write should raise, duplicate key or not.
     *
     * Why the error number and not the message
     *
     * MySQL reports a duplicate unique value as error **1062**, and that number
     * is part of the protocol: it does not change between versions and does not
     * translate. The message beside it does both - a server running a non-English
     * locale words it differently - so a `str_contains($message, 'Duplicate')`
     * test is a rule that works on the developer's machine and quietly stops
     * firing on a client's.
     *
     * How the number is obtained
     *
     * `$wpdb` does not store it. Verified against WordPress core
     * (`wp-includes/class-wpdb.php`): the class declares `public $last_error` and
     * `protected $dbh`, and reads the driver's number only transiently ,
     * `mysqli_errno( $this->dbh )` inside `query()` - without assigning it to a
     * property. There is no `$wpdb->errno`, and `__get()` is a plain
     * `return $this->$name`, so reading one would raise a warning and produce
     * `null` rather than 1062.
     *
     * The handle is therefore reached through `$wpdb->dbh`. It is `protected`,
     * but `__get()` forwards to it, which is WordPress's documented
     * backward-compatibility shim for exactly this kind of access.
     *
     * Every step is guarded, because a connection that is not a live `mysqli` is
     * one of the states this runs in: `$wpdb` itself detects a dropped connection
     * by testing `$this->dbh instanceof mysqli`. When that test fails here the
     * error number is unknowable, and the failure is reported as a plain
     * {@see QueryException} carrying `last_error` - which is the honest outcome,
     * since guessing "not a duplicate" from an absent connection would be right
     * for the wrong reason.
     *
     * @param string $operation Phrased for a failure message - "insert into leads".
     * @param string $table     The bare table name, for the duplicate message.
     */
    private function writeFailure(string $operation, string $table): QueryException
    {
        $db = $this->db();
        $message = $this->lastError();

        if ($this->errnoIs('1062')) {
            return UniqueConstraintException::duplicateEntry(
                $table,
                $this->constraintFromMessage($message),
                $message
            );
        }

        return QueryException::statementFailed($operation, $message);
    }

    /**
     * Whether the driver is reporting the given MySQL error number.
     *
     * Isolated so the `instanceof`/`mysqli_errno` dance - and its guards - exist
     * once rather than at each write path.
     *
     * The `try` is load-bearing rather than defensive habit. `mysqli_errno()`
     * does not merely return a sentinel when it cannot answer: called on an
     * `mysqli` that was never successfully initialised - which is a real state
     * here, since a dropped connection is exactly when this runs - it throws an
     * `Error` ("mysqli object is not fully initialized"). Letting that escape
     * would replace a clean {@see QueryException} with an uncaught `Error`
     * *inside the handling of another failure*, which is the worst moment to
     * raise a second one. An errno that cannot be read is unknown, and unknown is
     * not 1062.
     */
    private function errnoIs(string $expected): bool
    {
        $handle = $this->db()->dbh ?? null;

        if (!is_object($handle) || !$handle instanceof \mysqli) {
            return false;
        }

        try {
            return (string) mysqli_errno($handle) === $expected;
        } catch (\Error) {
            return false;
        }
    }

    /**
     * The index MySQL blamed, pulled from its message, or an empty string.
     *
     * Purely for readability - nothing branches on the result, so a MySQL release
     * that words the message differently costs a less specific error and not a
     * missed detection. The number decided that already.
     *
     * MySQL words it `Duplicate entry 'x' for key 'idx_name'` (or, since 8.0,
     * `for key 'table.idx_name'`), so the quoted name after `for key` is taken
     * and any table qualifier stripped.
     */
    private function constraintFromMessage(string $message): string
    {
        if (preg_match("/for key '([^']+)'/i", $message, $matches) !== 1) {
            return '';
        }

        $name = $matches[1];
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr($name, $dot + 1);
    }

    /**
     * The database handle, resolved on first use.
     *
     * Lazy for the same reason the migrator's and the repositories' is: a
     * provider's `register()` runs before WordPress has necessarily finished
     * setting `$wpdb` up, and a constructor reaching for the global eagerly
     * would fail there.
     */
    private function db(): wpdb
    {
        if ($this->wpdb instanceof wpdb) {
            return $this->wpdb;
        }

        if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof wpdb) {
            throw QueryException::couldNotBuild(
                'No $wpdb instance is available. The query builder needs a WordPress database '
                . 'handle; pass one to the constructor when running outside a loaded WordPress.'
            );
        }

        return $this->wpdb = $GLOBALS['wpdb'];
    }
}
