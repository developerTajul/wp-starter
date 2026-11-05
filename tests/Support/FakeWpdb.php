<?php
declare(strict_types=1);

namespace Tests\Support;

use wpdb;

/**
 * An in-memory stand-in for WordPress's `$wpdb`.
 *
 * This is not a mock: it is a small SQL engine covering exactly the statements
 * the migrator issues, backed by real arrays. The difference matters, because
 * the behaviour under test is largely *how the migrator interprets wpdb's return
 * values* — and those are the semantics easiest to get wrong. Each one below is
 * copied from `wp-includes/class-wpdb.php` rather than invented:
 *
 *  - `query()` returns `false` on error, `$rows_affected` for INSERT/UPDATE/
 *    DELETE, the raw result for CREATE/ALTER/TRUNCATE/DROP, and a row count for
 *    SELECT (class-wpdb.php:2308-2333). A test that makes a DELETE return 0 rows
 *    and a CREATE return 0 rows behave differently is testing what the migrator
 *    actually does.
 *  - `insert()` returns `false` on failure and does not throw (:2586-2617).
 *  - `get_var()` returns null when there is no result, so `MAX(batch)` over an
 *    empty table is null and not 0 — which is what makes "no batch" expressible.
 *  - `get_results()` returns an empty array for no rows and on error (:3179).
 *  - `last_error` is a public string, empty when nothing has gone wrong.
 *
 * Failures are injected by SQL fragment through {@see failOn()}, so a test can
 * make one specific statement fail and assert that the migrator stopped there.
 *
 * Queries are a hand-written subset. If the migrator grows a statement this class
 * does not understand, `query()` throws rather than silently returning 0 — a
 * silent 0 would look like a passing test.
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

        if (preg_match('/^\s*drop\s+table\s+`?([a-z0-9_]+)`?/i', $sql, $m) === 1) {
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
     * @return int
     */
    private function runDelete(string $sql)
    {
        if (preg_match('/^\s*delete\s+from\s+`?([a-z0-9_]+)`?\s+where\s+`?migration`?\s*=\s*\'(.*)\'\s*$/is', $sql, $m) !== 1) {
            throw new \LogicException('FakeWpdb cannot parse DELETE: ' . $sql);
        }

        $table = $m[1];
        $identifier = str_replace("''", "'", $m[2]);
        $before = count($this->tables[$table] ?? []);

        $this->tables[$table] = array_values(array_filter(
            $this->tables[$table] ?? [],
            static fn (array $row): bool => ($row['migration'] ?? null) !== $identifier
        ));

        return $before - count($this->tables[$table]);
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

        throw new \LogicException('FakeWpdb cannot parse SELECT: ' . $sql);
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
     * @return mixed
     */
    private function castValue(string $value)
    {
        $value = trim($value);

        if ($value === 'NULL') {
            return null;
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
            static fn ($value): string => is_int($value) || is_float($value)
                ? (string) $value
                : "'" . str_replace("'", "''", (string) $value) . "'",
            array_values($data)
        );

        return sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`, `', $columns) . '`',
            implode(', ', $values)
        );
    }
}
