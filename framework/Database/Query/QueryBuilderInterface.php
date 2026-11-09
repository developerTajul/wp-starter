<?php
declare(strict_types=1);

namespace Framework\Database\Query;

/**
 * A fluent, immutable query builder over a table.
 *
 * What this is for, and where it may be used
 *
 * Repositories are the only callers. A repository is the one class in a module
 * that is allowed to know a table name and a column name, and this is the
 * vocabulary it writes that knowledge in - see
 * {@see \Core\Lead\Infrastructure\Repositories\LeadReadRepository} for the
 * worked example.
 *
 * It must **never** be injected into a service or a domain object. The point of
 * a port such as {@see \Core\Lead\Domain\Contracts\LeadRepositoryContract} is that the
 * business layer talks about leads and ids; a builder in a service constructor
 * would put `where('status', 'new')` one call away from business logic, and the
 * first time somebody wrote it there the storage decision would have leaked
 * inward with no seam left to notice it.
 *
 * Every modifier returns a new builder
 *
 * A builder is a value, not a cursor. `where()` does not change the builder it
 * was called on; it returns a second builder with one more clause. That is what
 * makes this safe to hold:
 *
 *     $base    = $builder->table('leads')->where('source', 'form');
 *     $news    = $base->where('status', 'new');       // $base is untouched
 *     $won     = $base->where('status', 'won');       // and still untouched
 *
 * A mutable builder makes that snippet a bug: `$won` and `$news` would be the
 * same object holding both conditions, and the query for `$news` would be
 * whatever ran last. The failure is order-dependent, which is what makes it
 * expensive - it reproduces on a machine that happens to interleave differently.
 *
 * Bindings are deferred, never baked in
 *
 * A value passed to {@see where()} becomes a `%s`/`%d` placeholder and a
 * separate argument. Nothing is quoted while the query is being built. The
 * placeholder is filled by `$wpdb->prepare()` **once, at execution**.
 *
 * This is not stylistic. `prepare()` escapes for the connection that is open at
 * the moment it runs, and a builder that prepared eagerly would hand a
 * caller a string that has already had quotes doubled inside it - so a later
 * `prepare()` over the same statement escapes the escapes, and the value
 * reaches the database with literal backslashes in it. Deferring also means the
 * caller can keep a half-built query and finish it after the connection is
 * known, which is how a builder works in a test that has no `$wpdb` at all.
 *
 * The read surface is a working vocabulary, not a subset
 *
 * `join()`, `leftJoin()`, `orWhere()`, the `IN` family and the null tests are
 * all here. They are what a repository needs to express its reads without
 * dropping to a raw statement, which is the point of the interface: once a
 * repository can write `OR` inline, the escape hatch stops being tempting and a
 * predicate stays a value the builder composes rather than a string a reader has
 * to parse against the schema.
 *
 * Everything added is additive to the clauses below and joins them by the same
 * rule - each modifier clones, each stores its fragment and arguments
 * separately, and nothing is quoted until execution.
 */
interface QueryBuilderInterface
{
    /**
     * Name the table this query reads or writes.
     *
     * A **bare** table name - `leads`, not `wp_leads`. The prefix is applied by
     * the implementation, so a repository never writes `$wpdb->prefix . 'leads'`
     * and a rename of the prefix reaches every query at once.
     *
     * **Every call returns a fresh builder.** A builder is a starting point, not
     * a connection: two repositories that call `table('leads')` hold two
     * independent chains, and a builder is never reused between queries because
     * the clauses would accumulate. The name matches Laravel's query builder, so
     * the habit transfers.
     *
     * @param string $table Bare table name, with no prefix.
     */
    public function table(string $table): static;

    /**
     * The columns to select.
     *
     * Omitted entirely, a query selects `*`. Given columns, the statement names
     * them - which is a real difference and not an optimisation: a `SELECT *`
     * that grows a column silently changes every caller's row shape, and a
     * caller that named what it wanted does not care.
     *
     * @param string ...$columns Bare column names.
     */
    public function select(string ...$columns): static;

