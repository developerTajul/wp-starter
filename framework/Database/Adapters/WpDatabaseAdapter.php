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
     * Whether statements are being recorded rather than run.
     */
    private bool $pretending = false;

    /**
     * Statements issued while {@see pretend()} was on, in order.
     *
     * @var list<string>
     */
    private array $recorded = [];

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

    public function pretend(): void
    {
        $this->pretending = true;
    }

    public function stopPretending(): void
    {
        $this->pretending = false;
    }

    public function recordedStatements(): array
    {
        return $this->recorded;
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
            $this->escapeLike($qualified)
        ));

        return $found !== null && $found !== '';
    }

    /**
     * Whether a table carries a column of the given name.
     *
     * Read from `SHOW COLUMNS`, which is scoped to a table that must exist and
     * therefore answers "no such table" with an error rather than with an empty
     * result. That error is the reason {@see hasTable()} runs first: the contract
     * says an absent table is `false`, and asking `SHOW COLUMNS` about a table
     * that is not there would raise instead — turning the most ordinary use of
     * this probe (a migration guarding an `ADD COLUMN` on a table an earlier
     * migration in the same batch has just created) into a hard failure.
     *
     * `SHOW COLUMNS` over `information_schema.COLUMNS` for the same reason
     * `hasTable()` uses `SHOW TABLES`: `%s` binds the table name as a *value*,
     * which is what the table list expects, whereas `information_schema` wants
     * the bare name compared literally and a prefix carries no wildcards to
     * escape. The result is a list either way, so absence is "the list is empty".
     */
    public function hasColumn(string $table, string $column): bool
    {
        if (! $this->hasTable($table)) {
            return false;
        }

        $db = $this->db();

        $columns = $db->get_results($db->prepare(
            'SHOW COLUMNS FROM `' . $this->qualify($table) . '` LIKE %s',
            $this->escapeLike($column)
        ));

        return is_array($columns) && $columns !== [];
    }

    /**
     * Whether a table carries an index of the given name.
     *
     * Asked of `information_schema.STATISTICS` rather than `SHOW INDEX`, because
     * `SHOW INDEX` has no name filter — it returns every key on the table for the
     * caller to sift, and the sifting is exactly where a `_` in a name would be
     * taken for a wildcard. Matched with `=` here, so nothing is escaped and
     * nothing is interpreted: the comparison is the literal string.
     *
     * `TABLE_SCHEMA = DATABASE()` pins the lookup to the database the connection
     * is already on, which is the one `SHOW TABLES` reads. Without it the query
     * would answer `true` for an index that exists on a same-named table in some
     * *other* database on the server — and a `DROP`/`ADD` built on that answer
     * would run against an index this migration does not own.
     *
     * The table still has to exist for the question to mean anything, and it is
     * checked first for the same reason as in {@see hasColumn()}.
     *
     * Reports a `PRIMARY` key as present when asked for `PRIMARY`: MySQL records
     * the primary key in this table under that name, and no other key may take
     * it. Anything else is the name the index was declared with.
     */
    public function hasIndex(string $table, string $index): bool
    {
        if (! $this->hasTable($table)) {
            return false;
        }

        $db = $this->db();

        $found = $db->get_var($db->prepare(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
            $this->qualify($table),
            $index
        ));

        return $found !== null && $found !== '';
    }

    public function statement(string $sql, string $operation): void
    {
        if ($this->pretending) {
            $this->recorded[] = $sql;

            return;
        }

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
        // Recorded as the two statements a real run issues, not as the raw
        // blueprint output: the constraint lift-out below is the adapter's own
        // doing, and a `--pretend` that showed the CREATE with its foreign keys
        // still inside would be showing SQL that is never executed — dbDelta's
        // field parser would misread it as a column named CONSTRAINT.
        if ($this->pretending) {
            [$create, $constraints] = $this->separateConstraints($sql);

            $this->recorded[] = $this->toDbDeltaStatement($create);

            foreach ($constraints as $constraint) {
                $this->recorded[] = sprintf(
                    'ALTER TABLE `%s` ADD %s',
                    $this->escape($this->qualify($table)),
                    $constraint
                );
            }

            return;
        }

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
        //
        // The adapter announces itself for the duration of the call because
        // `dbDelta()` takes no connection argument and would otherwise reach for
        // the `$wpdb` global — a different connection, or none, when this adapter
        // was constructed with an explicit handle. See
        // {@see \Tests\Support\SchemaAdapterRegistry}. The class is a test
        // double's seam, so it is probed with `class_exists()` rather than
        // required: production has no such class and must not.
        $result = $this->throughDbDelta(fn () => dbDelta($this->toDbDeltaStatement($create)));

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

    /**
     * Rebuild a column's definition from what `SHOW COLUMNS` reports.
     *
     * `SHOW COLUMNS` returns the definition already decomposed — `Type` is
     * `varchar(191)`, `Null` is `YES`/`NO`, `Default` is the default or null,
     * `Extra` carries `auto_increment` — and this puts it back together in the
     * order MySQL's grammar requires after `CHANGE COLUMN`: type, then null,
     * then default, then extra. That order is not negotiable; MySQL rejects
     * `DEFAULT` before `NOT NULL`.
     *
     * Three details are load-bearing:
     *
     * - **`Default` is itself nullable, and the distinction matters.** A column
     *   with no default and a column whose default is `NULL` are different
     *   schemas, and `SHOW COLUMNS` reports both as null — with the column's
     *   nullability as the only discriminator. So `NULL DEFAULT NULL` is emitted
     *   only for a nullable column, and a `NOT NULL` column with no default gets
     *   no clause at all.
     * - **`Default` is unquoted by the server** for numeric and string defaults
     *   alike, so it is re-quoted as a string. A column defaulting to the string
     *   `0` and one defaulting to the number `0` are equivalent in MySQL, so
     *   quoting it is correct and does not change meaning. The exception is a
     *   default the server reports as a function — `CURRENT_TIMESTAMP` — which is
     *   emitted bare, since quoting it would compare a literal string against a
     *   timestamp column.
     * - **`Extra` is appended verbatim**, because it is already the syntax —
     *   `auto_increment`, `on update CURRENT_TIMESTAMP`, or empty.
     *
     * Returns null when there is no such column, leaving the caller to decide
     * whether that is an error.
     */
    public function columnDefinition(string $table, string $column): ?string
    {
        // No `hasColumn()` guard first: the row read below answers the same
        // question, so guarding would be a second round trip to learn nothing.
        // The distinction the contract cares about — absent versus failed — is
        // already carried by `SHOW COLUMNS` itself, which raises on a missing
        // table rather than quietly returning nothing.
        if (! $this->hasTable($table)) {
            return null;
        }

        $db = $this->db();

        $row = $db->get_row($db->prepare(
            'SHOW COLUMNS FROM `' . $this->qualify($table) . '` LIKE %s',
            $this->escapeLike($column)
        ), ARRAY_A);

        if (!is_array($row) || !isset($row['Type'])) {
            return null;
        }

        $definition = (string) $row['Type'];

        $nullable = strtoupper((string) ($row['Null'] ?? 'NO')) === 'YES';

        $definition .= $nullable ? ' NULL' : ' NOT NULL';

        // `array_key_exists`, not `isset`: a null default is a real value here,
        // and `isset` would report it absent and drop the clause.
        if (array_key_exists('Default', $row) && $row['Default'] !== null) {
            $default = (string) $row['Default'];

            $definition .= ' DEFAULT ' . ($this->isFunctionDefault($default)
                ? $default
                : "'" . str_replace("'", "''", $default) . "'");
        } elseif ($nullable) {
            $definition .= ' DEFAULT NULL';
        }

        $extra = trim((string) ($row['Extra'] ?? ''));

        if ($extra !== '') {
            $definition .= ' ' . $extra;
        }

        return $definition;
    }

    /**
     * Whether a default the server reported is a function call rather than a
     * literal.
     *
     * `CURRENT_TIMESTAMP` is the one that occurs in practice, usually with
     * `on update` in `Extra` beside it. It must not be quoted: `DEFAULT
     * 'CURRENT_TIMESTAMP'` is a string, and assigning a string to a timestamp
     * column is how a migration quietly zeroes every timestamp it meant to
     * preserve. Matched case-insensitively because the server's spelling varies
     * by version, and by the `CURRENT_TIMESTAMP(n)` precision form.
     */
    private function isFunctionDefault(string $default): bool
    {
        return preg_match('/^current_timestamp(\(\d*\))?$/i', $default) === 1;
    }

    /**
     * Rename a table, addressing both names through the prefix.
     *
     * `RENAME TABLE a TO b` rather than `ALTER TABLE a RENAME TO b`: the two are
     * equivalent for a single table and the former is what WordPress's own schema
     * migration has always used, so it is the spelling that reads as expected in
     * a `SHOW PROCESSLIST` or a slow query log on a WordPress host.
     *
     * MySQL raises "Table '...' doesn't exist" for a missing source, which is the
     * behaviour this wants — the statement is left to fail and the adapter
     * reports it, rather than pre-checking with {@see hasTable()} and inventing a
     * message. The check would be a TOCTOU gap for no gain: the failure is
     * already the right failure.
     */
    public function renameTable(string $from, string $to): void
    {
        $this->statement(
            sprintf(
                'RENAME TABLE `%s` TO `%s`',
                $this->escape($this->qualify($from)),
                $this->escape($this->qualify($to))
            ),
            sprintf('rename the [%s] table to [%s]', $from, $to)
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

    // ------------------------------------------------------------- introspection

    /**
     * Every table in the database, sorted.
     *
     * Read through `SHOW TABLES` rather than by listing the plugin's own tracking
     * table, because the point of the dumper is to report what is *there* —
     * including a table an earlier developer created by hand, which no migration
     * would mention.
     *
     * No `LIKE` narrowing: the caller decides what to dump, and a filter applied
     * here would silently hide the table someone is trying to find. The WordPress
     * core tables come back too, for the same reason — excluding them would be
     * this class deciding what is interesting, and a plugin that legitimately
     * extends a core table has no way to say so.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $db = $this->db();

        /** @var list<string>|null $names */
        $names = $db->get_col('SHOW TABLES', 0);

        $tables = array_map(static fn (mixed $name): string => (string) $name, (array) $names);

        sort($tables);

        return array_values($tables);
    }

    /**
     * A table's columns in declaration order, with the attributes a dump needs.
     *
     * `SHOW COLUMNS` is the source, and each row's four fields map to one
     * blueprint concern: `Type` chooses the builder method, `Null` decides
     * `nullable()`, `Default` decides `default()`/`useCurrent()`, and `Extra`
     * carries the `auto_increment` marker that identifies a primary key the
     * builder spells `id()`.
     *
     * Returns an empty list for a table that does not exist, which the dumper
     * reports rather than turning into a column-less `create`.
     *
     * @return list<array{name: string, type: string, nullable: bool, default: string|null, autoIncrement: bool}>
     */
    public function columnDetails(string $table): array
    {
        $db = $this->db();
        $qualified = $this->qualify($table);

        /** @var list<array<string, mixed>>|null $rows */
        $rows = $db->get_results(sprintf('SHOW COLUMNS FROM `%s`', $this->escape($qualified)), ARRAY_A);

        $columns = [];

        foreach ((array) $rows as $row) {
            $columns[] = [
                'name' => (string) ($row['Field'] ?? ''),
                'type' => strtolower((string) ($row['Type'] ?? '')),
                'nullable' => strtoupper((string) ($row['Null'] ?? '')) === 'YES',
                'default' => $this->normaliseDefault($row['Default'] ?? null),
                'autoIncrement' => stripos((string) ($row['Extra'] ?? ''), 'auto_increment') !== false,
            ];
        }

        return $columns;
    }

    /**
     * A table's keys, primary first, each with its columns in index order.
     *
     * `SHOW INDEX` returns one row per column *per key*, so a composite index
     * arrives as several rows that have to be gathered by name and ordered by
     * `Seq_in_index`. Reading it as a flat list would report a two-column index
     * as two one-column indexes — which compiles, and produces the wrong schema.
     *
     * The primary key is included under `PRIMARY` rather than filtered out: a
     * caller has to be able to see that a table has one, and it is the caller
     * that decides not to emit it as an `index()`.
     *
     * @return list<array{name: string, columns: list<string>, unique: bool, primary: bool}>
     */
    public function indexDetails(string $table): array
    {
        $db = $this->db();
        $qualified = $this->qualify($table);

        /** @var list<array<string, mixed>>|null $rows */
        $rows = $db->get_results(sprintf('SHOW INDEX FROM `%s`', $this->escape($qualified)), ARRAY_A);

        /** @var array<string, array{columns: array<int, string>, unique: bool}> $gathered */
        $gathered = [];

        foreach ((array) $rows as $row) {
            $name = (string) ($row['Key_name'] ?? $row['Index_name'] ?? '');

            if ($name === '') {
                continue;
            }

            $position = (int) ($row['Seq_in_index'] ?? 0);
            $column = (string) ($row['Column_name'] ?? '');

            $gathered[$name]['columns'][$position] = $column;

            // `Non_unique` is 0 for a unique index — the field is named for the
            // opposite of what a reader wants, so it is inverted once, here.
            $gathered[$name]['unique'] = (string) ($row['Non_unique'] ?? '1') === '0';
        }

        $indexes = [];

        foreach ($gathered as $name => $details) {
            // Sorted by sequence rather than trusted from row order, because a
            // composite key's column order is part of its definition: the same
            // columns in the other order are a different index, and only one of
            // them serves the query the migration was written for.
            $columns = $details['columns'];
            ksort($columns);

            $indexes[] = [
                'name' => $name,
                'columns' => array_values($columns),
                'unique' => $details['unique'] ?? false,
                'primary' => strtoupper($name) === 'PRIMARY',
            ];
        }

        // Primary first, then by name, so a dump is stable between runs.
        usort($indexes, static function (array $a, array $b): int {
            if ($a['primary'] !== $b['primary']) {
                return $a['primary'] ? -1 : 1;
            }

            return strcmp($a['name'], $b['name']);
        });

        return $indexes;
    }

    /**
     * A `SHOW COLUMNS` default as the schema language spells it.
     *
     * `NULL` — the database's "no default" — has to become PHP `null` so the
     * dumper can tell it apart from the *string* `'NULL'`, which is a legitimate
     * default for a nullable column and would otherwise be emitted as no default
     * at all. The two are different schemas and only one of them is what the
     * database holds.
     */
    private function normaliseDefault(mixed $default): ?string
    {
        if ($default === null) {
            return null;
        }

        return (string) $default;
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
     * Neutralise the `LIKE` metacharacters in a string bound to a pattern.
     *
     * WordPress's table prefix is nearly always `wp_`, and `_` in a `LIKE` pattern
     * matches any single character — so an unescaped `wp_notes` also matches
     * `wpXnotes`, and a probe asked about the first can be answered by the second.
     * `%` matching any run is the same hazard with a wider reach.
     *
     * The backslash is escaped *first*, necessarily: done after, it would double
     * the backslashes this method had just added and unescape the metacharacters
     * again.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $value);
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

    /**
     * Run a callback with this adapter visible to `dbDelta()`.
     *
     * `dbDelta()` is WordPress's and takes no connection argument, so the only
     * way to tell it which handle to write to is to announce it around the call.
     * In a real WordPress the announcement is unnecessary — the global is the
     * connection — and there is no registry class to announce to, which is why
     * this is a `class_exists()` probe rather than a hard dependency. The
     * production path is the `return $callback();` at the end.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function throughDbDelta(callable $callback): mixed
    {
        $registry = 'Tests\Support\SchemaAdapterRegistry';

        if (!class_exists($registry)) {
            return $callback();
        }

        return $registry::with($this, $callback);
    }

    /**
     * The database handle this adapter is using, resolved.
     *
     * Exists because `dbDelta()` is a global function that takes no connection
     * argument — it reaches for the `$wpdb` global itself, which is right inside
     * WordPress and wrong anywhere the adapter was handed a handle directly. A
     * caller that constructs this adapter with an explicit `$wpdb` (every test,
     * and `MigrateDumpCommand` in production) would otherwise have `dbDelta()`
     * operate on a *different* connection than the one the adapter just compiled
     * the statement for, or on none at all.
     *
     * The framework does not read this; it is the seam a `dbDelta()` replacement
     * uses to find the connection the statement was meant for.
     */
    public function connection(): ?wpdb
    {
        if ($this->wpdb instanceof wpdb) {
            return $this->wpdb;
        }

        return isset($GLOBALS['wpdb']) && $GLOBALS['wpdb'] instanceof wpdb ? $GLOBALS['wpdb'] : null;
    }
}
