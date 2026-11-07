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
 * ## Why this is static, when almost nothing else here is
 *
 * It is the one deliberate exception to the framework's dependency-injection
 * rule, and the reason is that the alternative is worse at the call site. A
 * migration is loaded by *path* and instantiated from a filename
 * (see {@see \Framework\Database\MigrationFile}), so it cannot be given a
 * schema builder by its caller without every migration growing a constructor
 * that exists only to pass the same object along — the exact ceremony
 * `CLAUDE.md` rules out for configuration, for the same underlying reason.
 *
 * Static *state*, though, would be a real problem: a process-wide builder
 * cannot be swapped for a test, and two plugins sharing a database would fight
 * over it. So the state here is a single adapter reference resolved through the
 * container on each call ({@see adapter()}), and
 * {@see Schema::useAdapter()} is how a test substitutes one — the same seam the
 * migrator offers by taking its `$wpdb` as a constructor argument.
 *
 * ## One method per verb
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
     * Create a table from the given blueprint.
     *
     * The callback is handed a fresh {@see Blueprint} and may return nothing —
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
        $adapter = self::adapter();
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
     * declared them. There is no transaction around them, and there cannot be —
     * MySQL commits DDL implicitly — so a failure partway leaves the columns
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
        $adapter = self::adapter();
        $blueprint = new Blueprint($table, creating: false, resolveTable: self::resolver($adapter));

        $callback($blueprint);

        // A callback that defined nothing — guarded behind a conditional that
        // did not hold — must not emit `ALTER TABLE x` with an empty clause,
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
     * Drop a table.
     *
     * The same operation as {@see dropIfExists()}; the separate name is here so
     * that code migrating from raw `$wpdb` calls reads the way it was written.
     * Prefer `dropIfExists()` in a `down()` — see that method.
     *
     * @throws SchemaException When the drop fails.
     */
    public static function drop(string $table): void
    {
        self::adapter()->dropTableIfExists($table);
    }

    /**
     * Drop a table, and say nothing if it was not there.
     *
     * This is what a migration's `down()` wants, essentially always. A `down()`
     * is routinely run against a database where its `up()` never completed — a
     * batch that failed on the next migration, a rollback after a hand-edited
     * schema — and a drop that raised "unknown table" there would turn a
     * recoverable state into one that is neither finishable nor reversible.
     *
     * It is deliberately *not* forgiving of failure: only the missing table is
     * tolerated, not a drop that was refused.
     *
     * @throws SchemaException When the drop fails for any other reason.
     */
    public static function dropIfExists(string $table): void
    {
        self::adapter()->dropTableIfExists($table);
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
        return self::adapter()->hasTable($table);
    }

    /**
     * The adapter every entry point delegates to.
     *
     * Resolved through the container on *each* call rather than captured once,
     * so a provider that binds the interface during `register()` is honoured
     * even for a `Schema::` call made while another provider is still booting —
     * and so {@see useAdapter()} can substitute one after the container is up.
     *
     * Falls back to constructing the WordPress adapter directly when there is no
     * container, which is the case in a migration run outside a loaded plugin.
     */
    private static function adapter(): DatabaseAdapterInterface
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
                // exactly what the binding would have produced — falling through
                // keeps a migration runnable in a context with no container.
            }
        }

        return new \Framework\Database\Adapters\WpDatabaseAdapter();
    }

    /**
     * The callable a blueprint uses to spell a referenced table name.
     *
     * WordPress prefixes every table — its own core tables included — with
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