    /**
     * Add a condition, joined to the others by `AND`.
     *
     * The value is bound as a parameter - never interpolated - so a value
     * containing a quote cannot alter the statement.
     *
     * `$operator` exists for the comparisons a repository genuinely needs. It is
     * deliberately **not** a free-form string: a caller able to pass any operator
     * can pass one that changes the statement's shape, and the point of a builder
     * is that the shape is the builder's to decide. The supported set is closed:
     * `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE` and `NOT LIKE` (matched
     * case-insensitively).
     *
     * A `LIKE` pattern is the caller's to build. `%` and `_` are the wildcards
     * the database reads, and a term that arrived from a user should have
     * `$wpdb->esc_like()` run over it *before* the wildcards are added, so the
     * term's own `%` is matched literally rather than standing for "anything".
     * The builder does not do that for the caller: an escaped pattern is one the
     * caller chose, and re-escaping a pattern that already carries deliberate
     * wildcards would search for literal per cent signs.
     *
     * @param string                        $column   Bare column name.
     * @param string                        $operator One of `=`, `!=`, `<>`, `<`,
     *                                                `<=`, `>`, `>=`, `LIKE`,
     *                                                `NOT LIKE`. Defaults to
     *                                                equality, which is what
     *                                                almost every call wants.
     * @param string|int|float|bool|null    $value    The value to compare against.
     *                                                Converted to a placeholder and
     *                                                a bound argument. `null` is
     *                                                accepted only so it can be
     *                                                refused with a clear message -
     *                                                see below.
     *
     * @throws Exceptions\QueryException When the operator is not supported, or when
     *                                  `$value` is null. A null here is a caller
     *                                  who means `IS NULL` and reached for the
     *                                  wrong method: `= NULL` is unknown in SQL
     *                                  and matches nothing, so it is refused
     *                                  rather than compiled into a condition that
     *                                  silently returns no rows. Use
     *                                  {@see whereNull()} or {@see whereNotNull()},
     *                                  which say the intent and bind nothing.
     */
    public function where(string $column, string|int|float|bool|null $value, string $operator = '='): static;

    /**
     * Add a condition joined to the others by `OR`.
     *
     * The same clause as {@see where()} with a different connective, and the same
     * deferral: the value is a bound argument, never interpolated.
     *
     * What an ungrouped `OR` means
     *
     * SQL has no precedence between `AND` and `OR` that a reader can rely on -
     * `AND` binds tighter, so a chain that mixes them is read left to right in a
     * way that rarely matches the intent. The clause list here is flat, so an
     * `orWhere()` added after two `where()` calls produces
     * `a AND b OR c`, which MySQL reads as `(a AND b) OR c`. A caller who wants
     * `a AND (b OR c)` must reach for {@see whereGroup()} rather than assuming
     * the builder groups for them.
     *
     * @param string                $column   Bare column name.
     * @param string|int|float|bool|null $value The value to compare against, bound
     *                                        as a parameter. `null` is accepted
     *                                        only so it can be refused with a
     *                                        clear message - see {@see where()}.
     * @param string                $operator One of the operators {@see where()}
     *                                        accepts.
     *
     * @throws Exceptions\QueryException When the operator is not supported, or when
     *                                  `$value` is null - see {@see where()}.
     */
    public function orWhere(string $column, string|int|float|bool|null $value, string $operator = '='): static;

    /**
     * Add a parenthesised group of conditions, joined to the others by `AND`.
     *
     * The way to say `a AND (b OR c)`. The callback receives a fresh builder and
     * its conditions become one bracketed term in the outer statement, so an `OR`
     * inside it cannot reach out and change what a later `where()` conjoins with.
     *
     *     ->where('status', 'new')
     *     ->whereGroup(fn ($q) => $q->where('name', $term)->orWhere('industry', $term))
     *     // WHERE `status` = %s AND (`name` = %s OR `industry` = %s)
     *
     * The callback's builder starts empty: it carries no table, no projection and
     * none of the outer conditions, so the group is written as its own predicate
     * and nothing leaks in either direction.
     *
     * @param callable(static): static $callback Receives an empty builder, returns
     *                                           the one holding the group's
     *                                           conditions.
     *
     * @throws Exceptions\QueryException When the callback returns no condition,
     *                                  which would compile to `()` - a syntax
     *                                  error rather than a forgiving no-op.
     */
    public function whereGroup(callable $callback): static;

