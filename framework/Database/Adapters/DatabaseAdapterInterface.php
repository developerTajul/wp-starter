<?php
declare(strict_types=1);

namespace Framework\Database\Adapters;

/**
 * The whole of what the schema layer is allowed to know about a database.
 *
 * ## Why this exists
 *
 * {@see \Framework\Database\Schema\Schema} and
 * {@see \Framework\Database\Schema\Blueprint} describe a table in the vocabulary
 * of the framework — a column has a type, a length, a nullability, a default.
 * Turning that description into the SQL one particular database accepts, and
 * running it, is a different job, and it is the job described here.
 *
 * Keeping the two apart is what makes the builder testable without a database
 * and portable to one that is not MySQL: an implementation of this interface
 * either compiles statements and executes them, or records what it was asked to
 * do so a test can assert on the description rather than on the SQL.
 *
 * ## The contract is deliberately narrow
 *
 * No query building. This is a *schema* seam, not a general-purpose database
 * abstraction — a caller that wants to read rows wants `$wpdb`, and wrapping it
 * here would grow an interface whose every addition is a change every adapter
 * has to absorb.
 *
 * What it does cover, and all it covers, is four kinds of thing:
 *
 *  - **Names** — {@see prefix()} and {@see tableName()}, so a schema can write a
 *    table the way an application thinks of it and the statement still addresses
 *    the table that exists.
 *  - **Running a statement** — {@see statement()}, {@see createTable()},
 *    {@see dropTableIfExists()} and {@see renameTable()}, each taking an
 *    assembled statement rather than a fragment.
 *  - **Asking what exists** — {@see hasTable()}, {@see hasColumn()},
 *    {@see hasIndex()}, {@see tables()}, {@see columnDetails()},
 *    {@see indexDetails()} and {@see columnDefinition()}: catalogue probes, so a
 *    migration can decide without running something and reading the wreckage.
 *  - **Recording instead of running** — {@see pretend()},
 *    {@see stopPretending()} and {@see recordedStatements()}, which are what make
 *    a dry run possible without a second code path through every migration.
 *
 * {@see charsetCollation()} is the one member that fits none of those, and is
 * there because a table's charset is part of the statement the caller assembles.
 *
 * A caller needing more than this is the signal that it wants a second seam
 * rather than a wider one — which is exactly what happened when the migrator
 * needed to read and write its own book-keeping rows:
 * {@see \Framework\Database\Storage\MigrationStorageInterface} is that seam, and
 * it exists separately so this one did not have to grow `insert()` and
 * `get_var()` for a single caller.
 *
 * ## Statements are statements, not fragments
 *
 * Each method takes an already-assembled, fully-qualified statement. An adapter
 * may still translate it into the dialect it targets — the WordPress adapter
 * re-shapes `CREATE TABLE` into the diffing form `dbDelta()` expects — but it is
 * never handed a half-built clause to finish. A seam that required compiling SQL
 * in one place and completing it in another would put half the dialect in the
 * caller and half in the adapter, which is exactly the coupling this interface
 * exists to prevent.
 *
 * ## Identifiers, not values
 *
 * Every string a caller passes here is a table or column *name*, and none of
 * them can be bound as a parameter — `prepare()` quotes values, and a quoted
 * table name is a syntax error. So an implementation is responsible for making
 * an identifier safe to interpolate; a caller never does it and never has to
 * reason about whether it did.
 */
interface DatabaseAdapterInterface
{
    /**
     * The prefix prepended to every table name in this database.
     *
     * Bare `$wpdb->prefix` on WordPress, and applied to WordPress's own tables
     * exactly as it is to the ones a plugin creates — a site configured with
     * `$table_prefix = 'leadbridge_'` has `leadbridge_users`, not `users`.
     *
     * Part of the contract rather than an implementation detail because a table
     * name is only half a name: a migration that created `notes` and a query
     * that reads `{$prefix}notes` have to agree, and neither of them should have
     * to reach for a global to find out what the prefix is.
     */
    public function prefix(): string;

    /**
     * The real name of a table the schema refers to.
     *
     * Applied to every table name a migration writes down, so a schema can name
     * a table the way an application thinks of it (`leads`, `users`) and the
     * statement still addresses the table that exists. On WordPress that prefix
     * is `$wpdb->prefix`, and it applies to **every** table — core's own
     * included. A site with `$table_prefix = 'leadbridge_'` has
     * `leadbridge_users`, so `constrained('users')` must resolve to
     * `leadbridge_users`; leaving core's names unprefixed is a foreign key that
     * points at a table which does not exist.
     *
     * An implementation is free to do something else — map a name through a
     * scheme of its own, or leave names untouched — which is why this is a
     * method rather than `prefix() . $name` at each call site. Identity is the
     * correct behaviour for a target with no notion of prefixes, and is what an
     * adapter over such a target should return.
     */
    public function tableName(string $table): string;

