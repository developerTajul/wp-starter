<?php
declare(strict_types=1);

namespace Framework\Database\Schema;

use Closure;
use Framework\Database\Adapters\DatabaseAdapterInterface;
use Framework\Database\Schema\Exceptions\SchemaException;

/**
 * The static entry point a migration uses to declare a table.
 *
 *     Schema::create('notes', function (Blueprint $table) {
 *         $table->id();
 *         $table->string('title');
 *         $table->timestamps();
 *     });
 *
 *     Schema::dropIfExists('notes');
 *
 * Why this is static, when almost nothing else here is
 *
 * It is the one deliberate exception to the framework's dependency-injection
 * rule, and the reason is that the alternative is worse at the call site. A
 * migration is loaded by *path* and instantiated from a filename
 * (see {@see \Framework\Database\MigrationFile}), so it cannot be given a
 * schema builder by its caller without every migration growing a constructor
 * that exists only to pass the same object along - the exact ceremony
 * `CLAUDE.md` rules out for configuration, for the same underlying reason.
 *
 * Static *state*, though, would be a real problem: a process-wide builder
 * cannot be swapped for a test, and two plugins sharing a database would fight
 * over it. So the state here is a single adapter reference resolved through the
 * container on each call ({@see adapter()}), and
 * {@see Schema::useAdapter()} is how a test substitutes one - the same seam the
 * migrator offers by taking its `$wpdb` as a constructor argument.
 *
 * One method per verb
 *
 * `create()` builds and runs a `CREATE TABLE`. `table()` is handed a table that
 * already exists and each column defined in the callback becomes its own
 * `ALTER TABLE ... ADD COLUMN`. `drop()` and `dropIfExists()` are the same
 * operation, and both are here because the second is the name a reader looks
 * for: a migration's `down()` should be self-describing about whether it
 * tolerates a missing table, and it always should.
 */
final class Schema
{
    /**
     * An adapter to use in place of the container's, for the life of the process
     * or until it is cleared with null.
     *
     * Exists for tests, and only for tests. It is not a configuration point: an
     * application that wants a different adapter binds
     * {@see DatabaseAdapterInterface} in a service provider, where the binding
     * is explicit and ordered against the other providers.
     */
    private static ?DatabaseAdapterInterface $adapter = null;

    /**
     * The adapter {@see pretend()} displaced, held so {@see stopPretending()}
     * can put it back rather than clearing the slot.
     *
     * `null` is a meaningful value here - it means "there was nothing installed
     * and the container should resolve one again", so this is only read while
     * {@see $pretending} is true.
     */
    private static ?DatabaseAdapterInterface $displaced = null;

    /**
     * This is a namespace of static entry points, not a thing to construct.
     */
    private function __construct()
    {
    }

    /**
     * Replace the adapter the static entry points delegate to.
     *
     * @param DatabaseAdapterInterface|null $adapter Null restores the
     *                                              container-resolved one.
     */
    public static function useAdapter(?DatabaseAdapterInterface $adapter): void
    {
        self::$adapter = $adapter;
    }

    /**
     * The adapter this namespace is currently talking to.
     *
     * Exposed because anything that *reads* the schema - the dumper, and the
     * `migrate:dump` command over it - has to read through exactly the object the
     * `Schema::` calls write through, or the two disagree about which database
     * they are describing. That is not a hypothetical: a caller resolving the
     * interface from the container itself would miss {@see useAdapter()}
     * entirely, describe the real database, and look right doing it.
     *
     * Still not a place to *set* anything - {@see useAdapter()} is that, and an
     * application configures this through a container binding.
     */
    public static function adapter(): DatabaseAdapterInterface
    {
        return self::resolveAdapter();
    }

    /**
     * Record schema statements instead of running them.
     *
     * Why this pins the adapter
     *
     * {@see adapter()} re-resolves through the container on every call, which is
     * right for normal use and wrong for this: pretending is a *stateful* mode,
     * and a re-resolved instance would start with it off. So the first call
     * captures the current adapter into the static slot - making the thing that
     * records and the thing that is read back the same object - and
     * {@see stopPretending()} hands the slot back to the container.
     *
     * The limit, stated plainly
     *
     * Only statements issued through this interface are recorded. A migration
     * that runs raw `$wpdb` queries alongside its `Schema::` calls will have those
     * queries neither recorded nor run - the framework does not route them, so
     * there is nothing here to intercept. Callers built on this are expected to
     * say so rather than present the output as complete.
     *
     * Reads still execute. A migration that guards on {@see hasColumn()} has to
     * take the branch it would really take, or the recorded statements describe a
     * decision nobody made.
     */
    public static function pretend(): void
    {
        $adapter = self::resolveAdapter();

        // Remembered, not assumed to be null. `stopPretending()` used to clear
        // the slot and let `resolveAdapter()` build a fresh adapter - which is
        // wrong whenever someone had *installed* one, as `useAdapter()` does in
        // every test and as a service provider does in an application. Clearing
        // threw that adapter away and the next `Schema::` call silently described
        // a different database (or, with no `$wpdb` global, threw). Restoring the
        // displaced instance is what makes a pretend pass leave no trace.
        self::$displaced = self::$adapter;

        self::$adapter = $adapter;
        self::$pretending = true;

        $adapter->pretend();
    }

