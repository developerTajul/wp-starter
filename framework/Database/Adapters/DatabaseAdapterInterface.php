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
 * Four methods, and no query building. This is a *schema* seam, not a
 * general-purpose database abstraction — a caller that wants to read rows wants
 * `$wpdb`, and wrapping it here would grow an interface whose every addition is
 * a change every adapter has to absorb.
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
}
