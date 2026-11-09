<?php
declare(strict_types=1);

namespace Tests\Support;

use wpdb;

/**
 * An in-memory stand-in for WordPress's `$wpdb`.
 *
 * This is not a mock: it is a small SQL engine covering exactly the statements
 * the migrator and the note repository issue, backed by real arrays. The
 * difference matters, because the behaviour under test is largely *how the
 * callers interpret wpdb's return values* — and those are the semantics easiest
 * to get wrong. Each one below is copied from `wp-includes/class-wpdb.php`
 * rather than invented:
 *
 *  - `query()` returns `false` on error, `$rows_affected` for INSERT/UPDATE/
 *    DELETE, the raw result for CREATE/ALTER/TRUNCATE/DROP, and a row count for
 *    SELECT (class-wpdb.php:2308-2333). A test that makes a DELETE return 0 rows
 *    and a CREATE return 0 rows behave differently is testing what the migrator
 *    actually does.
 *  - `insert()` returns `false` on failure and does not throw (:2586-2617), and
 *    `update()` reports the rows changed or `false` (:2683-2730).
 *  - `get_var()` returns null when there is no result, so `MAX(batch)` over an
 *    empty table is null and not 0 — which is what makes "no batch" expressible.
 *  - `get_results()` returns an empty array for no rows and on error (:3179).
 *  - `get_row()` returns null when there is no row (:3070-3090), which is what
 *    the repository has to distinguish from a failed query via `last_error`.
 *  - `last_error` is a public string, empty when nothing has gone wrong.
 *
 * Failures are injected by SQL fragment through {@see failOn()}, so a test can
 * make one specific statement fail and assert that the migrator stopped there.
 *
 * Queries are a hand-written subset. If a caller grows a statement this class
 * does not understand, `query()` throws rather than silently returning 0 — a
 * silent 0 would look like a passing test. The suite's SQL surface is kept
 * deliberately narrow: equality conjunctions only (see
 * {@see compileEqualityPredicate()}), which is what both callers issue.
 */
final class FakeWpdb extends wpdb
{
    public string $prefix = 'wp_';

    public string $last_error = '';

    public int $insert_id = 0;

    public int $rows_affected = 0;

    /**
     * Tables, as table => list of rows, with rows as column => value.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $tables = [];

    /**
     * Auto-increment counters, as table => next id.
     *
     * @var array<string, int>
     */
    private array $autoIncrement = [];

    /**
     * SQL fragments that should make a statement fail.
     *
     * @var list<string>
     */
    private array $failOn = [];

    /**
     * The error to report per injected fragment, keyed by the fragment.
     *
     * A fragment with no entry here reports the generic `'Injected failure.'`.
     * Stored per fragment rather than as one current message so that injecting
     * two failures in one test — each expecting a different translation — cannot
     * have them overwrite each other. See {@see failOn()}.
     *
     * @var array<string, string>
     */
    private array $failureMessages = [];

    /**
     * Every statement issued, in order. Lets a test assert that, say, no DELETE
     * ran after a failure.
     *
     * @var list<string>
     */
    public array $queries = [];

    /**
     * The tables the statement being evaluated may reference, prefixed.
     *
     * Set by {@see selectRows()} from its own `FROM`/`JOIN` clauses before any
     * clause is evaluated, and read by {@see assertQualifierIsInScope()} so a
     * qualifier naming a table the statement never mentioned fails the way MySQL
     * fails it (error 1054) instead of quietly resolving to nothing.
     *
     * @var list<string>
     */
    private array $tablesInScope = [];

    /** Which clause is being evaluated, for the 1054 message. */
    private string $clauseInScope = 'field list';

    /**
     * What `GET_LOCK()` answers, as a string.
     *
     * MySQL's contract is three-valued and all three matter: `'1'` the lock was
     * taken, `'0'` it timed out because another connection holds it, `NULL` the
     * request itself errored. The default is `'1'` so the common case — a single
     * process, no contention — needs no setup, and a test that wants the contended
     * case sets `'0'`.
     *
     * A string rather than an int because that is what `$wpdb->get_var()` returns:
     * everything from the driver arrives as text, and a fake that handed back an
     * int would let `=== 1` pass here and fail in production.
     */
    public ?string $lockResult = '1';

    /**
     * Lock names currently held, so a test can assert the lock was given back.
     *
     * @var list<string>
     */
    public array $heldLocks = [];

    /**
     * Lock name requested by the most recent `GET_LOCK()`, or null.
     */
    public ?string $lastLockName = null;

    /**
     * Lock name passed to the most recent `RELEASE_LOCK()`, or null.
     */
    public ?string $lastReleasedLock = null;

    /**
     * Forget the recorded queries without touching the tables.
     *
     * Lets a test assert on only the statements one operation issued, rather than
     * on everything the setup did before it.
     */
    public function resetQueries(): void
    {
        $this->queries = [];
    }

    public function __construct()
    {
        // Deliberately does not call parent::__construct(): the real wpdb
        // constructor connects to MySQL. Nothing in this class touches a
        // connection, and calling it would make the suite require a database.
    }

    /**
     * Make any statement containing $fragment fail, as the database would.
     *
     * ## Why the message can be chosen
     *
     * The default `'Injected failure.'` is right for a test that only wants the
     * statement to fail — it is obviously artificial, so nobody mistakes it for a
     * real error. It is wrong for a test verifying how a *specific* error is
     * translated: `$wpdb->insert()` has no exception channel, so `last_error` is
     * the only way the message reaches the caller, and a fixed string cannot
     * exercise a branch that keys on MySQL's wording. A unique-key violation is
     * the case that matters, because the repositories translate it into a domain
     * exception.
     *
     * Passing `$message` sets `last_error` to it and leaves it in place: the
     * injection sites assign the default but never overwrite a message that was
     * already set for the statement, so a chosen message survives the statement
     * that triggers it.
     */
    public function failOn(string $fragment, ?string $message = null): void
    {
        $this->failOn[] = $fragment;

        if ($message !== null) {
            $this->failureMessages[$fragment] = $message;
        }
    }

    /**
     * The message to report for `$sql` when it is made to fail.
     *
     * The first matching fragment wins, mirroring `shouldFail()`, so a test that
     * injected two fragments gets the message belonging to the one that matched
     * rather than an arbitrary one.
     */
    private function injectedMessage(string $sql): string
    {
        foreach ($this->failOn as $fragment) {
            if (str_contains($sql, $fragment)) {
                return $this->failureMessages[$fragment] ?? 'Injected failure.';
            }
        }

        return 'Injected failure.';
    }

    /**
     * Create a table so the migrator's existence probe finds it.
     *
     * @param list<string> $columns
     */
    public function createTable(string $table, array $columns = ['id', 'migration', 'batch']): void
    {
        $this->tables[$table] = [];
        $this->autoIncrement[$table] = 1;

        // Cleared, not left alone: a table that is dropped and re-created must
        // not inherit the indexes of its previous life, or a test that asserts
        // "this migration added no index" would pass on leftover state.
        $this->indexes[$table] = [];
        $this->indexColumns[$table] = [];
        $this->constraints[$table] = [];

        // The tracking-table shape the migrator creates by hand always has an
        // auto-increment `id` primary key; a table seeded through `createTable()`
        // is given the same, since that is what a table created by `id()` has and
        // a dumper that saw no primary key would emit one that is not there.
        $this->primaryKeys[$table] = ['name' => 'PRIMARY', 'columns' => ['id']];

        $this->columnCache[$table] = $columns;

        // Every column gets a plausible definition the moment the table is made,
        // because `columnDefinition()` reads this and `renameColumn()` compiles a
        // `CHANGE COLUMN` from it. Inventing a `varchar(191) NULL` here is the
        // honest stand-in: a real table seeded through a migration gets its type
        // from the CREATE, which this fake does not parse into metadata, and a
        // test that cares about a *specific* type sets it with setColumnMeta().
        $this->columnMeta[$table] = [];

        foreach ($columns as $column) {
            $this->columnMeta[$table][$column] = [
                'Type' => 'varchar(191)',
                'Null' => 'YES',
                'Default' => null,
                'Extra' => '',
            ];
        }
    }

    /**
     * Record a real definition for a column, as `SHOW COLUMNS` would report it.
     *
     * @param array{Type?: string, Null?: string, Default?: string|null, Extra?: string} $meta
     */
    public function setColumnMeta(string $table, string $column, array $meta): void
    {
        $this->columnMeta[$table][$column] = $meta + [
            'Type' => 'varchar(191)',
            'Null' => 'YES',
            'Default' => null,
            'Extra' => '',
        ];
    }

    /**
     * A column's `SHOW COLUMNS` metadata, as the database currently holds it.
     *
     * The read side of {@see setColumnMeta()}, and what a test asserting on a
     * *compiled* column definition goes through: reading it back out of the fake
     * means the assertion is on what the adapter emitted rather than on what the
     * blueprint intended.
     *
     * @return array{Type: string, Null: string, Default: string|null, Extra: string}
     */
    public function columnMeta(string $table, string $column): array
    {
        return $this->columnMeta[$table][$column] ?? [
            'Type' => '',
            'Null' => 'YES',
            'Default' => null,
            'Extra' => '',
        ];
    }

    /**
     * Seed a row, bypassing auto-increment. Used to set up "already migrated".
     *
     * @param array<string, mixed> $row
     */
    public function seed(string $table, array $row): void
    {
        if (!isset($this->tables[$table])) {
            $this->createTable($table);
        }

        $row['id'] = $row['id'] ?? $this->autoIncrement[$table]++;
        $this->tables[$table][] = $row;
    }

    /**
     * Every row in a table.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $table): array
    {
        return $this->tables[$table] ?? [];
    }

    public function tableExists(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    /**
     * Columns known per table, populated by CREATE TABLE and by createTable().
     *
     * @var array<string, list<string>>
     */
    private array $columnCache = [];

    /**
     * Full `SHOW COLUMNS` metadata per table, as column => row.
     *
     * Separate from `$columnCache`, which holds bare names: four places read that
     * one to answer "does this column exist", and widening its values to arrays
     * would break each of them to serve one caller. This holds what
     * `columnDefinition()` needs — `Type`, `Null`, `Default`, `Extra` — so a
     * `CHANGE COLUMN` has a real definition to restate.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $columnMeta = [];

    /**
     * Indexes per table, as index name => whether it is UNIQUE.
     *
     * Populated by an `ALTER TABLE ... ADD KEY`, so a test can assert that a
     * `unique()` replaced the plain index rather than being added beside it —
     * which is the property the company-name migration exists to establish and
     * the one a naive "did the statement run" assertion would miss.
     *
     * @var array<string, array<string, bool>>
     */
    private array $indexes = [];

    /**
     * Foreign key constraint names per table.
     *
     * @var array<string, array<string, true>>
     */
    private array $constraints = [];

    /**
     * `SHOW INDEX` rows per table, as index name => list of column rows.
     *
     * Separate from `$indexes`, which records only *whether* a key exists and
     * whether it is unique. A dumper needs the columns and their order — a
     * composite index dumped as its constituent single-column indexes is two
     * indexes where the database has one — and widening `$indexes` would break
     * every `hasIndex()` caller to serve one reader.
     *
     * @var array<string, array<string, list<array<string, string>>>>
     */
    private array $indexColumns = [];

    /**
     * The primary key per table, as `['name' => 'PRIMARY', 'columns' => [...]]`.
     *
     * Kept apart from `$indexColumns` because MySQL treats it apart: it is
     * reported by `SHOW INDEX` but is not a `KEY` clause in the schema builder's
     * vocabulary — a dumper that emitted it as `->index()` would create a second,
     * duplicate key. The dumper asks about it through `columns()` and `Extra`
     * instead, so this exists mainly to keep it *out* of the plain-index read.
     *
     * @var array<string, array{name: string, columns: list<string>}>
     */
    private array $primaryKeys = [];

    /**
     * Whether a table carries an index of the given name.
     */
    public function hasIndex(string $table, string $index): bool
    {
        return isset($this->indexes[$table][$index]);
    }

    /**
     * Register an index as the database would hold it, for dumper tests.
     *
     * The write-side counterpart to {@see hasIndex()} and what a `SHOW INDEX`
     * read is answered from. Columns default to the index name, which is the
     * convention the blueprint uses when it derives a name — so a single-column
     * index can be written `addIndex('wp_notes', 'title')` and a composite one
     * has to name its columns, mirroring how the two differ in the schema.
     *
     * @param list<string>|null $columns
     */
    public function addIndex(string $table, string $index, bool $unique = false, ?array $columns = null): void
    {
        if (!isset($this->tables[$table])) {
            $this->createTable($table);
        }

        $this->indexes[$table][$index] = $unique;
        $this->indexColumns[$table][$index] = $this->indexRows($index, $columns ?? [$index], $unique);
    }

    /**
     * Build `SHOW INDEX` rows for one key.
     *
     * @param list<string> $columns
     * @return list<array<string, string>>
     */
    private function indexRows(string $name, array $columns, bool $unique): array
    {
        $rows = [];

        foreach (array_values($columns) as $position => $column) {
            $rows[] = [
                'Table' => '',
                'Non_unique' => $unique ? '0' : '1',
                'Key_name' => $name,
                'Seq_in_index' => (string) ($position + 1),
                'Column_name' => $column,
                'Index_name' => $name,
            ];
        }

        return $rows;
    }