    /**
     * Add a condition matching any of a list of values.
     *
     * Compiles to `IN (…, …)` with one placeholder per value, each bound. An empty
     * `$values` is refused rather than compiled: `IN ()` is a syntax error in
     * MySQL, and the honest reading of "match one of nothing" is that the caller
     * built the list from a filter that came out empty and the query should not
     * run at all.
     *
     * @param string              $column Bare column name.
     * @param list<string|int|float|bool> $values The values to match.
     *
     * @throws Exceptions\QueryException When `$values` is empty.
     */
    public function whereIn(string $column, array $values): static;

    /**
     * Add a condition matching none of a list of values.
     *
     * Compiles to `NOT IN (…, …)`, bound like {@see whereIn()}.
     *
     * An empty `$values` is **not** refused here, and that asymmetry is
     * deliberate. `NOT IN ()` would be a syntax error, but the question it is
     * asked - "is this column outside a set that turned out to be empty" - has a
     * true answer for every row, so the clause is dropped and the statement
     * matches everything. An empty exclusion list excludes nothing.
     *
     * The one case that is not a fact about the data: SQL's `NOT IN` is unknown,
     * not true, when the list holds a `NULL` - so a `NULL` in `$values` makes the
     * whole condition match nothing. Values are the caller's to keep free of
     * nulls, and a `null` is refused here rather than compiled into a condition
     * that silently empties the result.
     *
     * @param string              $column Bare column name.
     * @param list<string|int|float|bool> $values The values to exclude.
     *
     * @throws Exceptions\QueryException When `$values` contains a null.
     */
    public function whereNotIn(string $column, array $values): static;

    /**
     * Add a condition matching rows where the column is null.
     *
     * Compiles to `IS NULL` and binds nothing: this is the test `where()` refuses
     * a null for, stated as its own method so the intent is the call.
     *
     * @param string $column Bare column name.
     */
    public function whereNull(string $column): static;

    /**
     * Add a condition matching rows where the column is not null.
     *
     * Compiles to `IS NOT NULL`. The counterpart to {@see whereNull()}.
     *
     * @param string $column Bare column name.
     */
    public function whereNotNull(string $column): static;

    /**
     * Join a second table, keeping only rows that match on both sides.
     *
     * Compiles to `INNER JOIN`, and the join is emitted between the `FROM` clause
     * and the `WHERE`, which is the only position MySQL accepts it in.
     *
     * `$first` and `$second` are the two sides of the comparison. Naming the
     * tables they belong to - `deals.company_id`, `companies.id` - is on the
     * caller, because this method has no way to know which table a bare column
     * belongs to and MySQL rejects an ambiguous reference outright. A bare name
     * works when only one of the joined tables has the column, which is the
     * common case.
     *
     * Neither side is bound: both are identifiers, and an identifier cannot be a
     * parameter. They are validated as such, so a string carrying SQL is refused
     * rather than pasted into the statement.
     *
     * @param string $table    Bare table name, with no prefix - the prefix is
     *                         applied like every other table in this builder.
     * @param string $first    The left-hand column, optionally table-qualified.
     * @param string $operator The comparison, from the operator list.
     * @param string $second   The right-hand column, optionally table-qualified.
     *
     * @throws Exceptions\QueryException When a name is not a bare identifier, or
     *                                  the operator is not supported.
     */
    public function join(string $table, string $first, string $operator, string $second): static;

    /**
     * Join a second table, keeping every row from the left side regardless.
     *
     * Compiles to `LEFT JOIN`: a left row with no match appears once with every
     * joined column null, rather than disappearing. That is what makes it the
     * right join for an optional relation - a deal whose stage row is gone should
     * still be listed, without its stage, rather than vanishing from the list.
     *
     * @param string $table    Bare table name, with no prefix.
     * @param string $first    The left-hand column, optionally table-qualified.
     * @param string $operator The comparison, from the operator list.
     * @param string $second   The right-hand column, optionally table-qualified.
     *
     * @throws Exceptions\QueryException When a name is not a bare identifier, or
     *                                  the operator is not supported.
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): static;

    /**
     * Sort the results.
     *
     * @param string $column    Bare column name.
     * @param string $direction `asc` or `desc`, case-insensitive.
     *
     * @throws Exceptions\QueryException When the direction is neither.
     */
    public function orderBy(string $column, string $direction = 'asc'): static;