    /**
     * Stop recording, and return the statements issued while pretending.
     *
     * Restores whatever adapter {@see pretend()} displaced - the one that was
     * installed before the dry run, or the container-resolved one if there was
     * none - so a pretend pass leaves no trace on the process. The recorded list
     * is returned rather than read separately because the restore drops the only
     * reference to the adapter that holds it.
     *
     * @return list<string>
     */
    public static function stopPretending(): array
    {
        if (! self::$adapter instanceof DatabaseAdapterInterface) {
            self::$pretending = false;

            return [];
        }

        $recorded = self::$adapter->recordedStatements();

        self::$adapter->stopPretending();

        self::$adapter = self::$displaced;
        self::$displaced = null;
        self::$pretending = false;

        return $recorded;
    }

    /**
     * Whether the adapter is currently recording rather than running.
     *
     * Read by {@see \Framework\Database\Migrator} to decide whether it may record
     * a migration as applied - a pretend run must leave the tracking table
     * untouched, since it did not do the work the row would claim.
     */
    public static function isPretending(): bool
    {
        return self::$pretending;
    }

    /**
     * Records whether {@see pretend()} is in force, for {@see isPretending()}.
     *
     * A separate flag because `recordedStatements()` being non-empty is not the
     * same question: a pretend run over a migration that issues nothing would
     * read as "not pretending", and the caller would then record it as applied.
     */
    private static bool $pretending = false;

    /**
     * Create a table from the given blueprint.
     *
     * The callback is handed a fresh {@see Blueprint} and may return nothing -
     * it configures the object it is given. A `CREATE TABLE` naming a table that
     * already exists is an error, not a no-op: `dbDelta()`'s whole purpose is to
     * turn one into a diff, and accepting that here would mean a migration could
     * not tell "I created this" from "someone else's schema happened to match".
     *
     * @param string  $table    Table name, without the prefix.
     * @param Closure(Blueprint): void $callback
     *
     * @throws SchemaException When the statement fails or the name is unusable.
     */
    public static function create(string $table, Closure $callback): void
    {
        $adapter = self::resolveAdapter();
        $blueprint = new Blueprint($table, creating: true, resolveTable: self::resolver($adapter));

        $callback($blueprint);

        $adapter->createTable(
            $table,
            $blueprint->toCreateSql($adapter->tableName($table), $adapter->charsetCollation())
        );
    }

    /**
     * Add columns and indexes to a table that already exists.
     *
     *     Schema::table('notes', function (Blueprint $table) {
     *         $table->string('slug')->nullable()->index();
     *     });
     *
     * Each definition becomes its own statement, run in the order the callback
     * declared them. There is no transaction around them, and there cannot be -
     * MySQL commits DDL implicitly - so a failure partway leaves the columns
     * already added in place. That is the same half-applied state the migrator
     * reports rather than hides, and a re-run resumes from the failure because
     * the statements are issued in a fixed order.
     *
     * @param Closure(Blueprint): void $callback
     *
     * @throws SchemaException When a statement fails or a name is unusable.
     */
    public static function table(string $table, Closure $callback): void
    {
        $adapter = self::resolveAdapter();
        $blueprint = new Blueprint(
            $table,
            creating: false,
            resolveTable: self::resolver($adapter),
            resolveColumnDefinition: static fn (string $t, string $c): ?string => $adapter->columnDefinition($t, $c)
        );

        $callback($blueprint);

        // A callback that defined nothing - guarded behind a conditional that
        // did not hold - must not emit `ALTER TABLE x` with an empty clause,
        // which is a syntax error rather than the no-op the caller intended.
        if ($blueprint->isEmpty()) {
            return;
        }

        $qualified = $adapter->tableName($table);

        foreach ($blueprint->toAddColumnStatements($qualified) as $sql) {
            $adapter->statement($sql, sprintf('add a column to the [%s] table', $table));
        }
    }

    /**
     * Rename a table.
     *
     *     Schema::rename('notes', 'archive');
     *
     * Both names are schema names, resolved through the adapter's prefix, a
     * rename that prefixed only the destination would move `wp_notes` to
     * `archive`, a table nothing else in the application would look for again,
     * and the data would appear to have vanished rather than the migration to
     * have failed.
     *
     * **Not reversible for free.** The `down()` of a migration that renames is
     * another rename in the other direction, and it has to be written; nothing
     * here remembers the previous name. A rename is also the operation most
     * likely to leave a foreign key pointing at a table that no longer exists -
     * MySQL does not rewrite `REFERENCES` clauses on rename - so a migration
     * that renames a table another table references needs to drop and re-add
     * that constraint.
     *
     * Throws when the source table is absent. There is no tolerant spelling:
     * a caller renaming a missing table holds a wrong belief about the schema.
     *
     * @throws SchemaException When the rename fails.
     */
    public static function rename(string $from, string $to): void
    {
        self::resolveAdapter()->renameTable($from, $to);
    }

