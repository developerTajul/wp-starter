<?php
declare(strict_types=1);

namespace Framework\Database\Adapters;

use Framework\Database\Schema\Exceptions\SchemaException;
use Throwable;
use wpdb;

/**
 * {@see DatabaseAdapterInterface} for WordPress, over `$wpdb` and `dbDelta()`.
 *
 * ## Why dbDelta() is used only for CREATE TABLE
 *
 * `dbDelta()` is WordPress's schema-diffing helper: it parses a `CREATE TABLE`
 * statement, compares it against the live table, and issues whatever `ALTER`s
 * are needed to make the live table match. Applied to the right statement it is
 * a real feature, and this adapter leans on it for exactly one case — a
 * migration that creates a table, so a re-run against a table that partly exists
 * converges instead of failing on the first duplicate.
 *
 * It is never used for anything else, because it can only compare tables the
 * statement *creates*. A column being added to an existing table is not
 * mentioned by any `CREATE TABLE`, so a `dbDelta()` over an `ALTER` is not a
 * diff at all: it either does nothing or, worse, re-asserts an older shape. That
 * is why {@see statement()} issues plain SQL and `dbDelta()` is reached only
 * through {@see createTable()}.
 *
 * ## Why the CREATE is rewritten before dbDelta() sees it
 *
 * `dbDelta()` is line-oriented, and its parser has rules that a `CREATE TABLE`
 * which is valid MySQL can still violate:
 *
 *  - the table name must not be backtick-quoted, and the statement must end in
 *    a semicolon;
 *  - the `PRIMARY KEY` and each `KEY` must be on their own line;
 *  - there must be exactly **two** spaces between `PRIMARY KEY` and its
 *    parenthesised column list, and the key's own name must be written out
 *    (`PRIMARY KEY (id)`, not MySQL's equally valid `PRIMARY KEY(id)`).
 *
 * A statement that breaks one of these is not rejected — it is *misparsed*, so
 * the table is created without the key, and the failure surfaces later as
 * duplicated rows rather than as an error. {@see toDbDeltaStatement()} therefore
 * normalises the statement rather than trusting the caller to have written it
 * in dbDelta's dialect; the blueprint emits ordinary MySQL and this one place
 * converts.
 *
 * ## Why upgrade.php is required, not assumed
 *
 * `dbDelta()` lives in `wp-admin/includes/upgrade.php`, which WordPress loads
 * for admin requests and does not load under WP-CLI's default bootstrap or
 * during a cron run. Requiring it here — guarded, once per process — is what
 * makes `wp <prefix> migrate` work at all, and it is the single most common
 * reason a hand-written migration works in the admin and not from the CLI.
 */
class WpDatabaseAdapter implements DatabaseAdapterInterface
{
    /**
     * The longest identifier MySQL accepts, which is what a prefixed table name
     * must fit inside.
     */
    private const MAX_IDENTIFIER_LENGTH = 64;

    private ?wpdb $wpdb;

    /**
     * @param wpdb|null $wpdb WordPress database handle. Defaults to the `$wpdb`
     *                        global, resolved lazily so constructing the adapter
     *                        during a provider's `register()` — before
     *                        WordPress has finished loading — does not fail.
     */
    public function __construct(?wpdb $wpdb = null)
    {
        $this->wpdb = $wpdb;
    }

    public function prefix(): string
    {
        return $this->db()->prefix;
    }

    /**
     * The table name as it exists in the database.
     *
     * WordPress prefixes every table — its own core tables included — with
     * `$wpdb->prefix`, whatever it is. On a site whose `$table_prefix` is
     * `leadbridge_`, the core user table is `leadbridge_users`; a foreign key
     * written as `REFERENCES users` points at a table that does not exist and
     * fails at `CREATE TABLE`. So the prefix is prepended unconditionally, and
     * only skipped when the caller has already spelled the qualified name
     * (which protects `constrained()` against double-prefixing).
     */
    public function tableName(string $table): string
    {
        $prefix = $this->db()->prefix;

        // An empty prefix is a real configuration (`$table_prefix = ''`), and
        // `str_starts_with($any, '')` is always true — testing it would report
        // every name as already qualified. The result is the same either way,
        // but the intent is worth spelling out.
        if ($prefix === '') {
            return $table;
        }

        return str_starts_with($table, $prefix) ? $table : $prefix . $table;
    }