    /**
     * Run a schema statement, reporting failure rather than returning a status.
     *
     * @param string $sql        A complete statement.
     * @param string $operation  What the statement was doing, in the form a
     *                           message can use — "create the notes table". A
     *                           failing adapter is expected to put this in its
     *                           exception, because "migration failed" without
     *                           the operation is the message an operator cannot
     *                           act on.
     *
     * @throws \Framework\Database\Schema\Exceptions\SchemaException When the
     *         statement fails.
     */
    public function statement(string $sql, string $operation): void;

    /**
     * Create the table `$sql` describes.
     *
     * @param string $table The bare table name, with no prefix. Used only to
     *                      name the table in a failure message; the name the
     *                      statement creates is already inside `$sql`.
     * @param string $sql   A complete `CREATE TABLE` statement, with every
     *                      table name already resolved through
     *                      {@see tableName()}.
     *
     * @throws \Framework\Database\Schema\Exceptions\SchemaException
     */
    public function createTable(string $table, string $sql): void;

    /**
     * Drop a table, and do nothing if it is not there.
     *
     * Not "drop a table that exists" — the *does not exist* half is the contract.
     * A `down()` is routinely run against a database where its `up()` half
     * failed, and a drop that raised "unknown table" there would turn a
     * recoverable state into a stuck one: the migration could not complete and
     * could not be reversed either.
     *
     * @param string $table The bare table name, with no prefix.
     */
    public function dropTableIfExists(string $table): void;

    /**
     * Whether a table is present in the database.
     *
     * On the contract rather than left to each implementation's own API, so
     * {@see \Framework\Database\Schema\Schema::hasTable()} can ask the question
     * without knowing which adapter it is talking to.
     *
     * A *catalogue probe*, not "attempt something and catch the failure": a
     * migration that asks whether a table exists is usually deciding whether to
     * create it, and a statement that failed for an unrelated reason —
     * permissions, a dropped connection — must not be read as an absence.
     */
    public function hasTable(string $table): bool;

    /**
     * Whether a table carries a column of the given name.     *
     * A *catalogue probe*, for the same reason {@see hasTable()} is one: the
     * caller is a migration deciding whether to add the column, and "the probe
     * failed" must not be read as "the column is absent" — that would add a
     * second copy of a column that is already there, which MySQL refuses, turning
     * a recoverable re-run into a failure.
     *
     * **An absent table is `false`, not an error.** A column cannot exist on a
     * table that does not, and a guard that asks "is this column missing?" before
     * adding it is most often running against a table created moments earlier in
     * the same batch — or not yet created at all, on a fresh install.
     *
     * @param string $table  The bare table name, with no prefix.
     * @param string $column The column name, as the schema spells it.
     */
    public function hasColumn(string $table, string $column): bool;

    /**
     * Whether a table carries an index of the given name.
     *
     * Asked by *name* rather than by the columns it covers, because that is how
     * MySQL identifies a key and therefore how a `DROP`/`ADD` has to refer to it.
     * {@see \Framework\Database\Schema\Blueprint} names every index it creates
     * after its columns joined by underscores (`company_id`, `note_id_occurred_at`),
     * which is what makes an index name derivable from the column list a caller
     * already has.
     *
     * An absent table is `false`, as in {@see hasColumn()}.
     */
    public function hasIndex(string $table, string $index): bool;

    /**
     * The SQL definition of an existing column, as the database spells it.
     *
     * The counterpart to {@see hasColumn()}: that one answers whether a column is
     * there, this one answers what it *is*. The case that needs it is a rename —
     * MySQL's `CHANGE COLUMN` requires the definition to be restated, and a
     * caller who has only two names should not have to repeat a type the database
     * already knows.
     *
     * Without this the alternative is Doctrine-style schema introspection, which
     * does not exist here and is not going to: the whole design keeps the builder
     * describing and the adapter translating. Asking the target what it currently
     * has is the adapter's job, not a new dependency's.
     *
     * Returns null when the column is absent, rather than throwing — absence is
     * the ordinary answer for a migration that is being re-run, and the caller
     * has {@see hasColumn()} to distinguish it from a failure if it cares.
     *
     * @param string $table  The bare table name, with no prefix.
     * @param string $column The column name.
     *
     * @return string|null A definition usable directly after a `CHANGE COLUMN`
     *                     clause — type, nullability, default and extra, in that
     *                     order — or null when the column does not exist.
     */
    public function columnDefinition(string $table, string $column): ?string;