    /**
     * The column names inside a `KEY name (\`a\`, \`b\`)` parenthesised list.
     *
     * @return list<string>
     */
    private function columnNamesIn(string $list): array
    {
        preg_match_all('/`([a-z0-9_]+)`/i', $list, $m);

        return $m[1];
    }

    /**
     * A table's columns, in the order the table holds them.
     *
     * Order, not just membership, because `after()` and `first()` are about
     * position — and a probe that returned a sorted or keyed list would report a
     * correctly-positioned column and a badly-positioned one identically.
     *
     * @return list<string>
     */
    public function columns(string $table): array
    {
        return $this->columnCache[$table] ?? [];
    }

    /**
     * Whether the named index is UNIQUE, or null when there is no such index.
     */
    public function indexIsUnique(string $table, string $index): ?bool
    {
        return $this->indexes[$table][$index] ?? null;
    }

    /**
     * Every index on a table, as name => whether it is UNIQUE.
     *
     * @return array<string, bool>
     */
    public function indexes(string $table): array
    {
        return $this->indexes[$table] ?? [];
    }

    /**
     * Every foreign key constraint name on a table.
     *
     * @return list<string>
     */
    public function constraints(string $table): array
    {
        return array_keys($this->constraints[$table] ?? []);
    }

    // ------------------------------------------------------------- wpdb surface

    /**
     * @param string|null $query
     * @return mixed
     */
    public function query($query)
    {
        $sql = (string) $query;
        $this->queries[] = $sql;
        $this->rows_affected = 0;

        if ($this->shouldFail($sql)) {
            $this->last_error = $this->injectedMessage($sql);
            $this->insert_id = 0;

            return false;
        }

        $this->last_error = '';

        // Mirrors class-wpdb.php:2308 — DDL returns the raw result, and for a
        // successful CREATE the migrator's `=== false` check must pass.
        if (preg_match('/^\s*(create|alter|truncate|drop|rename)\s/i', $sql) === 1) {
            return $this->runDdl($sql);
        }

        if (preg_match('/^\s*insert\s/i', $sql) === 1) {
            return $this->runInsert($sql);
        }

        if (preg_match('/^\s*delete\s/i', $sql) === 1) {
            return $this->runDelete($sql);
        }

        if (preg_match('/^\s*update\s/i', $sql) === 1) {
            return $this->runUpdate($sql);
        }

        if (preg_match('/^\s*(select|show)\s/i', $sql) === 1) {
            // Advisory-lock calls are answered here too, because `query()` is a
            // legitimate way to reach them and the table parser below would choke
            // on the function. `false` means "not a lock statement".
            $lock = $this->advisoryLockAnswer($sql);

            if ($lock !== false) {
                return $lock;
            }

            return count($this->select($sql));
        }

        throw new \LogicException(
            'FakeWpdb does not understand this statement, and returning a plausible number would '
            . 'make a broken migrator look correct: ' . $sql
        );
    }

    /**
     * @param string      $table
     * @param array<string, mixed> $data
     * @param array<int, string>|string|null $format
     * @return int|false
     */
    public function insert($table, $data, $format = null)
    {
        return $this->query($this->buildInsert($table, $data));
    }

    /**
     * @param string|null $query
     * @param int         $x
     * @return string|null
     */
    public function get_var($query = null, $x = 0, $y = 0)
    {
        if ($query === null) {
            return null;
        }

        $this->queries[] = (string) $query;

        // Injected failures are honoured on the read helpers too, because
        // `get_var()` is how a real `$wpdb` reports a failed aggregate: it
        // returns null *and* sets `last_error`. Without this, a caller that
        // distinguishes "no rows" from "the query broke" by reading
        // `last_error` — which is what every repository here does — could not be
        // tested on that branch at all, and the branch would ship unexercised.
        if ($this->shouldFail((string) $query)) {
            $this->last_error = $this->injectedMessage((string) $query);

            return null;
        }

        $this->last_error = '';

        // MySQL's advisory locks are scalar functions, so WordPress reaches them
        // through `get_var('SELECT GET_LOCK(...)')`. Answered before the SELECT
        // path below, which would otherwise parse them as a table query and
        // return null — reading as "the lock could not be taken" and making every
        // run refuse to proceed.
        $lock = $this->advisoryLockAnswer((string) $query);

        if ($lock !== false) {
            return $lock;
        }

        $rows = $this->select((string) $query);

        if ($rows === []) {
            return null;
        }

        $row = array_values($rows);

        // A single-column aggregate (MAX) has no column name to index by.
        $values = array_values($row[0]);

        return isset($values[$x]) ? (string) $values[$x] : null;
    }

    /**
     * @param string|null $query
     * @param string      $output
     * @param int         $y
     * @return array<string, mixed>|null
     */
    public function get_row($query = null, $output = OBJECT, $y = 0)
    {
        if ($query === null) {
            return null;
        }

        $this->queries[] = (string) $query;

        if ($this->shouldFail((string) $query)) {
            $this->last_error = $this->injectedMessage((string) $query);

            return null;
        }

        $this->last_error = '';

        $rows = $this->select((string) $query);

        if ($rows === []) {
            return null;
        }

        $row = $rows[$y] ?? null;

        if ($row === null) {
            return null;
        }

        // Matches class-wpdb.php:3070-3090 — ARRAY_A is the associative row,
        // OBJECT the same row cast, and ARRAY_N its values only.
        return match ($output) {
            ARRAY_A => $row,
            ARRAY_N => array_values($row),
            default => (object) $row,
        };
    }

    /**
     * Update the rows matching a WHERE clause, and report how many changed.
     *
     * @param string                          $table
     * @param array<string, mixed>            $data
     * @param array<string, mixed>            $where
     * @param array<int, string>|string|null  $format
     * @param array<int, string>|string|null  $whereFormat
     * @return int|false
     */
    public function update($table, $data, $where, $format = null, $whereFormat = null)
    {
        $sql = $this->buildUpdate($table, $data, $where);

        return $this->query($sql);
    }

    /**
     * @param string|null $query
     * @param int         $x
     * @return list<string>
     */
    public function get_col($query = null, $x = 0)
    {
        if ($query === null) {
            return [];
        }

        $this->queries[] = (string) $query;
        $out = [];

        foreach ($this->select((string) $query) as $row) {
            $values = array_values($row);

            if (isset($values[$x])) {
                $out[] = (string) $values[$x];
            }
        }

        return $out;
    }

    /**
     * @param string|null $query
     * @param string      $output
     * @return array<int, array<string, mixed>>|null
     */
    public function get_results($query = null, $output = OBJECT)
    {
        if ($query === null) {
            return null;
        }

        $this->queries[] = (string) $query;

        // A failed SELECT is `false` here, not an empty array — the distinction
        // the repositories depend on. See the note in get_var() above.
        if ($this->shouldFail((string) $query)) {
            $this->last_error = $this->injectedMessage((string) $query);

            return false;
        }

        $this->last_error = '';

        $rows = $this->select((string) $query);

        if ($output === ARRAY_A || $output === ARRAY_N) {
            return array_map(
                static fn (array $row): array => $output === ARRAY_A ? $row : array_values($row),
                $rows
            );
        }

        return array_map(static fn (array $row): object => (object) $row, $rows);
    }

    /**
     * The charset/collation clause a CREATE TABLE ends with.
     *
     * `utf8mb4` rather than the older `utf8`, matching what WordPress's own
     * tables use on any install the plugin supports — so a test that asserts the
     * clause reached the statement is asserting the value a real install would
     * produce, not a convenient one.
     */
    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    /**
     * Escape a string's `LIKE` metacharacters, as `wpdb::esc_like()` does.
     *
     * The real `dbDelta()` calls this before matching a table name against
     * `SHOW TABLES LIKE`, and the stub here mirrors that check so a replayed
     * `CREATE` is not re-issued. Without it the stub fatals on a global `$wpdb`
     * that is this fake, which is the arrangement every test needs in order to
     * have a `Schema::create()` actually reach a table.
     */
    public function esc_like(string $text): string
    {
        return $this->escapeLike($text);
    }

    /**
     * @param string $query
     * @param mixed  ...$args
     * @return string
     */
    public function prepare($query, ...$args)
    {
        // wpdb::prepare() historically accepted an array of args as its second
        // parameter; support both spellings since callers differ.
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $sql = (string) $query;
        $index = 0;

        return (string) preg_replace_callback(
            '/%[sdf]/',
            static function (array $m) use (&$index, $args): string {
                $value = $args[$index] ?? null;
                $index++;

                if ($m[0] === '%d') {
                    return (string) (int) $value;
                }

                if ($m[0] === '%f') {
                    return (string) (float) $value;
                }

                // Quoted, because the migrator relies on `SHOW TABLES LIKE '%s'`
                // receiving a quoted string.
                return "'" . str_replace("'", "''", (string) $value) . "'";
            },
            $sql
        );
    }

    // ------------------------------------------------------------- mini engine