    /**
     * Sort the results, newest first.
     *
     * The spelling Laravel uses, and the one almost every listing wants. Kept as
     * a method rather than left to `orderBy('id', 'desc')` at each call site so
     * the common case reads as intent rather than as an argument a reader has to
     * resolve.
     *
     * @param string $column Bare column name.
     */
    public function orderByDesc(string $column): static;

    /**
     * Take a row lock on the rows this query selects.
     *
     * The `SELECT` this compiles to ends with `FOR UPDATE`, which is how a
     * read-then-write use case stops two requests from reading the same row and
     * both deciding to write it. The pairing is always the same: lock, read,
     * decide, write - see
     * {@see \Core\Shared\Domain\Contracts\TransactionManagerContract} for the
     * boundary the lock lives inside.
     *
     * Only meaningful inside a transaction, on InnoDB
     *
     * `FOR UPDATE` outside a transaction is a lock released at the end of the
     * statement, which protects nothing: the row is unlocked before the caller
     * has decided what to write. It is also silently ignored by MyISAM, which has
     * no row locks - the statement succeeds and no lock is taken, which is the
     * failure mode worth knowing about because nothing reports it.
     *
     * The framework does not check either condition. It cannot: whether a
     * transaction is open is the transaction manager's state, and the engine is
     * the table's. Both are the caller's contract to honour, and the schema
     * builder emits InnoDB so the engine half is satisfied by default.
     *
     * What it locks
     *
     * MySQL locks the rows the statement examines, so a lock taken on a query
     * with a `WHERE` that matches nothing locks nothing. The intended use is
     * `table('leads')->where('id', $id)->lockForUpdate()->first()`, which locks
     * exactly the row about to be written.
     *
     * Only read statements honour this. A builder that has had `lockForUpdate()`
     * called and is then used for `update()`/`delete()` ignores it, because the
     * write statements do not go through the `SELECT` compilation path.
     */
    public function lockForUpdate(): static;

    /**
     * Cap the number of rows returned.
     *
     * @throws Exceptions\QueryException When `$limit` is not positive. A limit of
     *                                  zero or less is a caller who computed the
     *                                  wrong number; MySQL rejects a negative
     *                                  `LIMIT` and SQLite silently reads it as
     *                                  zero, so the two backends would disagree
     *                                  about the same request.
     */
    public function limit(int $limit): static;

    /**
     * Skip a number of rows.
     *
     * @throws Exceptions\QueryException When `$offset` is negative, for the same
     *                                  reason {@see limit()} refuses one.
     */
    public function offset(int $offset): static;

    /**
     * Run the `SELECT` and return every matching row.
     *
     * Rows are **raw associative arrays**, keyed as the database spells the
     * columns. Mapping them onto an entity is the repository's job, see
     * {@see \Core\Lead\Infrastructure\Mappers\LeadEntityMapper} - and keeping it
     * there is what allows one builder to serve every table in the project.
     *
     * @return list<array<string, mixed>>
     *
     * @throws Exceptions\QueryException When no table was named, or the statement
     *                                  fails.
     */
    public function get(): array;

    /**
     * Run the `SELECT` and return the first row, or null.
     *
     * Null for "no row" rather than an exception: absence is an ordinary answer
     * for a lookup, and making every caller wrap one in a `try` to handle a case
     * that is not exceptional is how a missing row becomes a 500.
     *
     * @return array<string, mixed>|null
     *
     * @throws Exceptions\QueryException When the statement fails - which is
     *                                  distinct from there being no row, and is
     *                                  why this throws at all.
     */
    public function first(): ?array;

    /**
     * Count the rows the query would return, ignoring any limit.
     *
     * A separate method rather than `count(get())`: a paginated read needs the
     * total across every page, and counting the current slice is how an API
     * reports "1 of 1" on every page. It also avoids reading rows only to throw
     * them away.
     *
     * Any limit and offset are ignored, because the question is how many there
     * are and not how many fit.
     *
     * @throws Exceptions\QueryException When no table was named, or the statement
     *                                  fails.
     */
    public function count(): int;

