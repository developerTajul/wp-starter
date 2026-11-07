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
     * Every statement issued, in order. Lets a test assert that, say, no DELETE
     * ran after a failure.
     *
     * @var list<string>
     */
    public array $queries = [];

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
     */
    public function failOn(string $fragment): void
    {
        $this->failOn[] = $fragment;
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
            $this->last_error = 'Injected failure.';
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

        if (preg_match('/\bdefault\s+(\'((?:[^\']|\'\')*)\'|[^\s,]+)/i', $definition, $d) === 1) {
            $default = isset($d[2]) && $d[2] !== ''
                ? str_replace("''", "'", $d[2])
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
     * @return int|false
     */
    private function runInsert(string $sql)
    {
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
            return $this->selectRows($m[1], trim($m[2]));
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
            $matches = $this->compileEqualityPredicate(trim($m[1]));
            $remaining = trim(substr($remaining, strlen($m[0])));
        }

        if (preg_match('/^order\s+by\s+(.+?)(?=\s+limit\s+\d|\s+offset\s+\d|$)/is', $remaining, $m) === 1) {
            $orders = $this->parseOrderBy(trim($m[1]));
            $remaining = trim(substr($remaining, strlen($m[0])));
        }

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
     * Parse an ORDER BY list into `[column, descending]` pairs, in precedence
     * order.
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
            $column = trim(
                preg_replace('/\s+(asc|desc)\s*$/i', '', $term) ?? $term,
                " `\t\n\r\0\x0B"
            );

            $orders[] = [$column, $descending];
        }

        return $orders;
    }

    /**
     * Compile an equality conjunction — `\`a\` = 1`, `\`a\` = 'x'`,
     * `\`a\` = 1 AND \`b\` = 2` — into a row predicate.
     *
     * Deliberately narrow. Anything else throws rather than guessing, because a
     * fake that quietly accepted an unsupported clause would answer with the
     * wrong rows and make a broken query look correct.
     *
     * @return callable(array<string, mixed>): bool
     */
    private function compileEqualityPredicate(string $clause): callable
    {
        $conditions = [];

        foreach (preg_split('/\s+and\s+/i', $clause) ?: [] as $term) {
            if (preg_match('/^\s*`?([a-z0-9_]+)`?\s*=\s*(.+?)\s*$/is', $term, $m) !== 1) {
                throw new \LogicException('FakeWpdb cannot parse WHERE term: ' . $term);
            }

            $raw = trim($m[2]);

            if (strlen($raw) >= 2 && $raw[0] === "'" && str_ends_with($raw, "'")) {
                $value = str_replace("''", "'", substr($raw, 1, -1));
            } elseif (is_numeric($raw)) {
                $value = str_contains($raw, '.') ? (float) $raw : (int) $raw;
            } else {
                throw new \LogicException('FakeWpdb cannot parse WHERE value: ' . $raw);
            }

            $conditions[] = [$m[1], $value];
        }

        return static function (array $row) use ($conditions): bool {
            foreach ($conditions as [$column, $value]) {
                $actual = $row[$column] ?? null;

                // Loose comparison on purpose: `$wpdb->prepare('%d')` produces an
                // unquoted digit and the stored column may be a numeric string,
                // so the two must compare equal the way MySQL would.
                if (is_numeric($actual) && is_numeric($value)) {
                    if ((float) $actual !== (float) $value) {
                        return false;
                    }

                    continue;
                }

                if ((string) $actual !== (string) $value) {
                    return false;
                }
            }

            return true;
        };
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