    private function shouldFail(string $sql): bool
    {
        foreach ($this->failOn as $fragment) {
            if (str_contains($sql, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Answer a `GET_LOCK()` / `RELEASE_LOCK()` / `IS_USED_LOCK()` call.
     *
     * Returns `false` for "not an advisory-lock statement", which is why the
     * caller compares against `false` rather than testing truthiness: `NULL` is a
     * legitimate answer (the request errored) and must not be confused with
     * "carry on and parse this as a SELECT".
     *
     * The name is extracted from the single-quoted literal, which is how it
     * arrives — `prepare()` renders a `%s` placeholder as `'name'`, so this reads
     * what WordPress would actually send. Modelling the held-lock set rather than
     * returning a constant is what lets a test prove the lock was named correctly
     * *and* released, which are the two halves of the contract that matter.
     *
     * @return string|null|false
     */
    private function advisoryLockAnswer(string $sql)
    {
        if (preg_match('/\bGET_LOCK\s*\(\s*\'((?:[^\']|\'\')*)\'/i', $sql, $m) === 1) {
            $this->lastLockName = str_replace("''", "'", $m[1]);

            if ($this->lockResult === '1') {
                $this->heldLocks[] = $this->lastLockName;
            }

            return $this->lockResult;
        }

        if (preg_match('/\bRELEASE_LOCK\s*\(\s*\'((?:[^\']|\'\')*)\'/i', $sql, $m) === 1) {
            $this->lastReleasedLock = str_replace("''", "'", $m[1]);

            $index = array_search($this->lastReleasedLock, $this->heldLocks, true);

            if ($index === false) {
                // MySQL returns NULL when releasing a lock this connection does
                // not hold, and the distinction is worth keeping: a migrator that
                // releases a lock it never took has a bug a flat '1' would hide.
                return null;
            }

            unset($this->heldLocks[$index]);
            $this->heldLocks = array_values($this->heldLocks);

            return '1';
        }

        if (preg_match('/\bIS_USED_LOCK\s*\(/i', $sql) === 1) {
            return $this->heldLocks === [] ? null : '1';
        }

        return false;
    }

    /**
     * @return mixed
     */
    private function runDdl(string $sql)
    {
        if (preg_match('/^\s*create\s+table\s+`?([a-z0-9_]+)`?/i', $sql, $m) === 1) {
            $table = $m[1];

            // MySQL refuses a duplicate CREATE and reports "Table ... already
            // exists". Refusing it *here* is what stops the fake from handing out
            // a false green: `createTable()` is idempotent by design (it resets
            // the table), so without this guard a migration that replayed a
            // `CREATE TABLE` over a live table looked like it succeeded. It does
            // not — it fails on a real server, and that is exactly the failure a
            // two-table `up()` produces when its second half dies and the
            // migrator replays the first.
            //
            // A real `$wpdb` returns `false` and fills `last_error` rather than
            // throwing, so the caller's own `=== false` check is what reports it.
            if (isset($this->tables[$table])) {
                $this->last_error = sprintf(
                    "Table '%s' already exists",
                    $table
                );

                return false;
            }

            // The body between the outermost parentheses — every column
            // definition and every key clause, one per line, which is the shape
            // `WpDatabaseAdapter` emits and dbDelta requires.
            $this->applyCreateBody($table, $sql);

            return 0;
        }

        if (preg_match('/^\s*alter\s+table\s+`?([a-z0-9_]+)`?\s+(.+)$/is', $sql, $m) === 1) {
            return $this->runAlter($m[1], $m[2]);
        }

        // `IF EXISTS` is optional and precedes the name. Matched rather than left
        // to fail, because a migration's `down()` writes it precisely so that a
        // half-applied rollback can be re-run — and a fake that rejected the
        // spelling would make a correct migration look broken.
        if (preg_match('/^\s*drop\s+table\s+(?:if\s+exists\s+)?`?([a-z0-9_]+)`?/i', $sql, $m) === 1) {
            unset(
                $this->tables[$m[1]],
                $this->columnCache[$m[1]],
                $this->columnMeta[$m[1]],
                $this->autoIncrement[$m[1]],
                $this->indexes[$m[1]],
                $this->indexColumns[$m[1]],
                $this->primaryKeys[$m[1]],
                $this->constraints[$m[1]]
            );

            return 0;
        }

        // `RENAME TABLE a TO b`. Refused when the source is absent, which is what
        // MySQL does and what `Schema::rename()` documents — a no-op here would
        // let a test pass on a migration that on a real server fails, and the
        // whole point of the fake's loudness is not to do that.
        if (preg_match(
            '/^\s*rename\s+table\s+`?([a-z0-9_]+)`?\s+to\s+`?([a-z0-9_]+)`?\s*$/is',
            $sql,
            $m
        ) === 1) {
            if (!isset($this->tables[$m[1]])) {
                $this->last_error = sprintf("Table '%s' doesn't exist", $m[1]);

                return false;
            }

            $this->renameTableInPlace($m[1], $m[2]);

            return 0;
        }

        throw new \LogicException('FakeWpdb cannot execute DDL: ' . $sql);
    }

    /**
     * Move a table's whole record — rows, columns, metadata, keys — to a new name.
     *
     * Every parallel map is moved together, because they are all keyed by table
     * name and a rename that moved some and not others would leave the fake
     * answering about two different tables depending on which question was asked.
     * Insertion order is preserved in each, so a table's column order survives the
     * move the way it does on a real server.
     */
    private function renameTableInPlace(string $from, string $to): void
    {
        $this->tables[$to] = $this->tables[$from];
        $this->columnCache[$to] = $this->columnCache[$from] ?? [];
        $this->columnMeta[$to] = $this->columnMeta[$from] ?? [];
        $this->autoIncrement[$to] = $this->autoIncrement[$from] ?? 1;
        $this->indexes[$to] = $this->indexes[$from] ?? [];
        $this->indexColumns[$to] = $this->indexColumns[$from] ?? [];
        $this->primaryKeys[$to] = $this->primaryKeys[$from] ?? ['name' => 'PRIMARY', 'columns' => []];
        $this->constraints[$to] = $this->constraints[$from] ?? [];

        unset(
            $this->tables[$from],
            $this->columnCache[$from],
            $this->columnMeta[$from],
            $this->autoIncrement[$from],
            $this->indexes[$from],
            $this->indexColumns[$from],
            $this->primaryKeys[$from],
            $this->constraints[$from]
        );
    }

    /**
     * Apply the clauses of one `ALTER TABLE`.
     *
     * Clauses are applied in MySQL's own evaluation order, **not** the order
     * they are written — DROP before ADD — because that is the behaviour the
     * schema builder depends on and the whole reason it emits one combined
     * statement rather than several. A fake that honoured the written order
     * would make a correct migration look broken and, worse, a migration that
     * relies on the real order pass here and fail on a real database.
     *
     * Only the clause shapes the builder emits are understood; anything else
     * throws rather than being ignored, so an unsupported statement is a loud
     * failure instead of a silently-missing schema change.
     */
    private function runAlter(string $table, string $body): int
    {
        $clauses = $this->splitClauses($body);

        // MySQL's order: drops first, then adds. Written order is deliberately
        // not used; see the docblock.
        usort($clauses, static function (string $a, string $b): int {
            $rank = static fn (string $clause): int => preg_match('/^\s*drop\s/i', $clause) === 1 ? 0 : 1;

            return $rank($a) <=> $rank($b);
        });

        foreach ($clauses as $clause) {
            $this->runAlterClause($table, trim($clause));
        }

        return 0;
    }

    /**
     * Split an ALTER body on top-level commas.
     *
     * Depth-aware on parentheses, because a column definition or a key list
     * contains commas of its own — `decimal(15,2)`, `KEY (a, b)` — and splitting
     * on every comma would cut a clause in half.
     *
     * @return list<string>
     */
    private function splitClauses(string $body): array
    {
        $clauses = [];
        $current = '';
        $depth = 0;

        for ($i = 0, $length = strlen($body); $i < $length; $i++) {
            $char = $body[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $clauses[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $clauses[] = $current;
        }

        return $clauses;
    }

    /**
     * Apply one ALTER clause.
     */
    private function runAlterClause(string $table, string $clause): void
    {
        if (preg_match('/^add\s+column\s+`?([a-z0-9_]+)`?/i', $clause, $m) === 1) {
            $this->insertColumn($table, $m[1], $this->positionFrom($clause));

            // A definition has to exist for a later `change()` or `renameColumn()`
            // to read, and the clause carries one — take the type from it rather
            // than leaving the placeholder, so a rename of a freshly-added column
            // restates the type the migration actually asked for.
            $this->columnMeta[$table][$m[1]] = $this->parseColumnDefinition($clause) + [
                'Type' => 'varchar(191)',
                'Null' => 'YES',
                'Default' => null,
                'Extra' => '',
            ];

            foreach ($this->tables[$table] ?? [] as $i => $row) {
                $this->tables[$table][$i][$m[1]] = 0;
            }

            return;
        }

        // `MODIFY COLUMN` replaces a definition in place; `CHANGE COLUMN` does the
        // same and renames on the way. MySQL's grammar for both is
        // `CHANGE [COLUMN] old new definition` / `MODIFY [COLUMN] name definition`,
        // and the definition may be anything up to the next comma — so it is
        // parsed greedily and handed to the shared definition reader.
        if (preg_match('/^change\s+(?:column\s+)?`?([a-z0-9_]+)`?\s+`?([a-z0-9_]+)`?\s+(.+)$/is', $clause, $m) === 1) {
            $this->renameColumnInPlace($table, $m[1], $m[2]);
            $this->columnMeta[$table][$m[2]] = $this->parseColumnDefinition('add column `x` ' . $m[3]);

            return;
        }

        if (preg_match('/^modify\s+(?:column\s+)?`?([a-z0-9_]+)`?\s+(.+)$/is', $clause, $m) === 1) {
            $this->columnMeta[$table][$m[1]] = $this->parseColumnDefinition('add column `x` ' . $m[2]);

            // MySQL's MODIFY takes the same FIRST/AFTER clauses as ADD, and it is
            // the only way to move a column that already exists — so this is where
            // `->after('id')->change()` lands.
            $position = $this->positionFrom($clause);

            if ($position !== null) {
                $this->moveColumn($table, $m[1], $position);
            }

            return;
        }

        if (preg_match('/^add\s+(unique\s+)?key\s+`?([a-z0-9_]+)`?\s*\(([^)]*)\)/i', $clause, $m) === 1) {
            $this->indexes[$table][$m[2]] = strtolower(trim($m[1] ?? '')) === 'unique';

            // The column list is recorded too, so a dumper reading `SHOW INDEX`
            // sees the same shape the ALTER created rather than an index with no
            // columns — which would dump as `->index([])` and be refused.
            $this->indexColumns[$table][$m[2]] = $this->indexRows($m[2], $this->columnNamesIn($m[3]), $this->indexes[$table][$m[2]]);

            return;
        }

        if (preg_match('/^add\s+(unique\s+)?key\s+`?([a-z0-9_]+)`?/i', $clause, $m) === 1) {
            $this->indexes[$table][$m[2]] = strtolower(trim($m[1] ?? '')) === 'unique';

            return;
        }

        if (preg_match('/^add\s+constraint\s+`?([a-z0-9_]+)`?\s+foreign\s+key/i', $clause, $m) === 1) {
            $this->constraints[$table][$m[1]] = true;

            return;
        }

        if (preg_match('/^drop\s+column\s+`?([a-z0-9_]+)`?/i', $clause, $m) === 1) {
            // The column list is what `hasColumn()` reads, so emptying it is what
            // makes a drop observable rather than merely recorded. The stored
            // rows are pruned too: a fake that kept the data would let a
            // repository test read back a column the schema no longer has, and
            // pass on a table that a real server would reject the query against.
            $this->columnCache[$table] = array_values(array_filter(
                $this->columnCache[$table] ?? [],
                static fn (string $column): bool => $column !== $m[1]
            ));

            unset($this->columnMeta[$table][$m[1]]);

            foreach ($this->tables[$table] ?? [] as $i => $row) {
                unset($this->tables[$table][$i][$m[1]]);
            }

            return;
        }

        if (preg_match('/^drop\s+(?:key|index)\s+`?([a-z0-9_]+)`?/i', $clause, $m) === 1) {
            unset($this->indexes[$table][$m[1]], $this->indexColumns[$table][$m[1]]);

            return;
        }

        throw new \LogicException('FakeWpdb cannot execute ALTER clause: ' . $clause);
    }

    /**
     * Read a `FIRST` / `AFTER \`x\`` clause out of an ALTER clause.
     *
     * @return array{0: string, 1: string|null}|null  `['first', null]` or
     *         `['after', 'column']`, or null when the clause positions nothing.
     */
    private function positionFrom(string $clause): ?array
    {
        if (preg_match('/\bAFTER\s+`?([a-z0-9_]+)`?\s*$/i', $clause, $m) === 1) {
            return ['after', $m[1]];
        }

        if (preg_match('/\bFIRST\s*$/i', $clause) === 1) {
            return ['first', null];
        }

        return null;
    }

    /**
     * Insert a new column at a position rather than appending it.
     *
     * Column *order* is the thing `after()`/`first()` exist to control, so a fake
     * that appended regardless would report a correctly-positioned column and a
     * badly-positioned one identically — and the test asserting on order would be
     * asserting on nothing.
     *
     * @param array{0: string, 1: string|null} $position
     */
    private function insertColumn(string $table, string $column, ?array $position): void
    {
        $columns = $this->columnCache[$table] ?? [];

        if ($position === null) {
            $columns[] = $column;
        } else {
            [$where, $anchor] = $position;

            if ($where === 'first') {
                array_unshift($columns, $column);
            } elseif (($at = array_search($anchor, $columns, true)) !== false) {
                array_splice($columns, $at + 1, 0, [$column]);
            } else {
                // An anchor that does not exist: MySQL rejects the statement, and
                // appending here would let a typo'd `after()` pass as correct.
                throw new \LogicException(
                    "FakeWpdb: AFTER `{$anchor}` on [{$table}] names no such column."
                );
            }
        }

        $this->columnCache[$table] = array_values($columns);
    }

    /**
     * Move an existing column to a position.
     *
     * @param array{0: string, 1: string|null} $position
     */
    private function moveColumn(string $table, string $column, array $position): void
    {
        $columns = array_values(array_filter(
            $this->columnCache[$table] ?? [],
            static fn (string $c): bool => $c !== $column
        ));

        [$where, $anchor] = $position;

        if ($where === 'first') {
            array_unshift($columns, $column);
        } elseif (($at = array_search($anchor, $columns, true)) !== false) {
            array_splice($columns, $at + 1, 0, [$column]);
        } else {
            throw new \LogicException(
                "FakeWpdb: AFTER `{$anchor}` on [{$table}] names no such column."
            );
        }

        $this->columnCache[$table] = $columns;
    }

    /**
     * Move a column's name in every place the table records it.
     *
     * Four things are keyed by column name — the name list the `hasColumn()` probe
     * reads, the metadata map, each stored row, and any index covering it — and a
     * rename that missed one would leave the fake disagreeing with itself in a way
     * no single assertion would catch. The data is moved rather than recreated,
     * which is the property a `CHANGE COLUMN` has and the one a test can check.
     */
    private function renameColumnInPlace(string $table, string $from, string $to): void
    {
        $this->columnCache[$table] = array_map(
            static fn (string $column): string => $column === $from ? $to : $column,
            $this->columnCache[$table] ?? []
        );

        if (isset($this->columnMeta[$table][$from])) {
            $this->columnMeta[$table][$to] = $this->columnMeta[$table][$from];
            unset($this->columnMeta[$table][$from]);
        }

        foreach ($this->tables[$table] ?? [] as $i => $row) {
            if (!array_key_exists($from, $row)) {
                continue;
            }

            $rebuilt = [];

            foreach ($row as $key => $value) {
                $rebuilt[$key === $from ? $to : $key] = $value;
            }

            $this->tables[$table][$i] = $rebuilt;
        }
    }

    /**
     * Populate the catalogue from a `CREATE TABLE` body.
     *
     * ## Why this replaced a four-keyword scan
     *
     * It used to be `preg_match_all('/`(\w+)`\s+(bigint|varchar|int|tinyint)/')`
     * — a scan for four type names that silently dropped every other kind of
     * column. A table with a `decimal`, a `timestamp`, a `text`, a `date` or a
     * `json` column came back from this method **missing those columns**, and
     * every column it did find was stamped `varchar(191) NULL` regardless of what
     * the statement said. Nullability, defaults and `AUTO_INCREMENT` were all
     * discarded; `KEY` lines were never read at all.
     *
     * That is not a narrow inaccuracy. The dumper chooses its builder call from
     * the type string, decides `id()` from `AUTO_INCREMENT`, and emits
     * `->nullable()` and `->default()` from the other two — so a catalogue this
     * wrong makes the dumper's *correct* branches unreachable and its incorrect
     * ones look right. A test written against it cannot tell a working engine
     * from a broken one, which is exactly how the inverted `down()` block
     * survived a green suite.
     *
     * ## What it reads
     *
     * Column definitions and key clauses, reusing {@see parseColumnDefinition()}
     * rather than re-implementing it — a second reader of the same grammar would
     * drift from the first, and the drift would be invisible in both. The body is
     * split on top-level commas by {@see splitClauses()}, which is already
     * depth-aware, so `decimal(10,2)` and `KEY (a, b)` stay in one piece.
     *
     * Anything in the body that is neither a column nor a key it recognises is
     * ignored rather than thrown on, because a `CREATE TABLE` legitimately
     * carries trailing table options (`DEFAULT CHARACTER SET ... COLLATE ...`)
     * that say nothing about its shape.
     */
    private function applyCreateBody(string $table, string $sql): void
    {
        $body = $this->bodyOfCreateStatement($sql);

        $columns = [];
        $meta = [];
        $indexes = [];
        $indexColumns = [];
        $primary = [];

        foreach ($this->splitClauses($body) as $clause) {
            $clause = trim((string) preg_replace('/\s+/', ' ', trim($clause)));

            if ($clause === '') {
                continue;
            }

            if (preg_match('/^primary\s+key\s*\(([^)]*)\)/i', $clause, $m) === 1) {
                $primary = $this->columnNamesIn($m[1]);

                continue;
            }

            if (preg_match('/^(?:unique\s+)?key\s+`?([a-z0-9_]+)`?\s*\(([^)]*)\)/i', $clause, $m) === 1) {
                $unique = stripos($clause, 'unique') === 0;

                $indexes[$m[1]] = $unique;
                $indexColumns[$m[1]] = $this->indexRows($m[1], $this->columnNamesIn($m[2]), $unique);

                continue;
            }

            if (preg_match('/^`?([a-z0-9_]+)`?\s+(.+)$/is', $clause, $m) === 1) {
                $columns[] = $m[1];
                $meta[$m[1]] = $this->parseColumnDefinition($clause);
            }
        }

        // `createTable()` seeds the default tracking-table shape and stamps every
        // column with a `varchar(191)` placeholder. Both are replaced rather than
        // merged into, because the statement is the whole truth about this table:
        // the placeholder is a guess and a stale default on a column the CREATE
        // did not declare would be a column that does not exist.
        $this->createTable($table, $columns !== [] ? $columns : ['id', 'migration', 'batch']);

        foreach ($meta as $column => $definition) {
            $this->columnMeta[$table][$column] = $definition;
        }

        // Reported as the database would: the key exists as `PRIMARY` with the
        // columns it names, and with none when the table declares no primary key.
        // Read back rather than assumed, so the dumper's `id()` decision rests on
        // the schema instead of on a column's name.
        $this->primaryKeys[$table] = ['name' => 'PRIMARY', 'columns' => $primary];

        $this->indexes[$table] = $indexes;
        $this->indexColumns[$table] = $indexColumns;
    }

    /**
     * The parenthesised body of a `CREATE TABLE`, without its table options.
     *
     * Scanned rather than matched with a greedy regex, because the body contains
     * parentheses of its own — `decimal(10,2)`, `KEY (a, b)` — and the trailing
     * `DEFAULT CHARACTER SET utf8mb4 COLLATE ...` is *outside* them. A `(.*)`
     * match would swallow the options and hand them to the clause splitter as a
     * final, unparseable clause. The first `(` opens the body and the matching
     * `)` at depth zero closes it.
     */
    private function bodyOfCreateStatement(string $sql): string
    {
        $open = strpos($sql, '(');

        if ($open === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($sql);

        for ($i = $open; $i < $length; $i++) {
            if ($sql[$i] === '(') {
                $depth++;
            } elseif ($sql[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    return substr($sql, $open + 1, $i - $open - 1);
                }
            }
        }

        return substr($sql, $open + 1);
    }

    /**
     * Decompose a column definition into the `SHOW COLUMNS` shape.
     *
     * A real server would report what it stored; this reads back what the
     * statement said, which is the closest a fake can honestly get and is enough
     * for a rename to restate the type the migration declared.
     *
     * The type is taken as the leading run of non-keyword text — everything up to
     * the first `NOT NULL` / `NULL` / `DEFAULT` / `AUTO_INCREMENT` — because a
     * type may itself contain spaces (`bigint unsigned`, `double precision`) and
     * stopping at the first space would truncate those to a type that does not
     * exist.
     *
     * The name is stripped in either spelling it arrives in: the `ADD COLUMN x`
     * form an `ALTER` clause carries, and the bare `` `x` `` a `CREATE TABLE`
     * body does. Both reach here, and a name left in front of the type would be
     * read as part of it.
     *
     * @return array{Type: string, Null: string, Default: string|null, Extra: string}
     */
    private function parseColumnDefinition(string $clause): array
    {
        $definition = trim((string) preg_replace('/^add\s+column\s+`?[a-z0-9_]+`?\s+/i', '', $clause));
        $definition = trim((string) preg_replace('/^`[a-z0-9_]+`\s+/i', '', $definition));

        preg_match('/^(.*?)(?=\s+(?:not\s+null|null|default|auto_increment|on\s+update)\b|$)/is', $definition, $m);
        $type = trim($m[1] ?? '');

        $null = preg_match('/\bnot\s+null\b/i', $definition) === 1 ? 'NO' : 'YES';

        $default = null;

        if (preg_match('/\bdefault\s+(\'((?:[^\'\\\\]|\\\\.|\'\')*)\'|[^\s,]+)/i', $definition, $d) === 1) {
            $default = isset($d[2]) && $d[2] !== ''
                ? $this->unescapeLiteral($d[2])
                : trim($d[1], "'");
        }

        $extra = preg_match('/\bauto_increment\b/i', $definition) === 1 ? 'auto_increment' : '';

        if ($extra === '' && preg_match('/\bon\s+update\s+(current_timestamp(?:\(\d*\))?)/i', $definition, $u) === 1) {
            $extra = 'on update ' . strtoupper($u[1]);
        }

        return [
            'Type' => $type,
            'Null' => $null,
            'Default' => $default,
            'Extra' => $extra,
        ];
    }

    /**
     * Read a SQL string literal's body back as the value a server would store.
     *
     * The body is the text *between* the quotes, already stripped of the outer
     * quotes by the caller's capture group.
     *
     * ## Why `\\` and `''` are both handled, and in this order
     *
     * MySQL treats a backslash as an escape character inside a literal and also
     * accepts a doubled quote. The builder emits both spellings — `\\` for a
     * backslash, `''` for a quote — so a fake that only collapsed `''` reported
     * `C:\\path\\` where a server reports `C:\path\`, and any schema assertion
     * comparing a default through the fake disagreed with reality by exactly the
     * escaping the builder had done.
     *
     * Backslashes are consumed *first* by a single left-to-right pass rather than
     * by two `str_replace()` calls, because the passes are not commutative: a
     * value that legitimately contains the two characters `\'` would have its
     * quote collapsed by a quote pass and then its backslash collapsed by the
     * backslash pass, yielding `'` — one character where the server yields two.
     * Walking the string once, and letting a backslash claim the character after
     * it, is what keeps `\\` and `\'` distinguishable.
     */
    private function unescapeLiteral(string $body): string
    {
        $out = '';
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($char !== '\\') {
                $out .= $char;
                continue;
            }

            // A trailing backslash with nothing after it cannot escape anything;
            // keep it rather than dropping it silently.
            if ($i + 1 >= $length) {
                $out .= '\\';
                continue;
            }

            $next = $body[++$i];

            $out .= match ($next) {
                '\\' => '\\',
                "'" => "'",
                '"' => '"',
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                '0' => "\0",
                'Z' => "\x1a",
                // MySQL drops the backslash before any other character, so `\x`
                // reads as `x`.
                default => $next,
            };
        }

        // A doubled quote is a quote, and is safe to collapse unconditionally:
        // the loop above has already consumed every backslash escape, so any `''`
        // remaining is the doubling spelling rather than an escaped quote.
        return str_replace("''", "'", $out);
    }

    /**
     * @return int|false
     */
    private function runInsert(string $sql)
    {
        // `INSERT … ON DUPLICATE KEY UPDATE` is split off first, because the
        // plain-insert pattern below anchors on the statement *ending* after the
        // VALUES list — and an upsert does not end there, so it would fall
        // through to the "cannot parse" throw.
        //
        // Handled as its own path rather than by loosening that regex: the two
        // statements have genuinely different semantics (one fails on a duplicate,
        // the other updates), and a single regex covering both would have to
        // decide which it was from a capture group, which is harder to read than
        // two methods.
        if (preg_match('/\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+(.+)$/is', $sql, $upsert, PREG_OFFSET_CAPTURE) === 1) {
            return $this->runUpsert(substr($sql, 0, (int) $upsert[0][1]), $upsert[1][0]);
        }

        if (preg_match('/^\s*insert\s+into\s+`?([a-z0-9_]+)`?\s*\(([^)]*)\)\s*values\s*\((.*)\)\s*$/is', $sql, $m) !== 1) {
            throw new \LogicException('FakeWpdb cannot parse INSERT: ' . $sql);
        }

        $table = $m[1];
        $columns = array_map(
            static fn (string $c): string => trim($c, " `\t\n\r\0\x0B"),
            explode(',', $m[2])
        );
        $values = $this->splitValues($m[3]);

        $row = array_combine($columns, $values);

        if (!isset($this->tables[$table])) {
            throw new \LogicException("FakeWpdb: no such table [{$table}].");
        }

        // The UNIQUE key on `migration` is enforced here, because the migrator
        // depends on the database rejecting a duplicate rather than a
        // read-then-write check that could race between two requests.
        if (in_array('migration', $columns, true)) {
            $identifier = $row['migration'];

            foreach ($this->tables[$table] as $existing) {
                if (($existing['migration'] ?? null) === $identifier) {
                    $this->last_error = "Duplicate entry '{$identifier}' for key 'migration'";
                    $this->insert_id = 0;

                    return false;
                }
            }
        }

        $this->insert_id = $this->autoIncrement[$table]++;
        $row['id'] = $this->insert_id;
        $this->tables[$table][] = $row;

        return 1;
    }

    /**
     * `INSERT … ON DUPLICATE KEY UPDATE`, as MySQL evaluates it.
     *
     * The rate limiter depends on this statement's *atomicity*, and the fake
     * cannot reproduce concurrency — but it can reproduce the semantics that make
     * the atomicity meaningful, which is what a test needs to assert the limiter
     * counts correctly:
     *
     *  - The duplicate is detected on a **unique key**, so the target table must
     *    actually declare one. The fake reads the constraint it recorded from the
     *    `CREATE TABLE` rather than assuming `rate_key` is unique — assuming would
     *    let a migration that forgot the index pass this fake while the real
     *    database inserted a new row per hit, which is a limiter that always
     *    allows.
     *  - `IF(cond, a, b)` is evaluated, because the limiter's window reset is
     *    expressed that way. A fake that ignored the condition would report every
     *    hit as the first in its window.
     *  - Column references on the right of an assignment read the **existing**
     *    row, which is MySQL's behaviour for the UPDATE branch — the values
     *    supplied in `VALUES(...)` are not what `attempts + 1` sees.
     *  - `$this->rows_affected` is 1 for an insert and 2 for an update, matching
     *    MySQL (class-wpdb.php surfaces this through the return of `query()`).
     *
     * What it deliberately does not do: `VALUES(col)` in the update clause, and
     * multi-row inserts. The suite issues neither, and a parser that accepted them
     * would be claiming fidelity it does not have — the same reasoning as
     * {@see query()}'s "throw rather than silently return 0".
     *
     * @param string $insertPart The statement up to (not including) `ON DUPLICATE`.
     * @param string $updatePart The assignment list after `ON DUPLICATE KEY UPDATE`.
     *
     * @return int|false
     */
    private function runUpsert(string $insertPart, string $updatePart)
    {
        if (preg_match('/^\s*insert\s+into\s+`?([a-z0-9_]+)`?\s*\(([^)]*)\)\s*values\s*\((.*)\)\s*$/is', trim($insertPart), $m) !== 1) {
            throw new \LogicException('FakeWpdb cannot parse the INSERT half of an upsert: ' . $insertPart);
        }

        $table = $m[1];
        $columns = array_map(
            static fn (string $c): string => trim($c, " `\t\n\r\0\x0B"),
            explode(',', $m[2])
        );
        $values = $this->splitValues($m[3]);
        $incoming = array_combine($columns, $values);

        if (!isset($this->tables[$table])) {
            throw new \LogicException("FakeWpdb: no such table [{$table}].");
        }

        if ($incoming === false) {
            throw new \LogicException('FakeWpdb: an upsert\'s column and value counts differ: ' . $insertPart);
        }

        // Which column carries the unique key is read from the schema the fake
        // recorded, not assumed — see the method docblock.
        $unique = $this->uniqueColumns($table);

        if ($unique === []) {
            throw new \LogicException(
                "FakeWpdb: [{$table}] has no unique key, so ON DUPLICATE KEY UPDATE can never match a "
                . 'duplicate. The real database would insert a row per statement.'
            );
        }

        $existingIndex = $this->findByUniqueKey($table, $unique, $incoming);

        if ($existingIndex === null) {
            $this->insert_id = $this->autoIncrement[$table]++;
            $incoming['id'] = $this->insert_id;
            $this->tables[$table][] = $incoming;
            $this->rows_affected = 1;

            return 1;
        }

        // The UPDATE branch. Each assignment is evaluated against the *existing*
        // row and written back in place, so a later assignment in the same list
        // sees an earlier one's result — which is the order MySQL applies them.
        $updates = $this->splitUpsertAssignments($updatePart);

        foreach ($updates as $column => $expression) {
            $this->tables[$table][$existingIndex][$column] =
                $this->evaluateExpression($expression, $this->tables[$table][$existingIndex]);
        }

        $this->rows_affected = 2;
        $this->insert_id = 0;

        return 2;
    }

    /**
     * The columns a table's unique keys cover, flattened.
     *
     * Read from the constraints the fake recorded while running `CREATE TABLE` or
     * `ALTER TABLE` — `$indexes` holds uniqueness per index name and
     * `$indexColumns` the columns each covers — so an upsert's duplicate
     * detection is only as good as the schema actually declared. Assuming a
     * particular column was unique would let a migration that forgot the index
     * pass here while the real database inserted a row per statement.
     *
     * @return list<string>
     */
    private function uniqueColumns(string $table): array
    {
        $columns = [];

        foreach ($this->indexes[$table] ?? [] as $index => $isUnique) {
            if (!$isUnique) {
                continue;
            }

            foreach ($this->indexColumns[$table][$index] ?? [] as $row) {
                // `indexRows()` stores the shape `information_schema` reports, so
                // the column name is under `Column_name` — the same shape
                // `addIndex()` builds for the schema dumper.
                $name = is_array($row) ? ($row['Column_name'] ?? null) : $row;

                if (is_string($name) && $name !== '') {
                    $columns[] = $name;
                }
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * The index of the row an incoming set of values collides with, or null.
     *
     * @param list<string>                $unique
     * @param array<string, mixed>|false  $incoming
     */
    private function findByUniqueKey(string $table, array $unique, array|false $incoming): ?int
    {
        if ($incoming === false) {
            return null;
        }

        foreach ($this->tables[$table] as $index => $row) {
            $matches = true;

            foreach ($unique as $column) {
                // A column the statement did not supply cannot match: MySQL only
                // detects a duplicate on the columns actually written (plus a
                // default), and treating an absent column as matching would
                // collapse unrelated rows.
                if (!array_key_exists($column, $incoming)) {
                    $matches = false;

                    break;
                }

                if (($row[$column] ?? null) !== $incoming[$column]) {
                    $matches = false;

                    break;
                }
            }

            if ($matches) {
                return (int) $index;
            }
        }

        return null;
    }

    /**
     * Split an update clause into column => expression.
     *
     * Commas inside parentheses are not separators — the limiter's own clause is
     * `attempts = IF(a, 1, b), expires_at = IF(a, c, d)`, and splitting naively on
     * every comma would tear each `IF()` in half. The pre-existing
     * {@see splitAssignments()} does not track depth, which is correct for the
     * flat `col = value` lists the repository issues and wrong here, so this is a
     * separate method rather than a change to that one.
     *
     * Returns a map rather than a list because the update branch applies
     * assignments to a row by column name, and a list would need re-parsing there.
     *
     * @return array<string, string>
     */
    private function splitUpsertAssignments(string $clause): array
    {
        $assignments = [];
        $current = '';
        $depth = 0;
        $inString = false;

        $push = function (string $piece) use (&$assignments): void {
            $piece = trim($piece);

            if ($piece === '') {
                return;
            }

            [$column, $expression] = array_pad(explode('=', $piece, 2), 2, '');

            $column = trim($column, " `\t\n\r\0\x0B");

            if ($column === '' || $expression === '') {
                throw new \LogicException("FakeWpdb cannot parse the upsert assignment [{$piece}].");
            }

            $assignments[$column] = trim($expression);
        };

        for ($i = 0, $length = strlen($clause); $i < $length; $i++) {
            $char = $clause[$i];

            if ($char === "'" && ($clause[$i - 1] ?? '') !== '\\') {
                $inString = !$inString;
            }

            if (!$inString) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                }
            }

            if ($char === ',' && $depth === 0 && !$inString) {
                $push($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $push($current);

        return $assignments;
    }

    /**
     * Evaluate the small expression subset an upsert clause uses.
     *
     * Three forms, which is all the limiter and a repository's counter would
     * issue: a bare literal, a bare column, and `IF(cond, then, else)` where the
     * condition is `<column> <op> <literal>` and the branches are literals or
     * `column <op> literal` arithmetic.
     *
     * Anything else throws. A fake that guessed at an unparsed expression would
     * silently store the wrong value, and a limiter whose counter is wrong is a
     * limiter that reports nothing while being bypassed — the failure this whole
     * component exists to prevent.
     *
     * @param array<string, mixed> $row The existing row, for column references.
     *
     * @return int|string
     */
    private function evaluateExpression(string $expression, array $row): int|string
    {
        $expression = trim($expression);

        // IF(condition, then, else)
        if (preg_match('/^IF\s*\((.*)\)$/is', $expression, $m) === 1) {
            [$condition, $then, $else] = $this->splitArguments($m[1]);

            return $this->evaluateExpression(
                $this->evaluateCondition($condition, $row) ? $then : $else,
                $row
            );
        }

        // `column + 1` / `column - 1`
        if (preg_match('/^`?([a-z0-9_]+)`?\s*([+\-])\s*(\d+)$/i', $expression, $m) === 1) {
            $base = (int) ($row[$m[1]] ?? 0);

            return $m[2] === '+' ? $base + (int) $m[3] : $base - (int) $m[3];
        }

        // A quoted literal.
        if (preg_match("/^'(.*)'$/s", $expression, $m) === 1) {
            return $m[1];
        }

        // A bare integer.
        if (preg_match('/^-?\d+$/', $expression) === 1) {
            return (int) $expression;
        }

        // A bare column reference.
        if (preg_match('/^`?([a-z0-9_]+)`?$/i', $expression, $m) === 1) {
            return $row[$m[1]] ?? '';
        }

        throw new \LogicException("FakeWpdb cannot evaluate the upsert expression [{$expression}].");
    }

    /**
     * Evaluate a comparison inside an `IF()`.
     *
     * @param array<string, mixed> $row
     */
    private function evaluateCondition(string $condition, array $row): bool
    {
        if (preg_match('/^`?([a-z0-9_]+)`?\s*(<=|>=|<>|!=|=|<|>)\s*(.+)$/is', trim($condition), $m) !== 1) {
            throw new \LogicException("FakeWpdb cannot evaluate the upsert condition [{$condition}].");
        }

        $left = $row[$m[1]] ?? null;
        $right = $this->evaluateExpression($m[3], $row);

        return match ($m[2]) {
            '='          => $left == $right,
            '<>' , '!='  => $left != $right,
            '<'          => $left < $right,
            '>'          => $left > $right,
            '<='         => $left <= $right,
            '>='         => $left >= $right,
        };
    }

    /**
     * Split a function's argument list at top-level commas.
     *
     * @return list<string>
     */
    private function splitArguments(string $list): array
    {
        $arguments = [];
        $current = '';
        $depth = 0;
        $inString = false;

        for ($i = 0, $length = strlen($list); $i < $length; $i++) {
            $char = $list[$i];

            if ($char === "'") {
                $inString = !$inString;
            }

            if (!$inString) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                }
            }

            if ($char === ',' && $depth === 0 && !$inString) {
                $arguments[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $arguments[] = trim($current);

        return $arguments;
    }

    /**
     * DELETE, in the two shapes the suite issues: the migrator's
     * `WHERE migration = '...'`, and a plain equality clause over numeric
     * columns (`WHERE id = 7`, `WHERE id = 7 AND user_id = 3`).
     *
     * @return int
     */
    private function runDelete(string $sql)
    {
        if (preg_match('/^\s*delete\s+from\s+`?([a-z0-9_]+)`?\s+where\s+`?migration`?\s*=\s*\'(.*)\'\s*$/is', $sql, $m) === 1) {
            $table = $m[1];
            $identifier = str_replace("''", "'", $m[2]);

            return $this->deleteWhere(
                $table,
                static fn (array $row): bool => ($row['migration'] ?? null) !== $identifier
            );
        }

        if (preg_match('/^\s*delete\s+from\s+`?([a-z0-9_]+)`?\s+where\s+(.+?)\s*$/is', $sql, $m) === 1) {
            $table = $m[1];
            $predicate = $this->compileEqualityPredicate($m[2]);

            return $this->deleteWhere($table, static fn (array $row): bool => !$predicate($row));
        }

        throw new \LogicException('FakeWpdb cannot parse DELETE: ' . $sql);
    }

    /**
     * Keep the rows matching a predicate, and report how many were removed.
     *
     * @param callable(array<string, mixed>): bool $keep
     */
    private function deleteWhere(string $table, callable $keep): int
    {
        $before = count($this->tables[$table] ?? []);

        $this->tables[$table] = array_values(array_filter($this->tables[$table] ?? [], $keep));

        return $before - count($this->tables[$table]);
    }

    /**
     * UPDATE `table` SET ... WHERE ..., and report the rows changed.
     *
     * The rows are actually changed, so a later SELECT sees the new values — the
     * property a repository test depends on when it saves and reads back.
     *
     * @return int
     */
    private function runUpdate(string $sql)
    {
        if (preg_match('/^\s*update\s+`?([a-z0-9_]+)`?\s+set\s+(.+?)\s+where\s+(.+?)\s*$/is', $sql, $m) !== 1) {
            throw new \LogicException('FakeWpdb cannot parse UPDATE: ' . $sql);
        }

        $table = $m[1];

        if (!isset($this->tables[$table])) {
            throw new \LogicException("FakeWpdb: no such table [{$table}].");
        }

        // Split the SET clause on commas *outside* quoted strings, then read each
        // `col = value` pair. Splitting the raw clause and taking the odd tokens
        // would keep the backticked column names in the value list.
        $changed = [];

        foreach ($this->splitAssignments($m[2]) as $pair) {
            if (preg_match('/^\s*`?([a-z0-9_]+)`?\s*=\s*(.+?)\s*$/is', $pair, $assignment) !== 1) {
                throw new \LogicException('FakeWpdb cannot parse UPDATE assignment: ' . $pair);
            }

            $changed[$assignment[1]] = $this->castValue($assignment[2]);
        }

        $matches = $this->compileEqualityPredicate($m[3]);
        $affected = 0;

        foreach ($this->tables[$table] as $i => $row) {
            if (!$matches($row)) {
                continue;
            }

            $this->tables[$table][$i] = array_replace($row, $changed);
            $affected++;
        }

        return $affected;
    }

    /**
     * Split a SET clause into `col = value` pairs, honouring quoted commas.
     *
     * @return list<string>
     */
    private function splitAssignments(string $clause): array
    {
        $pairs = [];
        $current = '';
        $inString = false;

        for ($i = 0, $length = strlen($clause); $i < $length; $i++) {
            $char = $clause[$i];

            if ($char === "'") {
                if ($inString && ($clause[$i + 1] ?? '') === "'") {
                    $current .= "''";
                    $i++;

                    continue;
                }

                $inString = !$inString;
                $current .= $char;

                continue;
            }

            if ($char === ',' && !$inString) {
                $pairs[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $pairs[] = $current;
        }

        return $pairs;
    }

    /**
     * The narrow SELECT/SHOW surface the migrator uses.
     *
     * @return list<array<string, mixed>>
     */
    private function select(string $sql): array
    {
        if (preg_match('/^\s*show\s+tables\s+like\s+\'(.*)\'\s*$/is', $sql, $m) === 1) {
            $pattern = str_replace("''", "'", $m[1]);

            // LIKE, not equality: `_` matches any single character and `%` any
            // run, with a backslash escaping either. Interpreted here because the
            // migrator escapes its own pattern to stop the table prefix's
            // underscores acting as wildcards, and a fake that compared the raw
            // string would report the table missing and hide that behaviour.
            //
            // $tables holds an (empty) entry for every created table, so its keys
            // are the set of tables that exist.
            foreach (array_keys($this->tables) as $name) {
                if ($this->likeMatches($pattern, (string) $name)) {
                    return [['TABLE_NAME' => $name]];
                }
            }

            return [];
        }

        // A bare `SHOW TABLES`, with no `LIKE` clause — what the schema dumper
        // reads to list every table on the server.
        //
        // Placed before the `LIKE` form and anchored with `$`, so the two do not
        // shadow each other: a pattern that matched both would answer the
        // unqualified call with the *first* table rather than all of them, and a
        // discovery test would then pass or fail depending on insertion order.
        //
        // Every table is returned, prefix and all, because that is what MySQL
        // does — deciding which of them are the plugin's is the caller's job, and
        // a fake that pre-filtered would make that decision untestable.
        if (preg_match('/^\s*show\s+tables\s*$/is', $sql) === 1) {
            $rows = [];

            foreach (array_keys($this->tables) as $name) {
                $rows[] = ['Tables_in_' . ($this->dbname ?? 'wordpress') => (string) $name];
            }

            return $rows;
        }

        // `SHOW INDEX FROM` — what the schema dumper reads to reconstruct a
        // table's keys. Rows are ordered by index name then column position, which
        // is the order MySQL returns them in and therefore the order a dumper may
        // rely on for a composite key's column list.
        if (preg_match('/^\s*show\s+(?:index|keys)\s+from\s+`?([a-z0-9_]+)`?\s*$/is', $sql, $m) === 1) {
            $rows = [];

            foreach ($this->indexColumns[$m[1]] ?? [] as $name => $entries) {
                // The primary key comes back first on a real server, and this
                // mirrors that: a dumper that assumed it was absent would emit a
                // table with no primary key.
                if (isset($this->primaryKeys[$m[1]]) && $name === $this->primaryKeys[$m[1]]['name']) {
                    continue;
                }

                foreach ($entries as $entry) {
                    $rows[] = $entry;
                }
            }

            return $rows;
        }

        if (preg_match('/^\s*show\s+columns\s+from\s+`?([a-z0-9_]+)`?\s*(?:like\s+\'(.*)\')?\s*$/is', $sql, $m) === 1) {
            $table = $m[1];
            $columns = $this->columnCache[$table] ?? [];

            // The column probe narrows with `LIKE`, and the two metacharacters
            // matter here for the same reason they do in the table probe: a
            // column name is an identifier, so `_` in one has to be a literal
            // underscore and not "any single character". A fake that ignored the
            // clause would answer every column probe true and let a guard written
            // against the wrong column name pass.
            if (isset($m[2]) && $m[2] !== '') {
                $pattern = str_replace("''", "'", $m[2]);
                $columns = array_values(array_filter(
                    $columns,
                    fn (string $column): bool => $this->likeMatches($pattern, $column)
                ));
            }

            return array_map(
                fn (string $column): array => ['Field' => $column]
                    + ($this->columnMeta[$table][$column] ?? []),
                $columns
            );
        }

        // The index-registry probe the schema adapter makes before replacing a
        // key. Answered from the same `$indexes` map the ALTER parser fills, so
        // the fake reports an index present exactly when it has seen the
        // statement that creates one.
        //
        // Equality, not LIKE — there is no wildcard to interpret, and a name
        // containing `_` must compare literally. Both placeholders arrive
        // single-quoted, having been substituted by prepare() before this runs.
        if (preg_match(
            '/^\s*select\s+index_name\s+from\s+information_schema\.statistics\s+'
            . 'where\s+.*?table_name\s*=\s*[\'`]?([a-z0-9_]+)[\'`]?\s+.*?index_name\s*=\s*[\'`]?([a-z0-9_]+)[\'`]?/is',
            $sql,
            $m
        ) === 1) {
            return isset($this->indexes[$m[1]][$m[2]])
                ? [['INDEX_NAME' => $m[2]]]
                : [];
        }

        // MAX(batch) — one value, or no row at all on an empty table.
        if (preg_match('/^\s*select\s+max\(`?batch`?\)\s+from\s+`?([a-z0-9_]+)`?/i', $sql, $m) === 1) {
            $batches = array_column($this->tables[$m[1]] ?? [], 'batch');

            if ($batches === []) {
                return [];
            }

            return [['MAX(`batch`)' => (string) max(array_map('intval', $batches))]];
        }

        if (preg_match('/^\s*select\s+`?migration`?,\s*`?batch`?\s+from\s+`?([a-z0-9_]+)`?/i', $sql, $m) === 1) {
            return array_map(
                static fn (array $row): array => [
                    'migration' => (string) $row['migration'],
                    'batch' => (string) $row['batch'],
                ],
                $this->tables[$m[1]] ?? []
            );
        }

        if (preg_match('/^\s*select\s+`?migration`?\s+from\s+`?([a-z0-9_]+)`?\s+where\s+`?batch`?\s*=\s*(\d+)/i', $sql, $m) === 1) {
            $batch = (int) $m[2];

            return array_values(array_map(
                static fn (array $row): array => ['migration' => (string) $row['migration']],
                array_filter(
                    $this->tables[$m[1]] ?? [],
                    static fn (array $row): bool => (int) $row['batch'] === $batch
                )
            ));
        }

        // The constraint-registry probe the schema adapter makes before adding
        // a foreign key. Answered from the same `$constraints` map the ALTER
        // parser fills, so the fake reports a constraint present exactly when it
        // has seen the statement that creates one — an unrehearsed `true` here
        // would make a re-run look idempotent while the adapter silently skipped
        // every ALTER.
        //
        // Both `%s` placeholders are already substituted by `prepare()` before
        // this is reached, and come back single-quoted; the pattern accepts
        // either quoting so a probe written without placeholders still parses.
        if (preg_match(
            '/^\s*select\s+constraint_name\s+from\s+information_schema\.table_constraints\s+'
            . 'where\s+.*?table_name\s*=\s*[\'`]?([a-z0-9_]+)[\'`]?\s+.*?constraint_name\s*=\s*[\'`]?([a-z0-9_]+)[\'`]?/is',
            $sql,
            $m
        ) === 1) {
            return isset($this->constraints[$m[1]][$m[2]])
                ? [['CONSTRAINT_NAME' => $m[2]]]
                : [];
        }

        // COUNT(*), optionally with an equality WHERE. Returns one row, so
        // get_var() reads a real number, and an empty table still yields 0
        // rather than no row at all.
        if (preg_match('/^\s*select\s+count\(\*\)\s+from\s+`?([a-z0-9_]+)`?(?:\s+where\s+(.+?))?\s*$/is', $sql, $m) === 1) {
            $rows = $this->tables[$m[1]] ?? [];

            if (!empty($m[2])) {
                $matches = $this->compileEqualityPredicate($m[2]);
                $rows = array_filter($rows, $matches);
            }

            return [['COUNT(*)' => (string) count($rows)]];
        }

        // SELECT * with an optional equality WHERE, ORDER BY, LIMIT and OFFSET.
        // The general read the note repository issues, narrowed deliberately:
        // only `*`, only equality conjunctions, and only `ORDER BY` over real
        // columns.
        if (preg_match('/^\s*select\s+\*\s+from\s+`?([a-z0-9_]+)`?(.*)$/is', $sql, $m) === 1) {
            return array_map(
                static fn (array $row): array => self::withoutQualifiedKeys($row),
                $this->selectRows($m[1], trim($m[2]))
            );
        }

        // An explicit column list — `SELECT attempts, expires_at FROM …`. The
        // rate limiter issues this and nothing else in the suite did before it.
        //
        // Added as its own branch rather than by widening the `*` pattern to any
        // projection, because the projection has to be *applied* and the `*` path
        // never needed to be: widening it would mean every existing `SELECT *`
        // read now goes through a projection step that is a no-op for it, which is
        // a change to working code to serve a new caller.
        //
        // Columns are returned as MySQL returns them — every named column present,
        // even when the row lacks it (as null) — because a caller reading a column
        // that is simply absent would get a PHP notice rather than the null the
        // database would have given it.
        //
        // A term may be qualified (`deal_stages`.`id`) and aliased
        // (`deal_stages`.`id` AS `stage_id`). Both are what a joined read needs:
        // the qualifier says which table an ambiguous name belongs to, and the
        // alias is the key the caller reads the value back under - which is the
        // name that must appear in the returned row, not the column's own.
        $term = '`?[a-z0-9_]+`?(?:\.`?[a-z0-9_]+`?)?(?:\s+as\s+`?[a-z0-9_]+`?)?';

        if (preg_match('/^\s*select\s+(' . $term . '(?:\s*,\s*' . $term . ')*)\s+from\s+`?([a-z0-9_]+)`?(.*)$/is', $sql, $m) === 1) {
            $columns = [];

            foreach (explode(',', $m[1]) as $projection) {
                $projection = trim($projection);

                $alias = null;

                if (preg_match('/^(.*?)\s+as\s+(.+)$/is', $projection, $parts) === 1) {
                    $projection = trim($parts[1]);
                    $alias = trim($parts[2], " `\t\n\r\0\x0B");
                }

                $columns[] = $this->projectionTerm($projection, $alias, $m[2]);
            }

            // The FROM table is the statement's own scope, and it is not known
            // until `selectRows()` parses the tail - so the projection is
            // validated first, against the tables this statement declares, and
            // then resolved per row. Validating here rather than only in the row
            // mapper is what makes an unknown column independent of the result
            // set: a real server raises 1054 at prepare time, before it knows
            // whether any row exists, and a mapper that only runs for a non-empty
            // result would let invalid SQL through whenever the WHERE happened to
            // match nothing.
            $scope = $this->tablesInScopeFor($m[2], trim($m[3]));

            foreach ($columns as $column) {
                if ($column['qualifier'] !== null) {
                    $this->clauseInScope = 'field list';
                    $this->assertQualifierIsInScope($column['qualifier'], $scope);
                }
            }

            $rows = $this->selectRows($m[2], trim($m[3]));

            return array_map(
                static function (array $row) use ($columns): array {
                    $projected = [];

                    foreach ($columns as $column) {
                        // A qualified term is read from its own `table.column`
                        // key, which is either present or was already refused
                        // above - so no fallback applies to it. A bare term falls
                        // back to the `FROM` table's key: the `FROM` table is
                        // always in scope, so that can only resolve to a column
                        // MySQL would also have resolved.
                        $key = $column['source'] ?? $column['fallback'];

                        $projected[$column['key']] = $row[$key] ?? null;
                    }

                    return $projected;
                },
                $rows
            );
        }

        throw new \LogicException('FakeWpdb cannot parse SELECT: ' . $sql);
    }

    /**
     * Evaluate a `SELECT *` tail clause by clause.
     *
     * @return list<array<string, mixed>>
     */
    private function selectRows(string $table, string $tail): array
    {
        $rows = array_values($this->tables[$table] ?? []);

        // Joins come first: SQL requires them between FROM and WHERE, and they
        // change what the rest of the tail has to evaluate against.
        $remaining = trim($tail);
        $joins = [];

        // The join is consumed by index rather than by the match's own length:
        // the operand pattern is greedy and would swallow the following clause
        // (`where`, `order by`) as part of a qualified name if the whole match
        // decided where to resume. The lookahead ends the join at the next clause
        // keyword, or at the end of the tail.
        $joinPattern = '/^(inner|left)\s+join\s+`?([a-z0-9_]+)`?\s+on\s+'
            . '(`?[a-z0-9_]+`?(?:\.`?[a-z0-9_]+`?)?)\s*(=|!=|<>|<=|>=|<|>)\s*'
            . '(`?[a-z0-9_]+`?(?:\.`?[a-z0-9_]+`?)?)'
            . '(?=\s+where\s|\s+order\s+by\s|\s+limit\s|\s+offset\s|\s*$)/is';

        while (preg_match($joinPattern, $remaining, $m, PREG_OFFSET_CAPTURE) === 1) {
            $joins[] = [
                'left' => strtolower($m[1][0]) === 'left',
                'table' => $m[2][0],
                'first' => $m[3][0],
                'operator' => $m[4][0],
                'second' => $m[5][0],
            ];
            $remaining = substr($remaining, strlen($m[0][0]));
        }

        // The `FROM` table's own columns are keyed bare by the store, and a
        // predicate or projection may qualify them. The qualified key is the
        // **prefixed** table name, which is the only spelling MySQL resolves:
        // `FROM `wp_leads`` creates no alias, so `leads.id` is error 1054. Writing
        // a bare-qualified key here would let such a statement pass the fake and
        // fail on a real server.
        $qualified = $this->prefixed($table);

        // Declared before any clause is compiled, because every clause below is
        // validated against it: a qualifier is legal only if it names one of the
        // tables this statement actually reads.
        $this->tablesInScope = [$qualified];

        foreach ($joins as $join) {
            $this->tablesInScope[] = $this->prefixed($join['table']);
        }

        foreach ($rows as $index => $row) {
            foreach ($row as $column => $value) {
                if (str_contains((string) $column, '.')) {
                    continue;
                }

                $rows[$index][$qualified . '.' . $column] = $value;
            }
        }

        foreach ($joins as $join) {
            $rows = $this->applyJoin($rows, $join);
        }

        $tail = $remaining;

        // Clauses are pulled off the tail in the order SQL requires them, one
        // regex each. A single combined pattern would have to express the
        // optional-ness and the ordering at once, and the failure mode of
        // getting that subtly wrong is a WHERE that silently swallows the
        // ORDER BY — which is exactly the bug this method is here to catch.
        $remaining = trim($tail);
        $matches = null;
        $orders = [];
        $limit = null;
        $offset = 0;

        if (preg_match('/^where\s+(.+?)(?=\s+order\s+by\s+|\s+limit\s+\d|\s+offset\s+\d|$)/is', $remaining, $m) === 1) {
            $this->clauseInScope = 'where clause';
            $matches = $this->compileEqualityPredicate(trim($m[1]));
            $remaining = trim(substr($remaining, strlen($m[0])));
        }

        if (preg_match('/^order\s+by\s+(.+?)(?=\s+limit\s+\d|\s+offset\s+\d|$)/is', $remaining, $m) === 1) {
            $this->clauseInScope = 'order clause';
            $orders = $this->parseOrderBy(trim($m[1]));
            $remaining = trim(substr($remaining, strlen($m[0])));
        }

        // No clause validation happens past this point, so the label is reset -
        // otherwise a later statement reusing this instance would report its
        // failure against a clause it never had.
        $this->clauseInScope = 'field list';

        if (preg_match('/^limit\s+(\d+)/i', $remaining, $m) === 1) {
            $limit = (int) $m[1];
            $remaining = trim(substr($remaining, strlen($m[0])));
        }

        if (preg_match('/^offset\s+(\d+)/i', $remaining, $m) === 1) {
            $offset = (int) $m[1];
            $remaining = trim(substr($remaining, strlen($m[0])));
        }

        if ($remaining !== '') {
            throw new \LogicException('FakeWpdb could not parse the SELECT tail: ' . $tail);
        }

        if ($matches !== null) {
            $rows = array_values(array_filter($rows, $matches));
        }

        if ($orders !== []) {
            usort($rows, static function (array $a, array $b) use ($orders): int {
                foreach ($orders as [$column, $descending]) {
                    $left = $a[$column] ?? null;
                    $right = $b[$column] ?? null;

                    // Compared as numbers when both sides are numeric, as strings
                    // otherwise — which is what MySQL does for an int column and
                    // for a datetime column respectively.
                    $result = is_numeric($left) && is_numeric($right)
                        ? ((float) $left <=> (float) $right)
                        : strcmp((string) $left, (string) $right);

                    if ($result !== 0) {
                        return $descending ? -$result : $result;
                    }
                }

                return 0;
            });
        }

        if ($limit !== null) {
            $rows = array_slice($rows, $offset, $limit);
        } elseif ($offset > 0) {
            $rows = array_slice($rows, $offset);
        }

        return $rows;
    }

    /**
     * Join fetched rows by a single `ON` comparison.
     *
     * An inner join drops the left row when nothing matches; a left join keeps
     * it and fills every right-hand column with null, which is the whole
     * difference between the two and the reason a repository uses one or the
     * other deliberately.
     *
     * The right side is read whole and matched in PHP rather than indexed by the
     * join column. That is O(n*m) and would be wrong for a large table, but this
     * is a fixture whose tables have a handful of rows, and a straightforward
     * scan is far easier to read back against the SQL it is standing in for.
     *
     * @param list<array<string, mixed>>                          $rows
     * @param array{left: bool, table: string, first: string,
     *              operator: string, second: string}            $join
     *
     * @return list<array<string, mixed>>
     */
    private function applyJoin(array $rows, array $join): array
    {
        // The SQL names the table bare and the store keys it prefixed, so the
        // join's own spelling is resolved the same way `FROM` is.
        $right = $this->tables[$this->prefixed($join['table'])] ?? [];
        $joined = [];

        foreach ($rows as $row) {
            $matched = false;

            foreach ($right as $candidate) {
                // Columns are addressed by their bare name: the row store is flat
                // and a qualifier is a spelling the SQL carries, not a second
                // namespace to look in.
                $left = $row[$this->unqualify($join['second'])] ?? null;
                $rightside = $candidate[$this->unqualify($join['first'])] ?? null;

                if (!$this->joinValuesMatch($left, $rightside, $join['operator'])) {
                    continue;
                }

                $matched = true;
                $joined[] = $this->mergeJoinedRow($row, $candidate, $join['table']);
            }

            if (!$matched && $join['left']) {
                $joined[] = $this->mergeJoinedRow($row, [], $join['table']);
            }
        }

        return $joined;
    }

    /**
     * Compare two join operands the way MySQL's loose typing would.
     */
    private function joinValuesMatch(mixed $left, mixed $right, string $operator): bool
    {
        // A null operand never matches, including `null = null`: SQL's `=` is
        // unknown for a null, which is why an outer join's unfilled column is
        // reported as a missing stage rather than as a stage with a null id.
        if ($left === null || $right === null) {
            return false;
        }

        $bothNumeric = is_numeric($left) && is_numeric($right);
        $a = $bothNumeric ? (float) $left : (string) $left;
        $b = $bothNumeric ? (float) $right : (string) $right;

        return match ($operator) {
            '=', '!=', '<>', '<', '<=', '>', '>=' => match ($operator) {
                '=' => $a == $b,
                '!=', '<>' => $a != $b,
                '<' => $a < $b,
                '<=' => $a <= $b,
                '>' => $a > $b,
                '>=' => $a >= $b,
            },
            default => throw new \LogicException('FakeWpdb cannot evaluate join operator: ' . $operator),
        };
    }

    /**
     * Merge a joined table's columns into a row.
     *
     * The joined columns are keyed under the table's **prefixed** name, and under
     * nothing else. That is not a convenience: it is the one spelling MySQL
     * resolves. `FROM `wp_deals` INNER JOIN `wp_companies`` creates no alias, so
     * `companies.name` is error 1054 on a real server, and a row that also
     * carried a `companies.name` key would let such a query pass here and fail in
     * production.
     *
     * A bare column key is still written for a column the left side does not
     * already have, because `SELECT *` legitimately returns the joined table's
     * columns under their plain names.
     *
     * @param array<string, mixed> $left  The accumulated left-hand row.
     * @param array<string, mixed> $right A matching row from the joined table.
     * @param string               $table The joined table, as the SQL names it.
     *
     * @return array<string, mixed>
     */
    private function mergeJoinedRow(array $left, array $right, string $table): array
    {
        // Resolved to the store's prefixed key once, so the qualified keys written
        // below are the same spelling the `FROM` table uses.
        $qualified = $this->prefixed($table);

        foreach ($this->tables[$qualified][0] ?? [] as $column => $_) {
            if (!array_key_exists($column, $left)) {
                $left[$column] = $right[$column] ?? null;
            }

            $left[$qualified . '.' . $column] = $right[$column] ?? null;
        }

        return $left;
    }

    /**
     * The bare name of a table the store keys prefixed.
     */
    private function unprefixed(string $table): string
    {
        $table = trim($table, ' `');

        return str_starts_with($table, $this->prefix)
            ? substr($table, strlen($this->prefix))
            : $table;
    }

    /**
     * Strip the backticks from a possibly qualified identifier.
     *
     * `trim($s, ' `')` is not enough: it removes backticks from the ends only and
     * leaves the inner pair of a qualified name, so `` `leads`.`name` `` becomes
     * `` leads`.`name `` - a key that matches no column and silently falls
     * through to whatever else the lookup tries. Each part is unquoted on its
     * own instead.
     */
    private function normalizeIdentifier(string $identifier): string
    {
        $parts = explode('.', trim($identifier));

        return implode('.', array_map(
            static fn (string $part): string => trim($part, " `\t\n\r\0\x0B"),
            $parts
        ));
    }

    /**
     * Drop a table qualifier from a column reference.
     */
    private function unqualify(string $column): string
    {
        $column = trim($column, ' `');
        $dot = strrpos($column, '.');

        return $dot === false ? $column : trim(substr($column, $dot + 1), ' `');
    }

    /**
     * The name a table is stored under, from the bare name SQL uses.
     *
     * A statement names tables bare and the row store keys them prefixed, so
     * every lookup by table name - `FROM` and a `JOIN` alike - goes through here
     * rather than assuming which spelling it was handed.
     */
    private function prefixed(string $table): string
    {
        $table = trim($table, ' `');

        return str_starts_with($table, $this->prefix) ? $table : $this->prefix . $table;
    }

    /**
     * Parse an ORDER BY list into `[column, descending]` pairs, in precedence
     * order.
     *
     * A qualifier is validated against the statement's own tables, so a sort on a
     * column of a table the query never named fails here the way MySQL fails it.
     * Without that check the sort key resolves to null for every row, `usort`
     * compares `null` to `null`, and the statement silently returns insertion
     * order - a wrong answer that looks like a right one.
     *
     * @return list<array{0: string, 1: bool}>
     */
    private function parseOrderBy(string $clause): array
    {
        $orders = [];

        foreach (explode(',', $clause) as $term) {
            $term = trim($term);

            if ($term === '') {
                continue;
            }

            $descending = (bool) preg_match('/\s+desc\s*$/i', $term);
            $column = $this->normalizeIdentifier(
                preg_replace('/\s+(asc|desc)\s*$/i', '', $term) ?? $term
            );

            $dot = strpos($column, '.');

            if ($dot !== false) {
                $this->assertQualifierIsInScope(substr($column, 0, $dot));
            }

            $orders[] = [$column, $descending];
        }

        return $orders;
    }

    /**
     * Compile a WHERE clause into a row predicate.
     *
     * Supports the vocabulary the query builder can emit: an equality/comparison
     * conjunction (`` `a` = 1 ``, `` `a` != 'x' ``, `` `a` >= 3 ``), `LIKE` and
     * `NOT LIKE`, `IS NULL` / `IS NOT NULL`, `IN (…)` / `NOT IN (…)`, parenthesised
     * groups, and `OR` between terms at any of those levels.
     *
     * Deliberately narrow beyond that. Anything else throws rather than guessing,
     * because a fake that quietly accepted an unsupported clause would answer with
     * the wrong rows and make a broken query look correct.
     *
     * Columns may be bare, backquoted, table-qualified (`` `t`.`c` ``) or aliased;
     * the qualifier is dropped for lookup, which mirrors what a single-table read
     * of the fake's flat row store can meaningfully answer.
     *
     * @return callable(array<string, mixed>): bool
     */
    private function compileEqualityPredicate(string $clause): callable
    {
        $predicate = $this->parseOrPredicate(trim($clause));

        return static fn (array $row): bool => $predicate($row);
    }

    /**
     * Parse a term sequence joined by `OR`, where each term may itself be an
     * `AND` conjunction or a bracketed group.
     *
     * @return callable(array<string, mixed>): bool
     */
    private function parseOrPredicate(string $clause): callable
    {
        $terms = [];
        $depth = 0;
        $current = '';

        // Split on `OR` only at bracket depth zero, so an `OR` inside a group
        // stays with its group. A flat split would tear `(a OR b) AND c` apart.
        for ($i = 0, $length = strlen($clause); $i < $length; $i++) {
            $char = $clause[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($depth === 0 && $this->matchesKeywordAt($clause, $i, 'OR')) {
                $terms[] = trim($current);
                $current = '';
                $i += 1;

                continue;
            }

            $current .= $char;
        }

        $terms[] = trim($current);

        $predicates = array_map($this->parseAndPredicate(...), $terms);

        return static function (array $row) use ($predicates): bool {
            foreach ($predicates as $predicate) {
                if ($predicate($row)) {
                    return true;
                }
            }

            return false;
        };
    }

    /**
     * Parse a term sequence joined by `AND`, where each term may be a bracketed
     * group.
     *
     * @return callable(array<string, mixed>): bool
     */
    private function parseAndPredicate(string $clause): callable
    {
        $terms = $this->splitTopLevel($clause, 'AND');

        $predicates = array_map($this->parseTerm(...), $terms);

        return static function (array $row) use ($predicates): bool {
            foreach ($predicates as $predicate) {
                if (!$predicate($row)) {
                    return false;
                }
            }

            return true;
        };
    }

    /**
     * Compile one term: a bracketed group, or a single comparison.
     *
     * @return callable(array<string, mixed>): bool
     */
    private function parseTerm(string $term): callable
    {
        $term = trim($term);

        if ($term !== '' && $term[0] === '(' && str_ends_with($term, ')')) {
            return $this->parseOrPredicate(substr($term, 1, -1));
        }

        return $this->compileSingleComparison($term);
    }

    /**
     * Split a clause on a keyword that appears at bracket depth zero.
     *
     * @return list<string>
     */
    private function splitTopLevel(string $clause, string $keyword): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        for ($i = 0, $length = strlen($clause); $i < $length; $i++) {
            $char = $clause[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($depth === 0 && $this->matchesKeywordAt($clause, $i, $keyword)) {
                $parts[] = trim($current);
                $current = '';
                $i += strlen($keyword) - 1;

                continue;
            }

            $current .= $char;
        }

        $parts[] = trim($current);

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /**
     * Whether a standalone keyword begins at an offset, delimited by whitespace
     * or the string's bounds.
     */
    private function matchesKeywordAt(string $clause, int $offset, string $keyword): bool
    {
        $length = strlen($keyword);

        if (strcasecmp(substr($clause, $offset, $length), $keyword) !== 0) {
            return false;
        }

        $before = $offset === 0 ? ' ' : $clause[$offset - 1];
        $after = $clause[$offset + $length] ?? ' ';

        return ctype_space($before) && ctype_space($after);
    }

    /**
     * Compile one comparison term - equality, comparison, `LIKE`, `IS NULL` or
     * `IN`.
     *
     * @return callable(array<string, mixed>): bool
     */
    private function compileSingleComparison(string $term): callable
    {
        $column = '`?([a-z0-9_]+)`?(?:\.`?([a-z0-9_]+)`?)?';

        // IN / NOT IN
        if (preg_match('/^\s*' . $column . '\s+(not\s+)?in\s*\((.*)\)\s*$/is', $term, $m) === 1) {
            return $this->compileInPredicate($this->qualifiedName($m), $m[4], $this->negation($m));
        }

        // IS NULL / IS NOT NULL
        if (preg_match('/^\s*' . $column . '\s+is\s+(not\s+)?null\s*$/is', $term, $m) === 1) {
            $name = $this->qualifiedName($m);
            $negated = $this->negation($m);

            return fn (array $row): bool => $negated
                ? $this->rowValue($row, $name) !== null
                : $this->rowValue($row, $name) === null;
        }

        // LIKE / NOT LIKE
        if (preg_match('/^\s*' . $column . '\s+(not\s+)?like\s+(.+)$/is', $term, $m) === 1) {
            $name = $this->qualifiedName($m);
            $negated = $this->negation($m);
            $pattern = $this->literalValue(trim($m[4]), $term);

            return fn (array $row): bool => $this->likeMatches((string) $pattern, (string) $this->rowValue($row, $name)) !== $negated;
        }

        // Comparison
        if (preg_match('/^\s*' . $column . '\s*(=|!=|<>|<=|>=|<|>)\s*(.+?)\s*$/is', $term, $m) !== 1) {
            throw new \LogicException('FakeWpdb cannot parse WHERE term: ' . $term);
        }

        $name = $this->qualifiedName($m);
        $operator = $m[3];
        $value = $this->literalValue(trim($m[4]), $term);

        return fn (array $row): bool => $this->compare(
            $this->rowValue($row, $name),
            $value,
            $operator
        );
    }

    /**
     * Read a predicate's column out of a row.
     *
     * A bare name is the `FROM` table's own column and is read as written, which
     * is what makes `where('name', …)` keep meaning the left table's `name` once
     * a join is added. A qualified name is read from the `table.column` key a
     * join writes, and reads as null when that key is absent - a joined table
     * with no match has one, holding null, and the two are the same answer here.
     *
     * @param array<string, mixed> $row
     */
    private function rowValue(array $row, string $name): mixed
    {
        return $row[$name] ?? null;
    }

    /**
     * Drop the synthetic `table.column` keys from a returned row.
     *
     * Those keys are how the fake resolves a qualified reference against its flat
     * row store; they are not columns, and a real server never returns them.
     * `SELECT * FROM `wp_deals`` answers `id, name` and nothing else, so leaking
     * `wp_deals.id` here would hand a repository a row shape the database cannot
     * produce - and a caller iterating the row would see phantom columns.
     *
     * Stripping happens on the way out of {@see select()} only, after every
     * clause has been evaluated, because the clauses need the keys while they run.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function withoutQualifiedKeys(array $row): array
    {
        return array_filter(
            $row,
            static fn (string $column): bool => !str_contains($column, '.'),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Compare two values the way MySQL's loose typing would.
     *
     * A null on either side is unknown rather than false, which is why it is
     * answered before the operator is: `null = 1` and `null != 1` are both
     * unknown, and neither row matches.
     */
    private function compare(mixed $actual, mixed $value, string $operator): bool
    {
        if ($actual === null || $value === null) {
            return false;
        }

        // Loose comparison on purpose: `$wpdb->prepare('%d')` produces an
        // unquoted digit and the stored column may be a numeric string, so the
        // two must compare equal the way MySQL would.
        $bothNumeric = is_numeric($actual) && is_numeric($value);
        $left = $bothNumeric ? (float) $actual : (string) $actual;
        $right = $bothNumeric ? (float) $value : (string) $value;

        return match ($operator) {
            '=' => $left == $right,
            '!=', '<>' => $left != $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
            '>' => $left > $right,
            '>=' => $left >= $right,
            default => throw new \LogicException('FakeWpdb cannot evaluate operator: ' . $operator),
        };
    }

    /**
     * Whether a `NOT` was captured in the negation group of `IN`/`LIKE`/`IS`.
     *
     * The group sits at index 3 in each of those patterns, and PHP omits a
     * trailing unmatched group from the match array entirely - so it is read
     * through `??`, where a missing group means "not negated" rather than an
     * error.
     *
     * @param array<int, string> $matches
     */
    private function negation(array $matches): bool
    {
        return isset($matches[3]) && $matches[3] !== '';
    }

    /**
     * The column name from a comparison match, dropping any table qualifier.
     *
     * The pattern captures a bare name in group 1 and, when the term is
     * qualified, the column in group 2 and the table in group 1. A single-table
     * row store has nothing to qualify against, so the column is whichever group
     * carries it: the outer one is empty in the bare case and the qualifier in
     * the qualified one.
     *
     * @param array<int, string> $matches
     */
    private function qualifiedName(array $matches): string
    {
        // The qualifier is kept when the term carries one. Dropping it would make
        // `companies.name` and `leads.name` the same key, and the predicate would
        // then read whichever table's column the row happened to hold under the
        // bare name - the left table's, for a joined read.
        if (($matches[2] ?? '') === '') {
            return $matches[1];
        }

        $this->assertQualifierIsInScope($matches[1]);

        // The qualifier is returned exactly as written, because that is the only
        // spelling the check above can have accepted. It is also the key the row
        // store writes for a joined table, since `join()` in the builder prefixes
        // the same way `FROM` does.
        return $matches[1] . '.' . $matches[2];
    }

    /**
     * Fall over a qualifier that names a table the statement never mentioned.
     *
     * A real server answers error 1054 here, and the fake has to as well or it is
     * not standing in for one: a predicate qualified with a table that is not in
     * the `FROM`/`JOIN` list reads `null` from the flat row store, so the WHERE
     * quietly matches nothing instead of failing. A query that returns no rows
     * looks exactly like a query that found nothing.
     *
     * The comparison is **literal**: the qualifier as written is matched against
     * the table names as written, with no prefix applied to either. MySQL accepts
     * a qualifier only when it is the name its own `FROM`/`JOIN` gave the table -
     * `FROM `wp_deals`` binds `wp_deals` and creates no `deals` alias, so
     * `deals.id` is error 1054 even though the table is right there. Measured on
     * MySQL 5.7.33:
     *
     *     SELECT `wp_deals`.`id` FROM `wp_deals`;              -- rows
     *     SELECT `deals`.`id`    FROM `wp_deals`;              -- error 1054
     *     SELECT `deals`.`id`    FROM `wp_deals` AS `deals`;   -- rows (an alias)
     *
     * Mapping the qualifier through the prefix before comparing is the specific
     * leniency that let the production prefixing bug pass this fake, and it is
     * not repeated here.
     *
     * @param string $qualifier The table name written in the statement.
     * @param list<string>|null $scope Tables to check against; defaults to the
     *                                 statement's own, set once its `FROM`/`JOIN`
     *                                 have been parsed.
     *
     * @throws \LogicException When the qualifier is not one of the statement's tables.
     */
    private function assertQualifierIsInScope(string $qualifier, ?array $scope = null): void
    {
        if (in_array($qualifier, $scope ?? $this->tablesInScope, true)) {
            return;
        }

        throw new \LogicException(sprintf(
            "FakeWpdb: Unknown column '%s' in '%s' - no table named [%s] is in this statement's FROM/JOIN.",
            $qualifier,
            $this->clauseInScope,
            $qualifier
        ));
    }

    /**
     * The tables a statement reads, in the spelling its own `FROM`/`JOIN` gave them.
     *
     * Read out of the `FROM` table and each `JOIN` table without evaluating any
     * clause, so the projection can be validated before `selectRows()` runs and
     * independently of whether the statement returns any row.
     *
     * The names come back prefixed because that is how the builder writes them
     * into the statement, and they are compared literally against the qualifier
     * as written - never re-prefixed. See {@see assertQualifierIsInScope()}.
     *
     * @return list<string>
     */
    private function tablesInScopeFor(string $table, string $tail): array
    {
        $scope = [$this->prefixed($table)];

        preg_match_all(
            '/\b(?:inner|left)\s+join\s+`?([a-z0-9_]+)`?/i',
            $tail,
            $matches
        );

        foreach ($matches[1] as $joined) {
            $scope[] = $this->prefixed($joined);
        }

        return $scope;
    }

    /**
     * One term of a `SELECT` list, resolved to the key the row store uses.
     *
     * `qualifier` is kept separate from `source` so the caller can validate it
     * against the statement's tables before any row is read.
     *
     * @return array{source: ?string, fallback: ?string, qualifier: ?string, key: string}
     */
    private function projectionTerm(string $projection, ?string $alias, string $table): array
    {
        $source = $this->normalizeIdentifier($projection);
        $dot = strpos($source, '.');
        $qualifier = $dot === false ? null : substr($source, 0, $dot);
        $column = $dot === false ? $source : substr($source, $dot + 1);

        // The qualified key keeps the qualifier as written: it is matched against
        // the statement's tables literally (see assertQualifierIsInScope()), and
        // it is the same spelling the row store keys a joined table under.
        return [
            'source' => $dot === false ? null : $source,
            'fallback' => $this->prefixed($table) . '.' . $column,
            'qualifier' => $qualifier,
            'key' => $alias ?? $column,
        ];
    }

    /**
     * Compile an `IN`/`NOT IN` list into a row predicate.
     *
     * @return callable(array<string, mixed>): bool
     */
    private function compileInPredicate(string $column, string $list, bool $negated): callable
    {
        $values = [];

        foreach ($this->splitValues($list) as $value) {
            $values[] = $value;
        }

        return static function (array $row) use ($column, $values, $negated): bool {
            $actual = $row[$column] ?? null;

            $found = false;

            foreach ($values as $value) {
                if (is_numeric($actual) && is_numeric($value)) {
                    if ((float) $actual === (float) $value) {
                        $found = true;
                        break;
                    }

                    continue;
                }

                if ((string) $actual === (string) $value) {
                    $found = true;
                    break;
                }
            }

            return $found !== $negated;
        };
    }

    /**
     * Parse a WHERE literal - a quoted string or a number - for a comparison.
     *
     * @return string|int|float
     */
    private function literalValue(string $raw, string $term): string|int|float
    {
        if (strlen($raw) >= 2 && $raw[0] === "'" && str_ends_with($raw, "'")) {
            return str_replace("''", "'", substr($raw, 1, -1));
        }

        if (is_numeric($raw)) {
            return str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }

        throw new \LogicException('FakeWpdb cannot parse WHERE value: ' . $raw . ' (in: ' . $term . ')');
    }

    /**
     * Neutralise the `LIKE` metacharacters in a string bound to a pattern.
     *
     * The backslash is escaped *first*, necessarily: done after, it would double
     * the backslashes this method had just added and unescape the metacharacters
     * again. Mirrors `wpdb::esc_like()`, which is what {@see esc_like()} exposes.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $value);
    }

    /**
     * Match a MySQL LIKE pattern, honouring `%`, `_` and backslash escapes.
     *
     * Hand-rolled rather than converted to a regex character class, because the
     * escaping rule is the whole point: a backslash-escaped `_` must match a
     * literal underscore, and getting that wrong is exactly the bug this models.
     */
    private function likeMatches(string $pattern, string $subject): bool
    {
        $regex = '';

        for ($i = 0, $length = strlen($pattern); $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '\\' && $i + 1 < $length) {
                // The next character is literal, whatever it is.
                $regex .= preg_quote($pattern[++$i], '/');
                continue;
            }

            $regex .= match ($char) {
                '%' => '.*',
                '_' => '.',
                default => preg_quote($char, '/'),
            };
        }

        return preg_match('/^' . $regex . '$/s', $subject) === 1;
    }

    /**
     * Split a VALUES list, honouring quoted strings.
     *
     * @return list<mixed>
     */
    private function splitValues(string $list): array
    {
        $values = [];
        $current = '';
        $inString = false;

        for ($i = 0, $length = strlen($list); $i < $length; $i++) {
            $char = $list[$i];

            if ($char === "'") {
                // A doubled quote is an escaped quote, not a terminator.
                if ($inString && ($list[$i + 1] ?? '') === "'") {
                    $current .= "'";
                    $i++;

                    continue;
                }

                $inString = !$inString;

                continue;
            }

            if ($char === ',' && !$inString) {
                $values[] = $this->castValue($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $values[] = $this->castValue($current);

        return $values;
    }

    /**
     * A literal from an assignment or a values list, as a PHP value.
     *
     * `splitValues()` has already removed the surrounding quotes by the time it
     * calls this, but {@see splitAssignments()} keeps the clause verbatim, so the
     * quotes are stripped here when present. Doing it in one place means both
     * callers agree about how `'x'` and `1` are read.
     *
     * @return mixed
     */
    private function castValue(string $value)
    {
        $value = trim($value);

        if ($value === 'NULL') {
            return null;
        }

        if (strlen($value) >= 2 && $value[0] === "'" && str_ends_with($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }

        return is_numeric($value) ? (int) $value : $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildInsert(string $table, array $data): string
    {
        $columns = array_keys($data);
        $values = array_map(
            static function ($value): string {
                // A null *value* becomes the bare SQL keyword NULL. This mirrors
                // class-wpdb.php, where `insert()` checks `$value === null` and
                // substitutes 'NULL' *before* consulting the format string — so
                // the format never sees a null and never gets the chance to turn
                // one into `(int) null === 0`.
                //
                // Modelled rather than glossed because the difference is a real
                // bug in a caller: `%d` on a nullable column writes 0, which
                // attaches an entry to a row instead of leaving it unattached,
                // and the two are indistinguishable once stored.
                if ($value === null) {
                    return 'NULL';
                }

                return is_int($value) || is_float($value)
                    ? (string) $value
                    : "'" . str_replace("'", "''", (string) $value) . "'";
            },
            array_values($data)
        );

        return sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`, `', $columns) . '`',
            implode(', ', $values)
        );
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    private function buildUpdate(string $table, array $data, array $where): string
    {
        $render = static fn ($value): string => is_int($value) || is_float($value)
            ? (string) $value
            : "'" . str_replace("'", "''", (string) $value) . "'";

        $assignments = [];

        foreach ($data as $column => $value) {
            $assignments[] = "`{$column}` = " . $render($value);
        }

        $conditions = [];

        foreach ($where as $column => $value) {
            $conditions[] = "`{$column}` = " . $render($value);
        }

        return sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(', ', $assignments),
            implode(' AND ', $conditions)
        );
    }
}
