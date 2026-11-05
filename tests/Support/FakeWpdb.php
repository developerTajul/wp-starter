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

        $this->columnCache[$table] = $columns;
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
        if (preg_match('/^\s*(create|alter|truncate|drop)\s/i', $sql) === 1) {
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
     * @return mixed
     */
    private function runDdl(string $sql)
    {
        if (preg_match('/^\s*create\s+table\s+`?([a-z0-9_]+)`?/i', $sql, $m) === 1) {
            $table = $m[1];

            preg_match_all('/^\s*`([a-z0-9_]+)`\s+(bigint|varchar|int|tinyint)/im', $sql, $cols);

            $this->createTable($table, $cols[1] !== [] ? $cols[1] : ['id', 'migration', 'batch']);

            return 0;
        }

        if (preg_match('/^\s*alter\s+table\s+`?([a-z0-9_]+)`?\s+add\s+column\s+`?([a-z0-9_]+)`?/i', $sql, $m) === 1) {
            $this->columnCache[$m[1]][] = $m[2];

            foreach ($this->tables[$m[1]] ?? [] as $i => $row) {
                $this->tables[$m[1]][$i][$m[2]] = 0;
            }

            return 0;
        }

        // `IF EXISTS` is optional and precedes the name. Matched rather than left
        // to fail, because a migration's `down()` writes it precisely so that a
        // half-applied rollback can be re-run — and a fake that rejected the
        // spelling would make a correct migration look broken.
        if (preg_match('/^\s*drop\s+table\s+(?:if\s+exists\s+)?`?([a-z0-9_]+)`?/i', $sql, $m) === 1) {
            unset($this->tables[$m[1]], $this->columnCache[$m[1]], $this->autoIncrement[$m[1]]);

            return 0;
        }

        throw new \LogicException('FakeWpdb cannot execute DDL: ' . $sql);
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

        if (preg_match('/^\s*show\s+columns\s+from\s+`?([a-z0-9_]+)`?/i', $sql, $m) === 1) {
            $table = $m[1];

            return array_map(
                static fn (string $column): array => ['Field' => $column],
                $this->columnCache[$table] ?? []
            );
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