    /**
     * Drop a table.
     *
     * The same operation as {@see dropIfExists()}; the separate name is here so
     * that code migrating from raw `$wpdb` calls reads the way it was written.
     * Prefer `dropIfExists()` in a `down()` - see that method.
     *
     * @throws SchemaException When the drop fails.
     */
    public static function drop(string $table): void
    {
        self::resolveAdapter()->dropTableIfExists($table);
    }

    /**
     * Drop a table, and say nothing if it was not there.
     *
     * This is what a migration's `down()` wants, essentially always. A `down()`
     * is routinely run against a database where its `up()` never completed - a
     * batch that failed on the next migration, a rollback after a hand-edited
     * schema - and a drop that raised "unknown table" there would turn a
     * recoverable state into one that is neither finishable nor reversible.
     *
     * It is deliberately *not* forgiving of failure: only the missing table is
     * tolerated, not a drop that was refused.
     *
     * @throws SchemaException When the drop fails for any other reason.
     */
    public static function dropIfExists(string $table): void
    {
        self::resolveAdapter()->dropTableIfExists($table);
    }

    /**
     * Whether a table exists.
     *
     * Answers from the adapter's own catalogue probe rather than by attempting a
     * statement and catching the failure, because "does it exist" and "could I
     * reach it" are different questions and only the first is being asked.
     */
    public static function hasTable(string $table): bool
    {
        return self::resolveAdapter()->hasTable($table);
    }

    /**
     * Whether a table carries a column of the given name.     *
     * The precondition every alteration is written against: a second `ADD COLUMN`
     * for a column that is already there is an error MySQL refuses outright, so a
     * migration that means to be re-runnable has to ask first. This is the
     * question, and the adapter answers it from the catalogue rather than from a
     * failed statement.
     *
     * `false` when the table does not exist - a column cannot exist on a table
     * that does not, and on a fresh install the table is often not there yet.
     * Guarding a create-then-alter pair with this therefore does the right thing
     * in both orders.
     */
    public static function hasColumn(string $table, string $column): bool
    {
        return self::resolveAdapter()->hasColumn($table, $column);
    }

    /**
     * Whether a table carries an index of the given name.
     *
     * By name, because a name is how MySQL identifies a key and therefore the
     * only thing a `DROP INDEX`/`ADD INDEX` pair can be written against.
     * {@see Blueprint} derives an index's name from its columns joined by
     * underscores - `title`, `note_id_occurred_at` - so a guard can name the
     * index it is about to replace without having recorded it anywhere.
     *
     * `false` when the table does not exist, as in {@see hasColumn()}. A primary
     * key is found under the name the database records it as: `PRIMARY`.
     */
    public static function hasIndex(string $table, string $index): bool
    {
        return self::resolveAdapter()->hasIndex($table, $index);
    }

    /**
     * The adapter every entry point delegates to.
     *
     * Resolved through the container on *each* call rather than captured once,
     * so a provider that binds the interface during `register()` is honoured
     * even for a `Schema::` call made while another provider is still booting -
     * and so {@see useAdapter()} can substitute one after the container is up.
     *
     * Falls back to constructing the WordPress adapter directly when there is no
     * container, which is the case in a migration run outside a loaded plugin.
     */
    private static function resolveAdapter(): DatabaseAdapterInterface
    {
        if (self::$adapter instanceof DatabaseAdapterInterface) {
            return self::$adapter;
        }

        if (function_exists('app')) {
            try {
                $resolved = app(DatabaseAdapterInterface::class);

                if ($resolved instanceof DatabaseAdapterInterface) {
                    return $resolved;
                }
            } catch (\Throwable) {
                // Not bound, or not resolvable yet. A `Schema::` call during an
                // early hook is legitimate, and the direct construction below is
                // exactly what the binding would have produced - falling through
                // keeps a migration runnable in a context with no container.
            }
        }

        return new \Framework\Database\Adapters\WpDatabaseAdapter();
    }

    /**
     * The callable a blueprint uses to spell a referenced table name.
     *
     * WordPress prefixes every table - its own core tables included - with
     * `$wpdb->prefix`, whatever it is (`users` is `wp_users` by default and
     * `leadbridge_users` on a site with `$table_prefix = 'leadbridge_'`), so
     * the resolved spelling of every name is the adapter's job: plugin tables
     * (`leads` → `wp_leads`) and core tables alike (`users` → `wp_users`).
     *
     * @return callable(string): string
     */
    private static function resolver(DatabaseAdapterInterface $adapter): callable
    {
        return static fn (string $table): string => $adapter->tableName($table);
    }
}
