<?php
declare(strict_types=1);

namespace Tests\Support;

use Framework\Database\Adapters\WpDatabaseAdapter;
use wpdb;

/**
 * The adapter whose `createTable()` is currently on the stack.
 *
 * ## Why this exists
 *
 * `dbDelta()` is a WordPress global function and takes no connection argument:
 * the real one reaches for the `$wpdb` global. The stub in
 * `tests/stubs/wordpress.php` did the same, and that made it silently do nothing
 * in every test — the adapter is constructed with an explicit handle
 * (`new WpDatabaseAdapter($db)`) and never publishes it to the global, so the
 * stub's `isset($GLOBALS['wpdb'])` guard failed, the loop `continue`d, and the
 * migration carried on. The table was never created, the migration was recorded
 * as applied, and the command printed "Migrated 1 migration(s)."
 *
 * That is the exact false green the stub's own docblock says it exists to
 * prevent, so the guard is not simply relaxed: the stub asks this registry first
 * and only falls back to the global.
 *
 * ## Why a registry rather than a parameter
 *
 * `dbDelta()`'s signature is WordPress's and cannot grow a connection argument;
 * the adapter has to call the same global the rest of the ecosystem calls. So the
 * adapter announces itself for the duration of the call and the stub reads the
 * announcement. It is set in a `finally`, so a throwing `dbDelta()` cannot leave
 * a stale adapter visible to the next test.
 */
final class SchemaAdapterRegistry
{
    /**
     * Stacked, not a single slot: `dbDelta()` can be reached re-entrantly if a
     * hook it fires creates a table, and popping must restore the outer adapter
     * rather than clear the slot.
     *
     * @var list<WpDatabaseAdapter>
     */
    private static array $stack = [];

    /**
     * Announce an adapter for the duration of a callback, then restore.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function with(WpDatabaseAdapter $adapter, callable $callback): mixed
    {
        self::$stack[] = $adapter;

        try {
            return $callback();
        } finally {
            array_pop(self::$stack);
        }
    }

    /**
     * The connection `dbDelta()` should write to: the in-flight adapter's handle
     * if there is one, and otherwise whatever the `$wpdb` global holds.
     *
     * Returning the global as a fallback keeps this stub usable from a test that
     * genuinely is running against a global — the framework's own bootstrap, or a
     * future integration test against a real WordPress.
     */
    public static function connection(): ?wpdb
    {
        $adapter = self::$stack[count(self::$stack) - 1] ?? null;

        if ($adapter instanceof WpDatabaseAdapter) {
            return $adapter->connection();
        }

        return isset($GLOBALS['wpdb']) && $GLOBALS['wpdb'] instanceof wpdb ? $GLOBALS['wpdb'] : null;
    }

    /**
     * Clear the stack.
     *
     * Called from a test's `tearDown()` as a belt-and-braces measure: `with()`
     * already unwinds in a `finally`, but a fatal error inside the callback can
     * skip that, and a leaked adapter would make the *next* test's `dbDelta()`
     * write into the previous test's database.
     */
    public static function reset(): void
    {
        self::$stack = [];
    }
}