    /**
     * Rename a table, and fail when it is not there.
     *
     * Deliberately *not* tolerant the way {@see dropTableIfExists()} is. There is
     * no `IF EXISTS` spelling of a rename that means anything: a caller renaming
     * a missing table holds a wrong belief about the schema, and a no-op would
     * let a migration record itself as applied against a table that does not
     * exist under either name. Failing loudly is the only outcome that surfaces
     * the mistake at the point it is made.
     *
     * Both names are bare, and both are resolved through {@see tableName()} — a
     * rename that prefixed only the destination would move `wp_notes` to
     * `archive`, a table nothing else in the application would look for again,
     * and the data would appear to have vanished rather than the migration to
     * have failed.
     *
     * @param string $from The current bare table name, with no prefix.
     * @param string $to   The new bare table name, with no prefix.
     *
     * @throws \Framework\Database\Schema\Exceptions\SchemaException When the
     *         rename fails.
     */
    public function renameTable(string $from, string $to): void;

    /**
     * The character set and collation clause for new tables.
     *
     * Preferred over a hardcoded `utf8mb4` because the host application usually
     * knows better than a framework does — WordPress returns the collation its
     * own tables use, which is the one a plugin's tables should match, and on a
     * pre-5.5 database that is deliberately *not* utf8mb4.
     *
     * Empty when the target has no such concept, in which case a caller omits
     * the clause entirely rather than emitting an empty one.
     */
    public function charsetCollation(): string;

    /**
     * Start recording schema statements instead of running them.
     *
     * The seam behind `migrate --pretend`. Once this is on, every statement-
     * issuing method on the adapter appends to a list rather than touching the
     * database, and {@see recordedStatements()} hands the list back.
     *
     * ## What pretending can and cannot see
     *
     * It intercepts **this interface**, which is where every `Schema::` call
     * lands. A migration that also issues raw `$wpdb->query()` calls — a data
     * migration rewriting values, say — is not visible here, and cannot be: the
     * framework does not route those calls, so there is nothing to intercept.
     *
     * That is a real limit and the command built on this states it, rather than
     * implying the output is a complete account of what a run would do. A
     * `--pretend` that quietly omitted half the work would be worse than none.
     *
     * ## Reads are not recorded
     *
     * Probes such as {@see hasColumn()} still execute, and must: a migration that
     * guards an `ADD COLUMN` on a probe has to take the same branch it would take
     * for real, or the recorded statements describe a decision nobody made. So
     * pretending changes write behaviour only.
     */
    public function pretend(): void;

    /**
     * Stop recording and run statements again.
     *
     * Does not clear the recorded list — {@see recordedStatements()} is read
     * after this, and clearing here would make the caller's only way to obtain
     * the output also the act of destroying it.
     */
    public function stopPretending(): void;

    /**
     * The statements issued since {@see pretend()} was called, in order.
     *
     * Empty when pretending was never enabled. Not cleared by reading, so a
     * caller can inspect twice.
     *
     * @return list<string>
     */
    public function recordedStatements(): array;

    /**
     * Every table in the database, with the prefix already applied.
     *
     * Used by the schema dumper, which has to name the tables it reads and then
     * strip the prefix back off to spell them in the schema language. Returned
     * prefixed rather than bare because that is how they exist: a caller deciding
     * what to dump is choosing among real tables, and one that had to re-apply
     * the prefix to make a query would be re-deriving a name it was just given.
     *
     * Ordered, so a dump of several tables is stable between runs — a diff of two
     * dumps should show real schema changes and not a reshuffled table list.
     *
     * @return list<string>
     */
    public function tables(): array;

    /**
     * A table's columns, in the order the table holds them.
     *
     * The order is load-bearing rather than incidental: a `CREATE TABLE` that
     * names its columns in a different order is a different table to anyone
     * reading it, and `after()`/`first()` positions are only meaningful against
     * the real sequence.
     *
     * @return list<array{name: string, type: string, nullable: bool, default: string|null, autoIncrement: bool}>
     */
    public function columnDetails(string $table): array;

    /**
     * A table's keys, each with its columns in index order.
     *
     * The primary key is included, named `PRIMARY`, because a dumper has to know
     * a table has one — but a caller emitting schema vocabulary must not turn it
     * into an `index()` call, since the builder writes the primary key itself.
     *
     * @return list<array{name: string, columns: list<string>, unique: bool, primary: bool}>
     */
    public function indexDetails(string $table): array;

}