    /**
     * Insert one row, returning the assigned auto-increment id.
     *
     * Named as Laravel names it, so `insertGetId()` is the call a repository
     * written from Laravel habit already reaches for.
     *
     * @param array<string, mixed> $values Column => value. Every value is bound.
     *
     * @return int The id the database assigned.
     *
     * @throws Exceptions\UniqueConstraintException When a unique index rejected
     *                                  the row. A subtype of `QueryException`, so
     *                                  a caller that does not care needs no extra
     *                                  arm - see the class for how it is detected.
     * @throws Exceptions\QueryException When no table or no values were given, or
     *                                  the insert fails, or the insert succeeded
     *                                  but no id was reported - an id of zero
     *                                  would be an entity whose `exists()` is
     *                                  false, so its next save would insert a
     *                                  second copy.
     */
    public function insertGetId(array $values): int;

    /**
     * Update every row the query matches, returning the number affected.
     *
     * A return of `0` is not a failure: it means the predicate matched nothing,
     * or matched rows that already held those values. Callers that need to tell
     * those apart read the row first.
     *
     * @param array<string, mixed> $values Column => value. Every value is bound.
     *
     * @throws Exceptions\UniqueConstraintException When a unique index rejected
     *                                  the row - an update can hit one just as an
     *                                  insert can, by moving a row onto a value
     *                                  another row already holds.
     * @throws Exceptions\QueryException When no table or no values were given, or
     *                                  the update fails.
     */
    public function update(array $values): int;

    /**
     * Delete every row the query matches, returning the number removed.
     *
     * As with {@see update()}, `0` means nothing matched rather than that
     * anything went wrong.
     *
     * Refuses to run with no conditions at all - see the implementation - because
     * an unqualified `DELETE` empties the table and is far more likely to be a
     * forgotten `where()` than an intent.
     *
     * @throws Exceptions\QueryException When no table was named, when there is no
     *                                  condition, or the delete fails.
     */
    public function delete(): int;

    // ------------------------------------------------------------------ transactions

    /**
     * Run a callback inside a transaction and return its result.
     *
     * Rolls back and rethrows when the callback throws. The callback form is
     * preferred over a manual {@see beginTransaction()}/{@see commit()} pair
     * because a manual pair can be left open by an early return or a throw, and a
     * transaction left open holds locks until the connection ends.
     *
     * Nesting: an inner call joins, it does not open a second
     *
     * A `transaction()` inside another runs the callback in the frame it was
     * handed and issues no SQL. This is not an optimisation - MySQL has no nested
     * transactions, so a second `START TRANSACTION` **implicitly commits** the one
     * already open. The nested write would then be committed before the outer
     * frame decided whether to, which is precisely the half-written state a
     * boundary exists to prevent. Implementations count depth and issue
     * BEGIN/COMMIT/ROLLBACK only at the outermost frame.
     *
     * This matches the nesting guarantee
     * {@see \Core\Shared\Domain\Contracts\TransactionManagerContract} states, and
     * it is the same rule `WpTransactionManager` already implements in `app/` -
     * one behaviour, stated in both places a caller might look for it.
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public function transaction(callable $callback): mixed;

    /**
     * Open a unit of work explicitly.
     *
     * For a caller whose work cannot be expressed as one callback - a batched
     * import. Prefer {@see transaction()}; it cannot be left open.
     */
    public function beginTransaction(): void;

    /**
     * Commit the open unit of work.
     *
     * Only meaningful as the pair of {@see beginTransaction()}. At a nesting
     * depth above the outermost this is a no-op: the outer frame owns the commit.
     */
    public function commit(): void;

    /**
     * Abandon the open unit of work.
     *
     * Discards everything written since the outermost frame opened. A no-op when
     * nothing is open, because a bare `ROLLBACK` with no transaction is an error
     * on the server and would replace the caller's failure with an unrelated one.
     */
    public function rollback(): void;

    /**
     * Whether a unit of work is open.
     *
     * Answered from the implementation's own depth counter rather than by asking
     * the connection: `$wpdb` exposes no portable "am I in a transaction" query.
     * **Never throws**, so an error handler can ask without risking a second
     * failure while handling the first.
     */
    public function inTransaction(): bool;
}