    /**
     * Whether a table is present in the database.
     *
     * Needed by {@see \Framework\Database\Schema\Schema::hasTable()}, and
     * deliberately offered as a real probe rather than as "try it and catch":
     * a migration that asks whether a table exists is usually deciding whether
     * to create it, and a statement that failed for a *different* reason —
     * permissions, a dropped connection — must not be read as an absence.
     *
     * The pattern is escaped before it reaches `LIKE`, because `_` and `%` are
     * metacharacters and WordPress's own table prefix is full of underscores:
     * an unescaped `wp_` matches `wpx`, and a missing table would be reported as
     * present.
     */
    public function hasTable(string $table): bool
    {
        $db = $this->db();
        $qualified = $this->qualify($table);

        $found = $db->get_var($db->prepare(
            'SHOW TABLES LIKE %s',
            str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $qualified)
        ));

        return $found !== null && $found !== '';
    }

    public function statement(string $sql, string $operation): void
    {
        $db = $this->db();

        // WordPress 6.7+ can roll back the next failing statement, which cleans
        // up a partially-written DDL. Probed rather than called outright: the
        // plugin's floor is WordPress 6.0, where calling it is a fatal error.
        if (method_exists($db, 'set_transaction_rollback')) {
            $db->set_transaction_rollback(true);
        }

        // A DDL statement returns `false` for "the statement failed", never for
        // "zero rows affected" — which is what makes this check meaningful here
        // in a way it is not for a DELETE.
        if ($db->query($sql) === false) {
            throw SchemaException::statementFailed($operation, $this->reason($db));
        }
    }

    public function createTable(string $table, string $sql): void
    {
        $db = $this->db();

        // Guarded rather than checked with function_exists() after the fact:
        // `require_once` is idempotent, so this costs one stat() per create and
        // cannot race two concurrent migrations into a double declaration.
        if (!function_exists('dbDelta')) {
            $upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';

            if (!is_file($upgrade)) {
                throw new SchemaException(sprintf(
                    'Cannot create the [%s] table: dbDelta() is not available and [%s] does not exist. '
                    . 'This usually means ABSPATH points somewhere other than a WordPress root.',
                    $table,
                    $upgrade
                ));
            }

            require_once $upgrade;
        }

        if (method_exists($db, 'set_transaction_rollback')) {
            $db->set_transaction_rollback(true);
        }

        $before = $db->last_error;

        // Foreign keys are taken out of the CREATE and applied afterwards.
        //
        // dbDelta() has no concept of a constraint: its field parser treats a
        // line beginning `CONSTRAINT` as a *column* named `CONSTRAINT`, because
        // only primary/index/unique/fulltext/spatial are in its list of things
        // that are not columns. On a table it has not seen before that is
        // harmless — the original CREATE is executed verbatim — but on the
        // second run of the same statement it diffs that phantom column and
        // emits `ALTER TABLE ... ADD COLUMN CONSTRAINT ... FOREIGN KEY ...`,
        // which is a syntax error. The result is a migration that succeeds once
        // and can never be re-run.
        [$create, $constraints] = $this->separateConstraints($sql);

        // `dbDelta()` returns an array of the statements it ran. It does not
        // throw and does not set `last_error` on a parse failure, so a
        // non-array return is the only structural failure it reports.
        $result = dbDelta($this->toDbDeltaStatement($create));

        if (!is_array($result)) {
            throw SchemaException::statementFailed(
                sprintf('create the [%s] table', $table),
                'dbDelta() returned no result, which happens when it is called before '
                . 'wp-admin/includes/upgrade.php has been loaded.'
            );
        }

        // A failed statement inside dbDelta() surfaces here and nowhere else: it
        // swallows the query's return value. Re-reading `last_error` and
        // comparing it against what it was before the call is what turns a
        // silently-empty diff into a reported failure.
        if ($db->last_error !== $before && $db->last_error !== '') {
            throw SchemaException::statementFailed(
                sprintf('create the [%s] table', $table),
                $db->last_error
            );
        }

        // Only after the table exists — a constraint cannot name a table that
        // has not been created yet.
        $this->applyConstraints($table, $constraints);
    }

    public function dropTableIfExists(string $table): void
    {
        $this->statement(
            sprintf('DROP TABLE IF EXISTS `%s`', $this->escape($this->qualify($table))),
            sprintf('drop the [%s] table', $table)
        );
    }

    public function charsetCollation(): string
    {
        $db = $this->db();

        // Nothing is invented when the handle cannot answer: an empty clause is
        // omitted by the builder, so the table inherits the database default
        // rather than being forced onto a collation the host did not choose.
        if (!method_exists($db, 'get_charset_collate')) {
            return '';
        }

        return (string) $db->get_charset_collate();
    }

    // ---------------------------------------------------------------- internals

    /**
     * Split a `CREATE TABLE` into the part dbDelta() can parse and the foreign
     * key clauses it cannot.
     *
     * Every top-level `CONSTRAINT ...` item is removed from the column list and
     * returned separately; when there are none the statement is returned
     * unchanged, so a table without foreign keys still costs one pass and no
     * rebuild.
     *
     * The split is paren- and backtick-aware rather than a `explode(',')`
     * because a constraint's own column list contains commas, and a default
     * value could contain one too. Splitting naively would cut a clause in half
     * and hand dbDelta a statement it misparses in a way that is far harder to
     * read than this method is.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function separateConstraints(string $sql): array
    {
        $open = strpos($sql, '(');

        if ($open === false) {
            return [$sql, []];
        }

        // The matching close of the column list, counting nested parentheses.
        $depth = 0;
        $close = null;
        $length = strlen($sql);

        for ($i = $open; $i < $length; $i++) {
            if ($sql[$i] === '(') {
                $depth++;
            } elseif ($sql[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    $close = $i;
                    break;
                }
            }
        }

        if ($close === null) {
            return [$sql, []];
        }

        $constraints = [];
        $kept = [];

        foreach ($this->splitTopLevel(substr($sql, $open + 1, $close - $open - 1)) as $item) {
            if (preg_match('/^\s*CONSTRAINT\s/i', $item) === 1) {
                $constraints[] = trim($item);

                continue;
            }

            $kept[] = $item;
        }

        if ($constraints === []) {
            return [$sql, []];
        }

        return [
            substr($sql, 0, $open + 1) . implode(',', $kept) . substr($sql, $close),
            $constraints,
        ];
    }

    /**
     * Split a comma-separated list on the commas that separate its items.
     *
     * A comma inside parentheses (a key's column list) or inside a quoted
     * default value is part of the item, not a separator.
     *
     * @return list<string>
     */
    private function splitTopLevel(string $body): array
    {
        $items = [];
        $current = '';
        $depth = 0;
        $backtick = false;
        $quote = false;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($backtick) {
                $current .= $char;

                if ($char === '`') {
                    $backtick = false;
                }

                continue;
            }

            if ($quote) {
                $current .= $char;

                if ($char === "'") {
                    // `''` is an escaped quote inside a string literal, not the
                    // end of one.
                    if ($i + 1 < $length && $body[$i + 1] === "'") {
                        $current .= "'";
                        $i++;

                        continue;
                    }

                    $quote = false;
                }

                continue;
            }

            if ($char === '`') {
                $backtick = true;
                $current .= $char;

                continue;
            }

            if ($char === "'") {
                $quote = true;
                $current .= $char;

                continue;
            }

            if ($char === '(') {
                $depth++;
                $current .= $char;

                continue;
            }

            if ($char === ')') {
                $depth--;
                $current .= $char;

                continue;
            }

            if ($char === ',' && $depth === 0) {
                $items[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $items[] = $current;
        }

        return $items;
    }

    /**
     * Apply each foreign key the CREATE could not carry.
     *
     * Idempotent, because dbDelta() is: a `Schema::create()` whose table already
     * exists with the constraint in place must not fail, and a rerun after a
     * part-finished create must still add the keys it is missing. The
     * constraint registry is asked rather than MySQL's error text, which differs
     * between servers and between languages.
     *
     * @param list<string> $constraints
     */
    private function applyConstraints(string $table, array $constraints): void
    {
        if ($constraints === []) {
            return;
        }

        $db = $this->db();
        $qualified = $this->qualify($table);

        foreach ($constraints as $constraint) {
            if (preg_match('/^\s*CONSTRAINT\s+`([^`]+)`/i', $constraint, $m) === 1
                && $this->constraintExists($db, $qualified, $m[1])
            ) {
                continue;
            }

            $this->statement(
                sprintf('ALTER TABLE `%s` ADD %s', $this->escape($qualified), $constraint),
                sprintf('add a foreign key to the [%s] table', $table)
            );
        }
    }

    /**
     * Whether a constraint of this name is already defined on the table.
     */
    private function constraintExists(wpdb $db, string $table, string $name): bool
    {
        $found = $db->get_var($db->prepare(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s',
            $table,
            $name
        ));

        return $found !== null && $found !== '';
    }

    /**
     * Rewrite a `CREATE TABLE` into the dialect `dbDelta()` parses.
     *
     * The three transforms below are each a rule of dbDelta's parser rather than
     * a preference — see the class docblock. They are applied to the statement
     * *text*, which is safe here only because every identifier in it was already
     * validated as `[A-Za-z0-9_$]+` before it was assembled; a table name
     * containing a backtick could otherwise have a line injected into the body.
     */
    private function toDbDeltaStatement(string $sql): string
    {
        // 1. Unquote the table name. dbDelta() matches `CREATE TABLE <name>`,
        //    so a quoted one is not recognised as a CREATE at all and the
        //    statement is dropped without comment.
        $sql = preg_replace('/^(CREATE TABLE)\s+`([A-Za-z0-9_$]+)`/i', '$1 $2', $sql) ?? $sql;

        // 2. `PRIMARY KEY(` becomes `PRIMARY KEY  (` — two spaces, which is the
        //    literal dbDelta searches for. One space is read as a column named
        //    `PRIMARY`, which then fails as an unknown type.
        $sql = preg_replace('/PRIMARY KEY\s*\(/i', 'PRIMARY KEY  (', $sql) ?? $sql;

        // 3. Named keys are left named, deliberately.
        //
        //    An earlier version of this method stripped the name to reach the
        //    `KEY (col)` form, on the belief that dbDelta() only understood the
        //    unnamed spelling. It does not require it — its index regex makes the
        //    name optional — but it does *normalise* to whichever spelling it
        //    found, and an unnamed clause becomes `KEY `` (`col`)` with an empty
        //    name. That empty name can never equal the real `SHOW INDEX` name
        //    (`email`, say), so on the second and every later run of the same
        //    migration dbDelta() fails to recognise the index as present and
        //    re-issues `ADD` for it. A named clause round-trips exactly instead,
        //    and is also what `Schema::table()->dropIndex()` needs in order to
        //    name the index it is dropping.

        // 4. dbDelta() splits on the semicolon and expects it present.
        return rtrim(rtrim($sql), ';') . ';';
    }

    /**
     * The prefixed table name, validated so it can be interpolated safely.
     *
     * A table name cannot be bound as a parameter — `prepare()` quotes values,
     * and a quoted table name is a syntax error — so it is checked instead. The
     * name is built from `$wpdb->prefix` and a literal in every real call, so
     * this is belt-and-braces rather than a live injection path; it exists so no
     * caller has to reason about that, and so a prefix set to something odd in
     * `wp-config.php` fails loudly at the migration rather than at the database.
     */
    private function qualify(string $table): string
    {
        $qualified = $this->tableName($table);

        if (preg_match('/^[A-Za-z0-9_$]+$/', $qualified) !== 1) {
            throw SchemaException::invalidTableName($qualified);
        }

        if (strlen($qualified) > self::MAX_IDENTIFIER_LENGTH) {
            throw new SchemaException(sprintf(
                'The table name [%s] is %d characters, longer than the %d MySQL allows. '
                . 'Note the prefix counts toward it.',
                $qualified,
                strlen($qualified),
                self::MAX_IDENTIFIER_LENGTH
            ));
        }

        return $qualified;
    }

    /**
     * Make an identifier safe inside backticks.
     *
     * Reached only by {@see dropTableIfExists()}, whose statement is not passed
     * through the regex rewrites above — so doubling a backtick is correct and
     * sufficient here, where it would not be inside a dbDelta body.
     */
    private function escape(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }

    /**
     * The database's own explanation of the last failure, or a stand-in.
     *
     * Never returns an empty string: a failure reported with no reason reads as
     * "no error", and sends the reader to the database log to find out whether
     * the problem was a duplicate table or a dropped connection.
     */
    private function reason(wpdb $db): string
    {
        return $db->last_error !== ''
            ? $db->last_error
            : 'the database reported no error message';
    }

    /**
     * The WordPress database handle, resolved on first use.
     *
     * Lazy rather than eager for the same reason the migrator's is: an adapter
     * is constructed while a provider registers, which runs before the global
     * `$wpdb` is guaranteed to be the one this process will use.
     */
    private function db(): wpdb
    {
        if ($this->wpdb instanceof wpdb) {
            return $this->wpdb;
        }

        if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof wpdb) {
            throw new SchemaException(
                'No $wpdb instance is available. The WordPress schema adapter needs a database handle; '
                . 'pass one to the constructor when running outside a loaded WordPress.'
            );
        }

        return $this->wpdb = $GLOBALS['wpdb'];
    }
}
