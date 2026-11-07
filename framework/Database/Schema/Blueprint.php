<?php
declare(strict_types=1);

namespace Framework\Database\Schema;

use Framework\Database\Adapters\DatabaseAdapterInterface;
use Framework\Database\Schema\Exceptions\SchemaException;

use function count;
use function implode;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;
use function str_replace;
use function strlen;

/**
 * One table's definition, built up one chained call at a time.
 *
 *     Schema::create('notes', function (Blueprint $table) {
 *         $table->id();
 *         $table->string('title');
 *         $table->text('content');
 *         $table->timestamps();
 *     });
 *
 * ## What this class is, and is not
 *
 * It is a **description**: a list of columns, and a name to put them under. It
 * holds no connection, issues no statement, and knows no SQL dialect. Compiling
 * the description into a statement is the adapter's job
 * ({@see DatabaseAdapterInterface}), which is what lets the same blueprint
 * create a table on MySQL and be asserted against in a test with nothing
 * running underneath it.
 *
 * That separation is also why the fluent methods return `$this`: chaining is
 * just an ergonomic way to append to the list, and a chain that ended at the
 * first column would be a builder that could only ever define one.
 *
 * ## The table is not created when the closure returns
 *
 * `Schema::create()` owns execution — it runs the callback and then hands the
 * finished blueprint to the adapter. A blueprint that executed eagerly would
 * issue a statement per call and leave a half-created table behind when the
 * third column was the one with the typo.
 *
 * ## Two ways to build a table, and why both exist
 *
 * {@see Schema::create()} starts from nothing and emits one `CREATE TABLE`.
 * {@see Schema::table()} is handed a table that already exists and each column
 * added to it becomes its own `ALTER TABLE ... ADD COLUMN`. The distinction is
 * not bookkeeping: `CREATE TABLE` names only the tables it creates, so a column
 * added to an *existing* table is a statement the original `CREATE` was never
 * going to produce, and — for the WordPress adapter — a statement `dbDelta()`
 * cannot reach, since its diff only ever consults tables the statement creates.
 *
 * ## Values are never interpolated, identifiers are
 *
 * Column names are validated ({@see assertIdentifier()}) because they are
 * grammar — they cannot be bound as parameters and so must be known-safe.
 * Defaults *are* values, and they are escaped as string literals here rather
 * than bound, because a `DEFAULT` clause is part of the schema and a prepared
 * statement cannot carry one. See {@see compileDefault()}.
 */
class Blueprint
{
    /**
     * The column types this builder can express, mapped to the SQL a MySQL-
     * family database uses for them.
     *
     * A closed set on purpose. A `raw()`-style escape hatch would let any string
     * through, and the first thing it would let through is a migration whose
     * column type is a typo — which fails at *migration* time on a developer's
     * machine and at *deploy* time on the operator's, if it fails at all.
     */
    private const TYPES = [
        'bigint' => 'bigint(20)',
        'integer' => 'int(11)',
        'string' => 'varchar',
        'text' => 'longtext',
        'boolean' => 'tinyint(1)',
        'datetime' => 'datetime',
    ];

    /**
     * The longest `varchar` MySQL will index on utf8mb4 — 191 characters times
     * four bytes stays inside the 767-byte limit a single-column index has on
     * the older row formats WordPress still supports.
     */
    public const MAX_INDEXED_STRING_LENGTH = 191;

    private const MAX_VARCHAR_LENGTH = 65535;

    /**
     * The columns defined so far, each already compiled to an SQL fragment.
     *
     * @var list<array{name: string, definition: string}>
     */
    private array $columns = [];

    /**
     * Secondary indexes, each already compiled to an SQL fragment.
     *
     * @var list<string>
     */
    private array $indexes = [];

    /**
     * Whether each column carries a default, by column name.
     *
     * Consulted by {@see nullable()} because the *order* of
     * `->default(0)->nullable()` and `->nullable()->default(0)` must not change
     * the result — so the answer comes from the column's current definition
     * rather than from a flag flipped by whichever call ran last.
     *
     * @var array<string, bool>
     */
    private array $hasDefault = [];

    /**
     * `ALTER TABLE` actions that are neither a column nor a constraint, already
     * compiled — index drops, in practice.
     *
     * Kept apart from {@see $columns} because the statement shapes differ: a
     * column is `ADD COLUMN x ...` and one of these is a complete action clause.
     *
     * @var list<string>
     */
    private array $alterations = [];

    /**
     * Columns added by {@see foreignId()} that are still awaiting a
     * `constrained()` call, by column name.
     *
     * `constrained()` is a separate call from `foreignId()` — and its optional
     * argument means it is sometimes separated from it by nothing at all — so
     * the column it applies to is the most recent one, and whether it has one
     * is the question this answers. A `foreignId()` with no `constrained()`
     * after it is a legitimate unsigned big integer column with no constraint,
     * which is why this is a record rather than a pending obligation.
     *
     * @var array<string, true>
     */
    private array $foreignColumns = [];

    /**
     * The table the last `foreignId()` referred to, derived from its column
     * name, and overridden by `constrained()` when it is given one.
     */
    private ?string $pendingForeignKeyTable = null;

    /**
     * Foreign keys to emit, each already compiled to an SQL fragment.
     *
     * Separate from {@see $indexes} because MySQL treats them differently: a
     * `CONSTRAINT ... FOREIGN KEY` creates its own index implicitly, and adding
     * an explicit `KEY` over the same column would be a second B-tree over the
     * same data. A migration that asks for both — which the Laravel originals
     * do, since Laravel's `foreignId()->constrained()` emits only the
     * constraint — therefore ends up with one index, as it should.
     *
     * @var list<string>
     */
    private array $constraints = [];

    /**
     * Columns defined with a type that cannot carry an index, by the reason.
     *
     * Filled by the type methods rather than derived from the compiled
     * definition, because `KEY (content)` on a `longtext` column is rejected by
     * MySQL at creation — and a caller asking for one has a design problem worth
     * reporting, not a statement worth emitting and letting the database refuse
     * with a message about key length.
     *
     * @var array<string, string>
     */
    private array $unindexable = [];

    /**
     * Columns that cannot carry a `DEFAULT`, by the reason.
     *
     * Populated by the type methods, consulted by {@see default()}. Three types
     * need it and each for a different reason: `json` because MySQL rejects the
     * clause outright, `enum`/`set` because MySQL accepts a default outside the
     * member list and silently stores the empty member instead of refusing it.
     *
     * Refused at build time rather than emitted, because both failures are
     * invisible from the migration source: the first is a statement error the
     * database reports without naming the column, and the second is not an error
     * at all — it is a schema that accepts a value the application never meant to
     * allow.
     *
     * @var array<string, string>
     */
    private array $noDefault = [];

    /**
     * Resolves a table name from how a schema names it to how the database
     * spells it — the prefix, plus whatever else the target needs.
     *
     * A callable rather than the adapter itself, so the blueprint keeps the one
     * dependency it actually has (a way to name a table) instead of the whole
     * database seam. It is also what makes the class constructible in a test
     * with `fn (string $t): string => 'wp_' . $t` and nothing else.
     *
     * @var callable(string): string
     */
    private $resolveTable;

    /**
     * Reads an existing column's definition back from the database.
     *
     * Needed by {@see renameColumn()}, and by nothing else. MySQL's `CHANGE
     * COLUMN` makes the caller restate the definition, and the whole point of a
     * rename is that the caller gave two *names* — so the definition has to come
     * from the target rather than from the caller or from a schema-introspection
     * dependency this framework deliberately does not carry.
     *
     * Returns null when the column is absent, which a rename treats as an error
     * rather than as "invent a definition".
     *
     * @var callable(string, string): (string|null)
     */
    private $resolveColumnDefinition;

    /**
     * @param string   $table    The bare table name, with no prefix. Resolved by
     *                           the adapter at compilation time, so one blueprint
     *                           describes the same table on two installs whose
     *                           prefixes differ.
     * @param bool     $creating True for a new table, false to add to an existing
     *                           one. See the class docblock.
     * @param callable(string): string|null $resolveTable How a referenced table
     *                           name is spelled in a statement. Defaults to
     *                           identity, which is correct for a target with no
     *                           notion of prefixes.
     */
    public function __construct(
        public readonly string $table,
        private readonly bool $creating = true,
        ?callable $resolveTable = null,
        ?callable $resolveColumnDefinition = null
    ) {
        $this->resolveTable = $resolveTable ?? static fn (string $name): string => $name;
        $this->resolveColumnDefinition = $resolveColumnDefinition
            ?? static fn (string $table, string $column): ?string => null;
    }

    /**
     * Whether this blueprint describes a new table or an addition to one.
     *
     * Read by {@see Schema} to choose between creating and altering, and by the
     * column-building code to reject the one thing that is legal in a `CREATE`
     * and illegal in an `ALTER`.
     */
    public function isCreating(): bool
    {
        return $this->creating;
    }

    // --------------------------------------------------------------- columns

    /**
     * An auto-incrementing unsigned big integer primary key.
     *
     * The default key for a new table, and deliberately not offered when adding
     * to an existing one: MySQL permits exactly one auto-increment column per
     * table, so `$table->id()` in a `Schema::table()` block is a request the
     * database will always refuse. Refused here instead, where the message can
     * say why.
     *
     * @throws SchemaException When the blueprint is adding to an existing table.
     */
    public function id(string $column = 'id'): static
    {
        if (!$this->creating) {
            throw SchemaException::unsupportedColumn(
                $column,
                $this->table,
                'a table has at most one auto-increment column, so an existing table cannot be given '
                . 'a second one. Create the table with it, or add a plain unsignedBigInteger() column.'
            );
        }

        return $this->addColumn($column, 'bigint(20) unsigned NOT NULL AUTO_INCREMENT', primary: true);
    }

    /**
     * A variable-length string column.
     *
     * ## The default is 191, not 255
     *
     * Laravel defaults this to 255, and 255 is not indexable on utf8mb4 — 255
     * characters times four bytes overruns the 767-byte limit a single-column
     * index has on the row formats WordPress still supports. Laravel gets away
     * with it by having applications set a shorter default in a service
     * provider; a framework that has not been told that should not assume it.
     *
     * So the default is the length that always works. A column that genuinely
     * needs 255 asks for it — `$table->string('title', 255)` — and is still
     * allowed, because most columns are never indexed.
     *
     * ## What is refused, and when
     *
     * Above 65535 the type is not a `varchar` at all, so that is refused here.
     * Over {@see MAX_INDEXED_STRING_LENGTH} is *not* refused, because the column
     * may never be indexed — the refusal belongs at the index, which is where it
     * happens ({@see assertIndexable()}).
     *
     * @throws SchemaException When the length cannot be a varchar at all.
     */
    public function string(string $column, int $length = self::MAX_INDEXED_STRING_LENGTH): static
    {
        if ($length < 1 || $length > self::MAX_VARCHAR_LENGTH) {
            throw new SchemaException(sprintf(
                'Column [%s] on table [%s] was given a length of %d, which varchar() cannot take. '
                . 'Use a length between 1 and %d, or text() for an unbounded column.',
                $column,
                $this->table,
                $length,
                self::MAX_VARCHAR_LENGTH
            ));
        }

        if ($length > self::MAX_INDEXED_STRING_LENGTH) {
            $this->unindexable[$column] = sprintf(
                'a varchar(%d) column is longer than the %d characters a single-column index can hold '
                . 'on utf8mb4 (191 x 4 bytes = the 767-byte limit), so indexing it would be rejected. '
                . 'Shorten the column, or index a separate one.',
                $length,
                self::MAX_INDEXED_STRING_LENGTH
            );
        }

        return $this->addColumn($column, sprintf('varchar(%d) NOT NULL', $length));
    }

    /**
     * A `longtext` column.
     *
     * `longtext` rather than `text` for every case, matching what the project's
     * hand-written migrations chose: the two are the same type to a reader and
     * wildly different at 65KB, and the 4-byte-per-row overhead of the larger
     * one is not a reason to have a note body truncated in production.
     *
     * MySQL will not index a `longtext` column without a prefix length, so no
     * index is emitted for one — a `KEY` on it would be rejected at creation.
     */
    public function text(string $column): static
    {
        $this->unindexable[$column] = sprintf(
            'MySQL cannot index a longtext column without a prefix length, so a KEY on [%s] would be '
            . 'rejected at creation. Index a separate, shorter column instead — a hash of the content, '
            . 'or its first 191 characters in a varchar().',
            $column
        );

        return $this->addColumn($column, 'longtext NOT NULL');
    }

    /**
     * A `longtext` column, spelled out.
     *
     * The same type {@see text()} emits. It exists so a *dumper* has a name to
     * reach for that says what it means: a `SHOW COLUMNS` reports `longtext`, and
     * a reconstruction that had to call it `text()` would read as a deliberate
     * downgrade to anyone comparing the two.
     *
     * Unindexable, for the same reason and with the same message — the two
     * methods emit the identical definition and must therefore carry identical
     * guards.
     */
    public function longText(string $column): static
    {
        return $this->text($column);
    }

    /**
     * A JSON column.
     *
     * MySQL's native `json` type, not a `longtext` that happens to hold JSON.
     * The difference is not cosmetic: `json` validates on write, so a malformed
     * document is rejected at the statement rather than discovered by whatever
     * reads the column months later — and it normalises key order and whitespace,
     * so two documents that differ only in formatting compare equal.
     *
     * **No default, ever.** MySQL does not permit a `DEFAULT` on a `json` column
     * (before 8.0.13 it rejects the clause outright; after, only an expression
     * default is allowed), so {@see default()} refuses to apply to one rather
     * than emitting a statement the database will reject. Make the column
     * `nullable()` and let absence mean "no document".
     */
    public function json(string $column): static
    {
        $this->noDefault[$column] = 'MySQL does not accept a DEFAULT clause on a json column. '
            . 'Make the column nullable() and let NULL mean "no document".';

        return $this->addColumn($column, 'json NOT NULL');
    }

    /**
     * An `ENUM` column over a fixed list of values.
     *
     * ## Why this is worth having despite the reputation
     *
     * `ENUM` is genuinely awkward to change — adding a value is an `ALTER TABLE`
     * that rewrites the table on older MySQL — and it is still the right type for
     * a value whose set is closed and known: a status, a role, a direction. The
     * alternative, a `varchar(191)` with the valid values in application code,
     * has the same alteration problem plus no enforcement, so a typo writes a
     * value no reader expects and nothing rejects it.
     *
     * ## Values are escaped, never interpolated raw
     *
     * Each value becomes a single-quoted SQL literal through {@see quote()}, the
     * same path a {@see default()} takes, so a value containing a quote cannot
     * break out of the list. They are also checked for length: MySQL's limit is
     * 255 *total* bytes across the list, and going over is a statement error
     * rather than a truncation, so it is reported here where the list is visible.
     *
     * @param list<string> $values The permitted values, in the order MySQL stores
     *                             them — which is the order a `ORDER BY` on the
     *                             column sorts by, and is not alphabetical.
     *
     * @throws SchemaException When the list is empty, has non-string members, or
     *                         exceeds MySQL's 255-byte total.
     */
    public function enum(string $column, array $values): static
    {
        return $this->enumLike('enum', $column, $values);
    }

    /**
     * A `SET` column: a subset of a fixed list, stored as one value.
     *
     * The multi-select counterpart to {@see enum()}, and it carries the same
     * trade-off further: a `SET` is at most 64 members, and querying one is a
     * `FIND_IN_SET()` rather than an index-friendly equality. It is worth having
     * because the alternative — a comma-joined `varchar` — makes the same query
     * *and* loses enforcement.
     *
     * MySQL stores a `SET` as a bitmask, so `ORDER BY` on the column sorts by
     * the declared order, not alphabetically. Same rules, same escaping and the
     * same 255-byte ceiling as {@see enum()}.
     *
     * @param list<string> $values The permitted members. At most 64.
     *
     * @throws SchemaException When the list is empty, too long, or not strings.
     */
    public function set(string $column, array $values): static
    {
        if (count($values) > 64) {
            throw new SchemaException(sprintf(
                'Column [%s] on table [%s] was given %d set values, but MySQL stores a SET as a '
                . '64-bit mask and accepts at most 64. Use a json column or a related table instead.',
                $column,
                $this->table,
                count($values)
            ));
        }

        return $this->enumLike('set', $column, $values);
    }

    /**
     * A fixed-length `CHAR` column.
     *
     * Not a `varchar` with a short length, and the distinction is load-bearing
     * for exactly two kinds of value: a fixed-width code — a country code, a
     * currency code, a hash — where the padding is meaningful and an index over
     * it is denser than one over a variable-length column.
     *
     * `CHAR` right-pads with spaces and strips them on read, so `'ab '` and `'ab'`
     * compare equal. That is the point for a code and a trap for anything else,
     * which is why the length is required rather than defaulted: the caller has
     * to know what width the value really is.
     *
     * @throws SchemaException When the length is outside MySQL's 0–255 range.
     */
    public function char(string $column, int $length): static
    {
        if ($length < 1 || $length > 255) {
            throw new SchemaException(sprintf(
                'Column [%s] on table [%s] was given char(%d), but MySQL accepts a CHAR length '
                . 'between 1 and 255. Use string() for a longer variable-length column.',
                $column,
                $this->table,
                $length
            ));
        }

        return $this->addColumn($column, sprintf('char(%d) NOT NULL', $length));
    }

    /**
     * A `CHAR(36)` holding a UUID.
     *
     * Stored as text rather than as `BINARY(16)`, deliberately. The binary form
     * is half the size and a UUID is compared by equality in the overwhelming
     * majority of cases, so the storage argument is real — but it makes the
     * column unreadable in every `SELECT`, every log line and every manual
     * repair, and it puts a conversion between the application and the database
     * that has to be applied identically on both sides of every comparison. For
     * a plugin table that a human will inspect, readable wins.
     *
     * The collation note matters: at `utf8mb4`, `CHAR(36)` is 144 bytes, well
     * inside the 767-byte index limit, so a `UNIQUE` over it is fine.
     */
    public function uuid(string $column): static
    {
        return $this->addColumn($column, 'char(36) NOT NULL');
    }

    /**
     * A `CHAR(26)` holding a ULID.
     *
     * 26 characters is Crockford base32: 48 bits of millisecond timestamp, 80
     * bits of randomness. Not padded to the next power of two, because the type
     * is fixed-width by definition.
     *
     * @see uuid() for why this is text rather than a binary encoding.
     */
    public function ulid(string $column): static
    {
        return $this->addColumn($column, 'char(26) NOT NULL');
    }

    /**
     * The shared body of `enum()` and `set()`.
     *
     * Both are a quoted list under a different keyword, and both are subject to
     * the same escaping and the same 255-byte total. One implementation means a
     * fix to either cannot miss the other.
     *
     * @param 'enum'|'set' $type
     * @param list<string> $values
     */
    private function enumLike(string $type, string $column, array $values): static
    {
        $this->assertIdentifier($column, 'column');

        if ($values === []) {
            throw new SchemaException(sprintf(
                'Column [%s] on table [%s] was given an empty %s list. An %s with no permitted '
                . 'values cannot be written to at all — name at least one, or use string().',
                $column,
                $this->table,
                strtoupper($type),
                strtoupper($type)
            ));
        }

        $quoted = [];
        $totalBytes = 0;

        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new SchemaException(sprintf(
                    'Column [%s] on table [%s] was given a %s value that is not a string. '
                    . '%s members are stored as text and every one must be a non-empty string.',
                    $column,
                    $this->table,
                    strtoupper($type),
                    strtoupper($type)
                ));
            }

            if ($value === '') {
                // An empty member is a value with no spelling, and it collides
                // with the empty string MySQL uses for "no member selected".
                throw new SchemaException(sprintf(
                    'Column [%s] on table [%s] was given an empty %s value. An empty member is '
                    . 'indistinguishable from "nothing selected", so it cannot be one.',
                    $column,
                    $this->table,
                    strtoupper($type)
                ));
            }

            $totalBytes += strlen($value);

            $quoted[] = self::compileDefault($value);
        }

        // MySQL's own limit on the combined length of an ENUM/SET definition.
        // Checked here rather than left to the database because MySQL's error
        // ("Column length too big") does not name which column or by how much.
        if ($totalBytes > 255) {
            throw new SchemaException(sprintf(
                'Column [%s] on table [%s] was given %d bytes of %s values, but MySQL stores the '
                . 'list in a column definition limited to 255 bytes. Use fewer or shorter values, '
                . 'or a related table.',
                $column,
                $this->table,
                $totalBytes,
                strtoupper($type)
            ));
        }

        $this->noDefault[$column] = sprintf(
            'A %s column takes no DEFAULT: MySQL compares a default against the member list at '
            . 'write time and stores a value outside the list as the empty member rather than '
            . 'refusing it, so a wrong default is silently wrong. Set it in the application, or '
            . 'make the column nullable().',
            strtoupper($type)
        );

        return $this->addColumn($column, sprintf('%s(%s) NOT NULL', $type, implode(', ', $quoted)));
    }

    /**
     * A `MEDIUMTEXT` column — up to 16MB.
     *
     * Between {@see text()}'s `longtext` (4GB) and a `varchar` (65KB). Worth
     * having because the difference is real at the row level: `longtext` stores a
     * 4-byte length prefix and is otherwise identical, but choosing it for a
     * column known to hold a few kilobytes states an intent the reader can act
     * on. A `mediumtext` column is also indexable with a prefix length where a
     * `longtext` one is not.
     */
    public function mediumText(string $column): static
    {
        $this->unindexable[$column] = sprintf(
            'MySQL cannot index a mediumtext column without a prefix length, so a KEY on [%s] would '
            . 'be rejected at creation. Index a shorter column, or its first 191 characters in a '
            . 'varchar().',
            $column
        );

        return $this->addColumn($column, 'mediumtext NOT NULL');
    }

    /**
     * A `TINYTEXT` column — up to 255 bytes.
     *
     * The `varchar(255)` counterpart, and the reason to prefer it is the same one
     * that makes `text()` a `longtext`: it is stored off-page past a threshold,
     * so a row full of short values stays small. For anything a developer would
     * also want to index, `string()` is the better choice.
     */
    public function tinyText(string $column): static
    {
        $this->unindexable[$column] = sprintf(
            'MySQL cannot index a tinytext column without a prefix length, so a KEY on [%s] would '
            . 'be rejected at creation. Use string() if you need to index it.',
            $column
        );

        return $this->addColumn($column, 'tinytext NOT NULL');
    }

    /**
     * A single-precision float.
     *
     * For a value that is genuinely approximate and where the storage matters —
     * a coordinate, a measurement. **Not for money**: binary floating point
     * cannot represent 0.10 exactly, so a sum of float values drifts. Use
     * {@see decimal()} for anything that has to add up.
     */
    public function float(string $column): static
    {
        return $this->addColumn($column, 'float NOT NULL');
    }

    /**
     * A double-precision float. The wider counterpart to {@see float()}, and
     * subject to the same warning about money.
     */
    public function double(string $column): static
    {
        return $this->addColumn($column, 'double NOT NULL');
    }

    /**
     * A `YEAR` column, holding a four-digit year.
     *
     * One byte rather than the four a `smallint` would take, and it rejects an
     * out-of-range value at write time rather than storing `0000` for a typo.
     * Distinct from an `integer` holding a year, which is what most schemas reach
     * for and which stores four times as much to gain nothing.
     */
    public function year(string $column): static
    {
        return $this->addColumn($column, 'year(4) NOT NULL');
    }

    /**
     * A `TIME` column, holding a clock time with no date.
     *
     * The counterpart to {@see date()}. `TIME` also accepts values past 24 hours
     * and negative values — MySQL uses it for elapsed durations as well as for
     * clock times — so a schedule column and a duration column can share the
     * type. If the value is genuinely a span, that is worth a comment.
     */
    public function time(string $column): static
    {
        return $this->addColumn($column, 'time NOT NULL');
    }

    /**
     * A `BLOB` column, holding raw bytes.
     *
     * `blob` rather than `longblob`: the same reasoning as {@see text()}, in
     * reverse — this one is chosen for a value known to be small. MySQL's
     * `blob` is binary and has no character set, so it neither validates nor
     * converts on read, which is what makes it right for a hash, a key or an
     * encoded payload and wrong for anything a human reads.
     *
     * No `default()`, because MySQL refuses one on a blob column — the same
     * clause it refuses on `json`, for the same reason: there is no literal
     * syntax for the value.
     */
    public function binary(string $column): static
    {
        $this->noDefault[$column] = 'MySQL does not accept a DEFAULT clause on a blob column. '
            . 'Write the value in the application, or make the column nullable().';

        return $this->addColumn($column, 'blob NOT NULL');
    }

    /**
     * A fixed-width `BINARY(n)` column, padded with `\0` to exactly `n` bytes.
     *
     * **Not the same type as {@see binary()}**, and the difference is not
     * cosmetic. `BLOB` is variable-width with a two-byte length prefix and
     * compares by content; `BINARY(n)` is fixed-width, pads on the right, and
     * compares by the padded bytes. A `BINARY(16)` holding a UUID and a `BLOB`
     * holding the same 16 bytes are different storage and can compare unequal
     * against the same literal, so the two cannot stand in for one another.
     *
     * The length is required rather than defaulted: MySQL has no meaningful
     * default for `BINARY`, and a wrong one silently truncates or pads — both of
     * which change the value rather than failing. Sixteen is the common case (a
     * UUID, or the output of `INET6_ATON()`) but it is not the only one, so it is
     * the caller's to name.
     *
     * `default()` is refused for the same reason as `binary()`: there is no
     * literal syntax for a binary value, so a default the builder could emit
     * would be read as a string of characters rather than bytes.
     */
    public function binaryFixed(string $column, int $length): static
    {
        $this->assertPositiveLength($column, $length, 'BINARY');

        $this->noDefault[$column] = 'MySQL does not accept a DEFAULT clause on a binary column. '
            . 'Write the value in the application, or make the column nullable().';

        return $this->addColumn($column, sprintf('binary(%d) NOT NULL', $length));
    }

    /**
     * A variable-width `VARBINARY(n)` column, up to `n` bytes.
     *
     * The variable-width counterpart to {@see binaryFixed()}, and the right choice
     * for a value whose length varies — a hash, a token, an encrypted payload.
     * Like `BLOB` it is variable-width, but unlike `BLOB` it carries an explicit
     * maximum the database enforces on write, which is the difference that
     * matters for an indexed column.
     */
    public function varbinary(string $column, int $length): static
    {
        $this->assertPositiveLength($column, $length, 'VARBINARY');

        $this->noDefault[$column] = 'MySQL does not accept a DEFAULT clause on a binary column. '
            . 'Write the value in the application, or make the column nullable().';

        return $this->addColumn($column, sprintf('varbinary(%d) NOT NULL', $length));
    }

    /**
     * A `BINARY(16)` column holding a UUID, the binary counterpart of
     * {@see uuid()}.
     *
     * Sixteen bytes rather than `CHAR(36)`. {@see uuid()} argues for the readable
     * form and that argument stands for a table a human inspects; this exists for
     * the case where it does not — a join table, or a column indexed and compared
     * in bulk — where the storage and the comparison cost are the point. The
     * caller takes on the conversion between the textual and binary forms, which
     * has to be applied identically on every read and every write; that is the
     * whole cost of choosing this.
     *
     * A named method rather than `binaryFixed($c, 16)` so the intent is legible in
     * a migration and so the length cannot be got wrong by a digit.
     */
    public function binaryUuid(string $column): static
    {
        return $this->binaryFixed($column, 16);
    }

    /**
     * Refuse a length MySQL cannot accept, at the point the mistake is made.
     *
     * `BINARY(0)` and `VARBINARY(0)` are rejected by MySQL with a message about
     * the column type, which names the symptom and not the call that produced it.
     * A negative length is worse: it reaches the statement as `binary(-1)` and
     * fails as a syntax error attributed to the whole CREATE.
     */
    private function assertPositiveLength(string $column, int $length, string $type): void
    {
        if ($length < 1) {
            throw new SchemaException(sprintf(
                'Cannot create column [%s]: %s needs a length of at least 1, got %d.',
                $column,
                $type,
                $length
            ));
        }
    }

    /**
     * A `VARCHAR(45)` holding an IPv4 or IPv6 address.
     *
     * 45 is the longest textual IPv6 form —
     * `0000:0000:0000:0000:0000:ffff:255.255.255.255` — so the column fits either
     * family without a length the caller has to reason about. Text rather than
     * MySQL's `INET6_ATON()` binary form, for the same readability reason
     * {@see uuid()} is text: an address in a `SELECT` should be legible.
     */
    public function ipAddress(string $column): static
    {
        return $this->addColumn($column, 'varchar(45) NOT NULL');
    }

    /**
     * A `VARCHAR(17)` holding a MAC address in the usual colon-separated form.
     *
     * 17 is exactly `aa:bb:cc:dd:ee:ff`, so nothing shorter fits and nothing
     * longer is a MAC address in that spelling.
     */
    public function macAddress(string $column): static
    {
        return $this->addColumn($column, 'varchar(17) NOT NULL');
    }

    /**
     * A signed integer column.
     *
     * Signed, so it can hold a negative number — a count, an offset, a delta. An
     * identifier is not this: use {@see unsignedBigInteger()} for a foreign key,
     * since a negative id is not a row that can exist and an unsigned column
     * rejects one at insert time rather than storing it.
     */
    public function integer(string $column): static
    {
        return $this->addColumn($column, 'int(11) NOT NULL');
    }

    /**
     * A signed big integer.
     *
     * Distinct from {@see unsignedBigInteger()} by what it can hold: a signed
     * bigint reaches negative values and half the positive range, and a column
     * that needs a negative value — an offset, a balance, a signed delta — is
     * silently truncated to zero by the unsigned spelling rather than rejected.
     *
     * The dumper needs it because `bigint(20)` and `bigint(20) unsigned` are
     * different definitions that `SHOW COLUMNS` reports differently, and
     * collapsing both to the unsigned method would widen the range of every
     * signed column in a dump without changing a line of its output that a
     * reader would notice.
     */
    public function bigInteger(string $column): static
    {
        return $this->addColumn($column, 'bigint(20) NOT NULL');
    }

    /**
     * A signed one-byte integer.
     *
     * The signed counterpart to {@see unsignedTinyInteger()}, and separate for
     * the same reason `bigInteger()` is: a `tinyint(4)` that holds `-1` is not a
     * `tinyint(3) unsigned`, and the difference is only visible in the data.
     */
    public function tinyInteger(string $column): static
    {
        return $this->addColumn($column, 'tinyint(4) NOT NULL');
    }

    /**
     * An unsigned big integer, the type a foreign key has to be to match an
     * `id()` column.
     *
     * Not part of the brief's list; here because the brief's list cannot express
     * a foreign key without it, and a migration that guessed `integer()` for one
     * would produce a column that silently holds the wrong range of values.
     */
    public function unsignedBigInteger(string $column): static
    {
        return $this->addColumn($column, 'bigint(20) unsigned NOT NULL');
    }

    /**
     * A boolean column, stored as MySQL's one-byte `tinyint(1)`.
     *
     * The width is written out rather than left as `tinyint` because it is what
     * tells a client library to report the value as a bool — PHP's mysqli and
     * PDO both use it, and WordPress does not, so `$wpdb` hands back `'0'`/`'1'`
     * regardless. Defaulted to false so an existing row is not left NULL by an
     * `ADD COLUMN` that had no value to write.
     */
    public function boolean(string $column): static
    {
        return $this->addColumn($column, 'tinyint(1) NOT NULL DEFAULT 0');
    }

    /**
     * `created_at` and `updated_at`, both datetime, both not null.
     *
     * No `DEFAULT CURRENT_TIMESTAMP`, deliberately, and this is a real decision
     * rather than an omission. A note's creation time is the moment the
     * application decided it — which, inside a transaction that stays open, is
     * not the moment MySQL happened to write the row. A database default makes
     * those two indistinguishable after the fact, and the difference is exactly
     * what an audit trail is for. So the application writes both, and a row that
     * forgot to is a loud failure rather than a quietly plausible timestamp.
     *
     * No `ON UPDATE CURRENT_TIMESTAMP` either, for the same reason: an `UPDATE`
     * that deliberately leaves `updated_at` alone is a legitimate write, and a
     * column-level trigger cannot be told to allow one.
     */
    public function timestamps(): static
    {
        $this->datetime('created_at');
        $this->datetime('updated_at');

        return $this;
    }

    /**
     * A datetime column.
     *
     * `datetime` rather than `timestamp`: a `timestamp` column is stored as UTC
     * and converted on read using the connection's timezone, which means the
     * same stored row reads back differently through two connections configured
     * differently. `datetime` stores what it is given, which is what an
     * application that sets its own times wants.
     */
    public function datetime(string $column): static
    {
        return $this->addColumn($column, 'datetime NOT NULL');
    }

    /**
     * A `DATE` column, holding a calendar day with no time component.
     *
     * Distinct from {@see datetime()} because the difference is a real one for
     * anything compared or sorted as a day: an expected close date of the 5th
     * is the 5th regardless of the hour it was written, and storing it as a
     * datetime invites a `< '2026-11-05'` comparison to exclude rows on the 5th
     * itself.
     */
    public function date(string $column): static
    {
        return $this->addColumn($column, 'date NOT NULL');
    }

    /**
     * A `TIMESTAMP` column.
     *
     * The distinction from {@see datetime()} matters for exactly one modifier:
     * `useCurrent()` is only valid on a timestamp or datetime column, and
     * MySQL's `TIMESTAMP` additionally converts on read using the connection's
     * timezone. Use `datetime()` unless the column is genuinely a moment in
     * absolute time.
     */
    public function timestamp(string $column): static
    {
        return $this->addColumn($column, 'timestamp NOT NULL');
    }

    /**
     * A fixed-precision decimal.
     *
     * `decimal` rather than a float, and this is not a style choice: a float
     * cannot represent 0.10 exactly in binary, so summing a column of money
     * stored as one accumulates a drift that shows up as a total that is off by
     * a cent. Storage is exact at `$scale` places.
     *
     * @param int $precision Total significant digits.
     * @param int $scale     Digits after the point.
     *
     * @throws SchemaException When the scale exceeds the precision.
     */
    public function decimal(string $column, int $precision = 8, int $scale = 2): static
    {
        if ($scale > $precision) {
            throw new SchemaException(sprintf(
                'Column [%s] on table [%s] was given decimal(%d, %d), where the scale exceeds the '
                . 'precision. The scale is the digits after the point and must be the smaller of the two.',
                $column,
                $this->table,
                $precision,
                $scale
            ));
        }

        return $this->addColumn($column, sprintf('decimal(%d,%d) NOT NULL', $precision, $scale));
    }

    /**
     * An unsigned integer.
     *
     * Unsigned, so the column cannot hold a negative value — which is correct
     * for an ordering or a count and wrong for anything that can go backwards.
     */
    public function unsignedInteger(string $column): static
    {
        return $this->addColumn($column, 'int(10) unsigned NOT NULL');
    }

    /**
     * A one-byte unsigned integer, for a value with a small fixed ceiling —
     * a percentage, a rating, a small count.
     */
    public function unsignedTinyInteger(string $column): static
    {
        return $this->addColumn($column, 'tinyint(3) unsigned NOT NULL');
    }

    /**
     * A foreign key column: `{name}` as an unsigned big integer, with the
     * constraint that makes it a foreign key.
     *
     *     $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
     *
     * The column name is the whole input, and the referenced table is derived
     * from it by {@see constrainTo()} — `company_id` names `companies`. That
     * derivation is Laravel's, and it is why the `_id` suffix is not optional:
     * a column named `author` would resolve to a table called `author`.
     *
     * Emitted as `unsignedBigInteger` rather than a type name of its own,
     * because that is what it is — and because the column has to match the type
     * of the `id()` column it points at, which is an unsigned big integer. A
     * mismatch is rejected by InnoDB as an incompatible constraint.
     */
    public function foreignId(string $column): static
    {
        $this->assertIdentifier($column, 'column');

        $this->addColumn($column, 'bigint(20) unsigned NOT NULL');
        $this->foreignColumns[$column] = true;
        $this->pendingForeignKeyTable = $this->guessForeignTable($column);

        return $this;
    }

    /**
     * A polymorphic relation: `{name}_type` and `{name}_id`, and an index over
     * the pair.
     *
     * Two columns rather than one, because a morph relation identifies its
     * target by *type and id together* — `Lead` number 7 and `Company` number 7
     * are different rows in different tables. `{name}_id` is an unsigned big
     * integer but deliberately **not** a foreign key: it points at whichever
     * table `{name}_type` names, and MySQL cannot express a constraint whose
     * target is another column's value.
     *
     * The composite index is the point of the helper. A morph lookup always
     * filters on both columns, and an index on `{name}_id` alone would leave the
     * type as a post-filter over every row that shares an id — which is every
     * relation in the table, since ids repeat across types.
     */
    public function morphs(string $name): static
    {
        $this->assertIdentifier($name, 'column');

        // 191, not 255. The type column is indexed below, and a varchar longer
        // than 191 characters cannot carry that index on utf8mb4 — MySQL refuses
        // the CREATE with "specified key was too long". 191 is what WordPress
        // uses for its own indexed string columns for the same reason, and a
        // morph alias is a short class name rather than a document.
        $this->addColumn($name . '_type', 'varchar(' . self::MAX_INDEXED_STRING_LENGTH . ') NOT NULL');
        $this->addColumn($name . '_id', 'bigint(20) unsigned NOT NULL');
        $this->indexes[] = $this->compileIndex([$name . '_type', $name . '_id']);

        return $this;
    }

    /**
     * A nullable polymorphic relation: `{name}_type` and `{name}_id`, both null.
     *
     * The pair a relation that may not be set needs. {@see morphs()} emits both
     * columns `NOT NULL`, which is correct for a relation that always exists and
     * wrong for one that is optional — and "optional" is the common case for an
     * audit or activity row that is *sometimes* about a subject.
     *
     * Nullability is applied to both columns rather than to one, because a morph
     * with a type and no id (or the reverse) is a relation that names nothing.
     * MySQL's `NULL` semantics make the composite index still work: `(NULL, NULL)`
     * is stored and does not collide with any other row, since a NULL never
     * compares equal to another NULL in a unique index.
     */
    public function nullableMorphs(string $name): static
    {
        $this->morphs($name);

        // Applied by name rather than through `nullable()`, which only ever
        // reaches the *last* column — and the last column here is `{name}_id`,
        // so the type column would silently stay NOT NULL.
        $this->makeNullable($name . '_type');
        $this->makeNullable($name . '_id');

        return $this;
    }

    /**
     * A nullable `deleted_at` datetime, for soft deletes.
     *
     * A column and nothing more: this framework has no model layer, so nothing
     * here filters on it — a query that means "only live rows" says
     * `WHERE deleted_at IS NULL` itself. What the helper buys is the convention:
     * one name, one type, and a *nullable* column, so "not deleted" is NULL
     * rather than a sentinel timestamp like `'0000-00-00'` that some MySQL
     * configurations reject outright.
     *
     * Deliberately not indexed. The usual companion is a composite index over
     * `(deleted_at, <something you filter by>)`, which depends on what a given
     * table queries on — so the caller adds it, and a bare index here would
     * usually be the wrong one and always a second B-tree to maintain.
     */
    public function softDeletes(string $column = 'deleted_at'): static
    {
        $this->assertIdentifier($column, 'column');

        return $this->datetime($column)->nullable();
    }

    /**
     * A nullable `remember_token` varchar, for "remember me" sessions.
     *
     * 100 characters, matching what Laravel's own migrations use. Nullable
     * because most rows never have one — the token exists only between a user
     * checking "remember me" and that session ending — and an empty-string
     * default would make "no token" and "a token that happens to be empty"
     * indistinguishable.
     */
    public function rememberToken(): static
    {
        return $this->string('remember_token', 100)->nullable();
    }

    /**
     * A foreign key column holding a UUID: `{name}` as `CHAR(36)`, constrained.
     *
     * `char(36)` rather than `varchar`, because the referenced column is
     * {@see uuid()} and InnoDB requires a foreign key's type to match the column
     * it points at — including the fixed-versus-variable distinction. A
     * `varchar(36)` here produces a constraint the server refuses, so the reason
     * the type is what it is belongs in the code rather than in a comment
     * somebody will delete.
     *
     * The column name is the whole input; the referenced table is derived the
     * same way {@see foreignId()} derives it — `company_id` names `companies`.
     * That derivation lives in {@see guessForeignTable()} and is shared rather
     * than repeated, so the two cannot drift.
     */
    public function foreignUuid(string $column): static
    {
        $this->assertIdentifier($column, 'column');

        $this->addColumn($column, 'char(36) NOT NULL');
        $this->foreignColumns[$column] = true;
        $this->pendingForeignKeyTable = $this->guessForeignTable($column);

        return $this;
    }

    /**
     * A foreign key column holding a ULID: `{name}` as `CHAR(26)`, constrained.
     *
     * @see foreignUuid() for why the type is fixed-width, and {@see ulid()} for
     * why it is 26 characters.
     */
    public function foreignUlid(string $column): static
    {
        $this->assertIdentifier($column, 'column');

        $this->addColumn($column, 'char(26) NOT NULL');
        $this->foreignColumns[$column] = true;
        $this->pendingForeignKeyTable = $this->guessForeignTable($column);

        return $this;
    }

    // --------------------------------------------------------- column modifiers

    /**
     * Allow NULL in the column the chain is currently on.
     *
     * Applies to the most recently added column, so it must directly follow it:
     * `$table->string('title')->nullable();` and not
     * `$table->string('title'); $table->string('body')->nullable();` — the
     * second leaves `title` untouched and is correct behaviour, but reads as
     * though it modified both.
     *
     * @throws SchemaException When no column has been added yet.
     */
    public function nullable(): static
    {
        return $this->modifyLastColumn(function (string $definition, string $name): string {
            return $this->dropNotNull($definition, $name);
        });
    }

    /**
     * Allow NULL in a column named explicitly, wherever it is in the list.
     *
     * The by-name counterpart to {@see nullable()}, which can only ever reach the
     * most recent column. A helper that defines *two* columns needs this: the
     * thing that wants nullability is the pair, and `nullable()` after it would
     * reach only the second of them.
     *
     * Not part of the public builder API — a migration chains `nullable()` — but
     * `private` would force the two morph helpers to duplicate the definition
     * rewrite below, which is exactly the kind of duplication that lets one copy
     * drift.
     *
     * @throws SchemaException When no column by that name has been defined.
     */
    private function makeNullable(string $column): static
    {
        $found = false;

        foreach ($this->columns as $index => $existing) {
            if ($existing['name'] !== $column) {
                continue;
            }

            $found = true;
            $this->columns[$index]['definition'] = $this->dropNotNull($existing['definition'], $column);
        }

        if (!$found) {
            throw new SchemaException(sprintf(
                'Column [%s] was asked to be nullable on table [%s], but no such column was defined '
                . 'by this blueprint.',
                $column,
                $this->table
            ));
        }

        return $this;
    }

    /**
     * Strip `NOT NULL` from a definition, state `NULL`, and drop any default the
     * *type* supplied.
     *
     * A column that becomes nullable keeps a default the author gave it, but
     * loses one it inherited from its type — `boolean()` and `id()` both emit
     * one. The distinction is what stops a nullable boolean from reporting
     * `false` for a row whose value was never set, which is the difference
     * between "no" and "no answer".
     *
     * ## Why `NULL` is written rather than merely implied
     *
     * Omitting `NOT NULL` is *enough* — MySQL reads a column with no nullability
     * clause as nullable — so this method worked for years while saying nothing.
     * It is written out anyway because of the two places the difference is
     * observable.
     *
     * A dump is the first. {@see SchemaDumper} reads nullability from the
     * catalogue and re-emits it as an explicit `->nullable()`, so a migration the
     * dumper generated and one a person wrote spell the same column two ways —
     * and a text comparison of the two reports a difference that does not exist.
     *
     * The second is that relying on a default makes the intent unrecoverable from
     * the source. `tinyint(1)` states a type; `tinyint(1) NULL` states a type and
     * a decision. The next reader should not have to know MySQL's rules to see
     * which was meant.
     *
     * `NULL` goes *before* any surviving default, because that is the order
     * MySQL's grammar requires — `type [NULL | NOT NULL] [DEFAULT v] [extra]`.
     * `tinyint(1) NULL DEFAULT 1` parses; `tinyint(1) DEFAULT 1 NULL` is a syntax
     * error. The adapter's own {@see WpDatabaseAdapter::columnDefinition()} builds
     * a definition the same way round, which is a second reason not to guess: the
     * two have to describe a column identically for an `ALTER` to be a no-op
     * against a column this created.
     */
    private function dropNotNull(string $definition, string $column): string
    {
        $definition = str_replace(' NOT NULL', '', $definition);

        if (($this->hasDefault[$column] ?? false) === false) {
            $definition = preg_replace('/ DEFAULT .*$/', '', $definition) ?? $definition;
        }

        $definition = rtrim($definition);

        // Idempotent, because `nullable()` can legitimately be reached twice for
        // one column: `$table->string('x')->nullable()` followed by a helper that
        // makes the whole group nullable, or a doubled modifier. Without this the
        // second pass would emit `... NULL NULL`, which MySQL rejects — turning a
        // harmless repeat into a migration that will not run.
        if (preg_match('/(?:^|\s)NULL(?:\s|$)/i', $definition) === 1) {
            return $definition;
        }

        // Split the default off, insert `NULL` ahead of it, and put it back.
        if (preg_match('/^(.*?)(\s+DEFAULT\s+.*)$/is', $definition, $m) === 1) {
            return rtrim($m[1]) . ' NULL' . $m[2];
        }

        return $definition . ' NULL';
    }

    /**
     * Give the most recently added column a default value.
     *
     * @throws SchemaException When no column has been added yet.
     */    public function default(string|int|float|bool $value): static
    {
        // Refused before the rewrite, so a refusal leaves the column as the type
        // method defined it rather than half-modified.
        $column = $this->lastColumn()['name'];

        if (isset($this->noDefault[$column])) {
            throw new SchemaException(sprintf(
                'Column [%s] on table [%s] cannot be given a default value. %s',
                $column,
                $this->table,
                $this->noDefault[$column]
            ));
        }

        $this->modifyLastColumn(function (string $definition) use ($value): string {
            // Any existing default is replaced, not appended to. A column type
            // may already carry one — `boolean()` does, so that an ADD COLUMN
            // does not leave every existing row NULL — and appending would emit
            // `DEFAULT 0 DEFAULT 1`, which is a syntax error rather than the
            // override the caller asked for.
            $definition = preg_replace('/ DEFAULT .*$/', '', $definition) ?? $definition;

            return $definition . ' DEFAULT ' . self::compileDefault($value);
        });

        // Recorded after the rewrite, keyed by the column it landed on, so a
        // later nullable() can tell an author's default from a type's.
        $this->hasDefault[$this->lastColumn()['name']] = true;

        return $this;
    }

    /**
     * Default the most recently added column to the current timestamp.
     *
     * Valid on a `timestamp` or `datetime` column only — MySQL applies
     * `DEFAULT CURRENT_TIMESTAMP` to those types and to nothing else, so on any
     * other type the statement is refused.
     *
     * Note this is the *one* place a database-side default is the right answer
     * in this builder. `timestamps()` refuses one deliberately, because a row's
     * `created_at` should be the moment the application decided rather than the
     * moment MySQL wrote it. A column like `converted_at` is different: it
     * records when the database accepted the row, which is exactly what the
     * application means by it, and deriving it in the schema removes a field
     * every caller would otherwise have to remember to set.
     *
     * @throws SchemaException When the column is not a date/time type.
     */
    public function useCurrent(): static
    {
        return $this->modifyLastColumn(function (string $definition, string $name): string {
            if (!str_contains($definition, 'timestamp') && !str_contains($definition, 'datetime')) {
                throw new SchemaException(sprintf(
                    'Column [%s] on table [%s] cannot use useCurrent(): MySQL applies '
                    . 'DEFAULT CURRENT_TIMESTAMP to timestamp and datetime columns only, and this one is '
                    . 'declared as [%s].',
                    $name,
                    $this->table,
                    $definition
                ));
            }

            return preg_replace('/ DEFAULT .*$/', '', $definition) . ' DEFAULT CURRENT_TIMESTAMP';
        });
    }

    // ----------------------------------------------------------- positioning

    /**
     * Place the column after a named one.
     *
     *     Schema::table('notes', function (Blueprint $table) {
     *         $table->string('title')->after('id');
     *     });
     *
     * Without it MySQL appends a new column to the end of the table, so a column
     * declared between two others lands last. That is cosmetic in the schema and
     * not at all cosmetic in `SELECT *`, where column order is what every
     * positional `fetch_row()` index and every `while ($row = ...)` loop over
     * named columns depends on — and it is the difference a `dbDelta()`-managed
     * WordPress table shows after an upgrade on two sites that applied different
     * batches.
     *
     * Also the way an *existing* column is repositioned: MySQL's `MODIFY COLUMN`
     * takes `AFTER`, so `->after('id')->change()` moves a column that is already
     * there. There is deliberately no separate `moveColumn()`.
     *
     * The last positioning call wins — see {@see first()}.
     *
     * @throws SchemaException When no column has been added yet, the name is not
     *                         a usable identifier, or the table is being created.
     */
    public function after(string $column): static
    {
        $this->assertIdentifier($column, 'column');

        return $this->position(sprintf('AFTER `%s`', $column), sprintf('after(\'%s\')', $column));
    }

    /**
     * Place the column first in the table.
     *
     * A separate method rather than `after(null)`, because `FIRST` is a keyword
     * and `AFTER NULL` is a syntax error — the distinction is in the grammar, so
     * it is in the API.
     *
     * **The last positioning call wins.** `->first()->after('id')` positions
     * after `id`; `->after('id')->first()` positions first. Both clauses cannot
     * be emitted — `FIRST AFTER x` does not parse — so one has to win, and
     * last-wins is the rule `default()` already follows for the same reason: a
     * second call is a correction, not an addition. Refusing the pair instead
     * would break the reordering idiom that makes the methods useful together.
     *
     * @throws SchemaException When no column has been added yet, or the table is
     *                         being created.
     */
    public function first(): static
    {
        return $this->position('FIRST', 'first()');
    }

    /**
     * Append a positioning clause to the current column's definition.
     *
     * Strips any clause a previous `after()`/`first()` appended, which is what
     * makes last-wins work: the definition carries its position, so replacing it
     * means removing the old one rather than tracking it separately.
     *
     * @param string $clause     The SQL — `FIRST` or `AFTER \`x\``.
     * @param string $describeAs How the call reads, for the refusal message.
     *
     * @throws SchemaException When no column has been added, or the table is
     *                         being created.
     */
    private function position(string $clause, string $describeAs): static
    {
        // Refused on a CREATE rather than emitted. MySQL rejects FIRST/AFTER
        // inside CREATE TABLE, and the request is meaningless there anyway: a new
        // table's column order is the order the migration declared them in, so
        // after() asks for something the caller already has.
        if ($this->creating) {
            throw new SchemaException(sprintf(
                '%s was requested while creating table [%s]. Column position is the order the '
                . 'columns are declared in, so declare it there instead; positioning applies to '
                . 'Schema::table() on a table that already exists.',
                $describeAs,
                $this->table
            ));
        }

        if ($this->columns === []) {
            throw new SchemaException(sprintf(
                '%s was called on table [%s] before any column was defined. It positions the column '
                . 'the chain is on, so it has to follow one: $table->string(\'title\')->after(\'id\').',
                $describeAs,
                $this->table
            ));
        }

        $index = count($this->columns) - 1;

        $definition = preg_replace('/\s+(?:FIRST|AFTER\s+`[^`]+`)$/i', '', $this->columns[$index]['definition'])
            ?? $this->columns[$index]['definition'];

        $this->columns[$index]['definition'] = $definition . ' ' . $clause;

        return $this;
    }

    // --------------------------------------------------------------- indexes

    /**
     * Index one or more columns, or the column the chain is currently on.
     *
     *     $table->string('status')->index();            // the last column
     *     $table->index(['user_id', 'created_at']);     // named columns
     *
     * The composite form is what covers an `ORDER BY`: an index on the first
     * column alone leaves MySQL sorting every row it returns.
     *
     * For an **existing** table each index is its own `ALTER TABLE ... ADD KEY`.
     * MySQL accepts several in one statement, but `dbDelta()` sees only the first
     * of them, so one statement per index is what keeps a `Schema::table()`
     * migration honest whichever path executes it.
     *
     * @param string|list<string>|null $columns Null applies it to the column the
     *                                          chain is currently on.
     *
     * @throws SchemaException When no column has been added yet, or a name is not
     *                         a usable identifier.
     */
    public function index(string|array|null $columns = null): static
    {
        if ($columns === null) {
            // Delegated rather than duplicated: indexColumn() exists for exactly
            // this case and its docblock is the one that explains it.
            return $this->indexColumn();
        }

        $columns = is_string($columns) ? [$columns] : $columns;

        if ($columns === []) {
            throw new SchemaException(sprintf(
                'An index on table [%s] was given no columns.',
                $this->table
            ));
        }

        foreach ($columns as $column) {
            $this->assertIdentifier($column, 'index');
            $this->assertIndexable($column);
        }

        $this->indexes[] = $this->compileIndex($columns);

        return $this;
    }

    /**
     * Put a UNIQUE index on one or more columns.
     *
     *     $table->string('email')->unique();            // the last column
     *     $table->unique(['company_id', 'contact_id']); // named columns
     *
     * A unique index is what actually enforces a rule like "one company per
     * contact": a plain index over the same columns makes the lookup fast and
     * leaves two writers free to store the same pair. That is why the constraint
     * belongs in the schema rather than in a read-then-write check in the
     * application, which can race between the read and the write.
     *
     * A named column list *replaces* the same-named plain index rather than
     * sitting beside it — a unique index already serves every lookup the plain
     * one did, so keeping both would maintain a second B-tree on every write for
     * nothing. Two indexes that differ only in uniqueness are collapsed here for
     * that reason, which is the same reasoning
     * `2026_10_31_222317_make_companies_name_unique.php` applies by hand.
     *
     * @param string|list<string>|null $columns Null applies it to the column the
     *                                          chain is currently on.
     *
     * @throws SchemaException When no column has been added yet, or a name is
     *                         not a usable identifier.
     */
    public function unique(string|array|null $columns = null): static
    {
        if ($columns === null) {
            $last = $this->lastColumn();

            $this->assertIndexable($last['name']);

            return $this->addUnique([$last['name']]);
        }

        $columns = is_string($columns) ? [$columns] : $columns;

        if ($columns === []) {
            throw new SchemaException(sprintf(
                'A unique index on table [%s] was given no columns.',
                $this->table
            ));
        }

        foreach ($columns as $column) {
            $this->assertIdentifier($column, 'index');
            $this->assertIndexable($column);
        }

        return $this->addUnique($columns);
    }

    /**
     * Index the most recently added column.
     *
     * Sugar for `->index($name)` on that column, so a nullable foreign key reads
     * as one line: `$table->unsignedBigInteger('note_id')->nullable()->index()`.
     *
     * @throws SchemaException When no column has been added yet.
     */
    public function indexColumn(): static
    {
        $last = $this->lastColumn();

        $this->assertIndexable($last['name']);
        $this->indexes[] = $this->compileIndex([$last['name']]);

        return $this;
    }

    /**
     * Index the most recently added column, and the named column after it.
     *
     *     $table->unsignedBigInteger('note_id')->nullable()->indexWith('occurred_at');
     *
     * The composite form exists for the query shape that has both a join and a
     * sort — `WHERE note_id = ? ORDER BY occurred_at` — where an index on the
     * first column alone still leaves MySQL sorting every row it returns.
     *
     * The second column is named rather than inferred, and appended rather than
     * prepended, because the leading column is the one being filtered on. It is
     * also always added: MySQL does not use the trailing part of a composite
     * index to avoid a sort when the leading part is `NULL`, and a migration is
     * not the place to guess whether a nullable column is *usually* null.
     *
     * @throws SchemaException When no column has been added yet.
     */
    public function indexWith(string $secondary): static
    {
        $last = $this->lastColumn();

        $this->assertIdentifier($secondary, 'column');
        $this->assertIndexable($last['name']);
        $this->assertIndexable($secondary);

        $columns = [$last['name']];

        if ($secondary !== $last['name']) {
            $columns[] = $secondary;
        }

        $this->indexes[] = $this->compileIndex($columns);

        return $this;
    }

    // ---------------------------------------------------- columns and indexes to drop

    /**
     * Rewrite the definition of a column that already exists.
     *
     *     Schema::table('notes', function (Blueprint $table) {
     *         $table->string('body', 500)->nullable()->change();
     *     });
     *
     * ## The caller states the whole definition
     *
     * This is the rule the framework settled on, and it is a deliberate cost.
     * Laravel reads the current definition via Doctrine DBAL and applies a diff;
     * this has no Doctrine, so `change()` takes the definition the fluent chain
     * built and emits it verbatim as `MODIFY COLUMN`. That means a caller must
     * restate everything they want to keep — a `->change()` on a `NOT NULL`
     * column without `->nullable()` makes it `NOT NULL`, and a default that is
     * not restated is dropped.
     *
     * The trade is worth stating plainly because it is the one place in this
     * builder where the code does *not* say what it means: `$table->string('body')->change()`
     * reads like "make body a string" and actually means "make body exactly
     * `varchar(191) NOT NULL`", discarding whatever else it had. Conversely it is
     * honest about itself — nothing is inferred, so nothing is silently guessed
     * wrong — and it needs no schema-introspection dependency to work.
     *
     * ## The column must exist
     *
     * MySQL's `MODIFY COLUMN` is an error against a missing column, and by the
     * time it fails the other clauses of the same `ALTER TABLE` have run. Checked
     * first so the message names the column rather than arriving as a syntax
     * error from the server.
     *
     * @throws SchemaException When no column was defined before it, the column
     *                         does not exist, or the blueprint is creating a
     *                         table.
     */
    public function change(): static
    {
        // Read before the guard below, because the guard needs the column's name
        // to name it in its own message — and the "no column chained" case is
        // reported by that same guard, with wording that mentions change().
        if ($this->columns === []) {
            throw new SchemaException(sprintf(
                'change() was called on table [%s] before any column was defined. It rewrites the '
                . 'definition of the column the chain is on, so it has to follow one: '
                . '$table->string(\'body\', 500)->nullable()->change().',
                $this->table
            ));
        }

        if ($this->creating) {
            $this->assertAlterable(sprintf('MODIFY COLUMN `%s`', $this->columns[count($this->columns) - 1]['name']));
        }

        // Consumed from the column list, not copied from it. A `change()` is a
        // *replacement*: leaving the column in $columns would emit both an
        // `ADD COLUMN` and a `MODIFY COLUMN` for it, and on a table where the
        // column already exists the ADD is the statement that fails.
        $column = array_pop($this->columns);

        $this->assertColumnExists($column['name']);

        $this->alterations[] = sprintf('MODIFY COLUMN `%s` %s', $column['name'], $column['definition']);

        // The default-tracking map is keyed by name and is consulted by
        // nullable() to keep `->default(0)->nullable()` order-independent. The
        // column it described is no longer in the list being compiled, so the
        // entry is stale either way; dropped so a later same-named definition
        // does not read the old answer.
        unset($this->hasDefault[$column['name']]);

        return $this;
    }

    /**
     * Refuse a modification to a column that does not exist.
     *
     * @throws SchemaException When the column is absent.
     */
    private function assertColumnExists(string $column): void
    {
        if (($this->resolveColumnDefinition)($this->table, $column) !== null) {
            return;
        }

        throw new SchemaException(sprintf(
            'Cannot change column [%s] on table [%s]: no such column. change() rewrites a column '
            . 'that already exists; add it with Schema::table() instead.',
            $column,
            $this->table
        ));
    }

    /**
     * Rename a column.
     *
     *     Schema::table('notes', function (Blueprint $table) {
     *         $table->renameColumn('body', 'content');
     *     });
     *
     * Two names and no type, which is the whole point: the definition is read
     * back from the database through the adapter, so a caller cannot accidentally
     * reset a column's type or nullability while renaming it. That is the failure
     * this signature exists to prevent — restating a definition by hand is how a
     * `varchar(255)` quietly becomes a `varchar(191)`, or a `NOT NULL` loses its
     * not.
     *
     * **The data is preserved.** MySQL's `CHANGE COLUMN` moves the values with
     * the name; it is not a drop-and-add in disguise. The compiled statement is
     * `CHANGE COLUMN` rather than the more modern `RENAME COLUMN` because that
     * one needs MySQL 8.0, and the plugin supports hosts older than that.
     *
     * Refused when the source column does not exist — there is nothing to
     * rename, and inventing a definition would create the column empty. Refused
     * when the destination already exists, because MySQL would fail with a
     * duplicate-column error after other clauses in the same statement had
     * already run; catching it here names both columns instead.
     *
     * @throws SchemaException When the blueprint is creating a table, the
     *                         definition cannot be read, or either name is
     *                         unusable.
     */
    public function renameColumn(string $from, string $to): static
    {
        $this->assertIdentifier($from, 'column');
        $this->assertIdentifier($to, 'column');

        // Checked before the definitions are read, not after. A rename inside a
        // `CREATE TABLE` is invalid whatever the database holds, and reading it
        // first would report "no such column" — sending an author to look at a
        // table that is not the problem. It would also query a table that, on a
        // first install, does not exist yet.
        $this->assertAlterable(sprintf('RENAME COLUMN `%s` TO `%s`', $from, $to));

        $definition = ($this->resolveColumnDefinition)($this->table, $from);

        if ($definition === null) {
            throw new SchemaException(sprintf(
                'Cannot rename column [%s] on table [%s]: no such column. A rename needs an '
                . 'existing definition to restate, and there is nothing here to restate.',
                $from,
                $this->table
            ));
        }

        if (($this->resolveColumnDefinition)($this->table, $to) !== null) {
            throw new SchemaException(sprintf(
                'Cannot rename column [%s] to [%s] on table [%s]: [%s] already exists. '
                . 'Rename or drop the existing column first.',
                $from,
                $to,
                $this->table,
                $to
            ));
        }

        $this->alterations[] = sprintf('CHANGE COLUMN `%s` `%s` %s', $from, $to, $definition);

        return $this;
    }

    /**
     * Drop a column, or several.
     *
     *     Schema::table('notes', function (Blueprint $table) {
     *         $table->dropColumn('slug');
     *         $table->dropColumn(['slug', 'excerpt']);
     *     });
     *
     * **This destroys the data in the column, and nothing here can undo it.**
     * There is no soft-delete for a schema: `down()` has to re-add the column,
     * and it comes back empty. A migration that drops a column is therefore only
     * reversible in the structural sense, and the `down()` written beside it
     * should say so rather than implying the values return.
     *
     * A list is compiled into one `ALTER TABLE` with a `DROP COLUMN` clause per
     * name, rather than one statement each — see {@see toAddColumnStatements()}
     * for why every change in a callback shares a statement. The practical
     * consequence here is that a multi-column drop either happens or does not,
     * so a caller who catches a failure is never left guessing which of the
     * columns went.
     *
     * Valid only when altering an existing table. MySQL rejects `DROP` inside
     * `CREATE TABLE`, so dropping a column from a table being created is refused
     * here, where the message can say which column and which table.
     *
     * @param string|list<string> $columns
     *
     * @throws SchemaException When the blueprint is creating a table, or a name
     *                         is not a usable identifier.
     */
    public function dropColumn(string|array $columns): static
    {
        foreach (is_string($columns) ? [$columns] : $columns as $column) {
            $this->assertIdentifier($column, 'column');

            $this->addAlteration(sprintf('DROP COLUMN `%s`', $column));
        }

        return $this;
    }

    /**
     * Drop an index by the columns it covers.
     *
     *     Schema::table('companies', function (Blueprint $table) {
     *         $table->dropIndex(['name']);
     *     });
     *
     * Named by *columns* rather than by index name, so a migration does not have
     * to know how the database spelled the key it is removing — and so the same
     * call works against an index this builder created and one created some
     * other way, since both are looked up by what they cover.
     *
     * Valid only when altering an existing table. Dropping a key from a table
     * being created is meaningless, and MySQL rejects `DROP` inside
     * `CREATE TABLE` — so it is refused here, where the message can say why.
     *
     * @param string|list<string> $columns
     *
     * @throws SchemaException When the blueprint is creating a table.
     */
    public function dropIndex(string|array $columns): static
    {
        $columns = is_string($columns) ? [$columns] : $columns;

        foreach ($columns as $column) {
            $this->assertIdentifier($column, 'index');
        }

        return $this->addAlteration(sprintf('DROP KEY `%s`', implode('_', $columns)));
    }

    /**
     * Drop a unique index by the columns it covers.
     *
     * Distinct from {@see dropIndex()} in MySQL's grammar — `DROP INDEX` and
     * `DROP KEY` are synonyms there, but a unique index whose name collides with
     * a plain one is exactly the confusion this pairs with {@see unique()}.
     * They compile to the same statement, deliberately: MySQL has one namespace
     * for both, so `DROP KEY <name>` removes whichever it is.
     *
     * @param string|list<string> $columns
     *
     * @throws SchemaException When the blueprint is creating a table.
     */
    public function dropUnique(string|array $columns): static
    {
        return $this->dropIndex($columns);
    }

    /**
     * Record an `ALTER TABLE` action that is not a column or a constraint.
     *
     * @throws SchemaException When the blueprint is creating a table.
     */
    private function addAlteration(string $clause): static
    {
        $this->assertAlterable($clause);

        $this->alterations[] = $clause;

        return $this;
    }

    /**
     * Refuse an alteration to a table that is being created.
     *
     * `MySQL` has no `DROP`, `CHANGE` or `MODIFY` inside a `CREATE TABLE`, so the
     * alternative to refusing is emitting a statement the server rejects — and
     * the author would be reading a syntax error when the mistake is really "you
     * altered a table you have not made yet".
     *
     * `$describeAs` lets a caller name the operation in its own vocabulary when
     * the clause it compiles to reads differently: a rename is `CHANGE COLUMN` to
     * MySQL but `RENAME COLUMN` to the person who wrote it, and a message
     * quoting the second is the one that matches their code.
     *
     * @throws SchemaException When the blueprint is creating a table.
     */
    private function assertAlterable(string $describeAs): void
    {
        if ($this->creating) {
            throw new SchemaException(sprintf(
                '%s was requested while creating table [%s]. Altering an existing table is not '
                . 'something a CREATE can express; define what the table should contain instead, '
                . 'or use Schema::table() with the table already created.',
                $describeAs,
                $this->table
            ));
        }
    }

    // --------------------------------------------------------- foreign keys

    /**
     * Make the column before this a foreign key.
     *
     *     $table->foreignId('company_id')->constrained()->cascadeOnDelete();
     *     $table->foreignId('assigned_to')->nullable()->constrained('users');
     *
     * Without an argument the referenced table is derived from the column name
     * (`company_id` → `companies`) and the referenced column is that table's
     * `id`. With one, the table is named outright — which is what a column that
     * does not follow the convention needs, `assigned_to` being the case in
     * hand: no rule turns it into `users`.
     *
     * The referenced table is stored as the *schema* name, not as a resolved
     * one. Resolution happens at compilation, through the adapter, which
     * resolves every name — plugin tables (`leads` → `wp_leads`) and WordPress
     * core tables alike (`users` → `wp_users`, `posts` → `wp_posts`) — by
     * prepending `$wpdb->prefix`, because WordPress prefixes every table with
     * that value.
     *
     * The constraint is left ON DELETE RESTRICT unless a deletion rule is
     * chained after it. That default is the conservative one: a cascade is a
     * decision about what a delete *means*, and defaulting to it would silently
     * delete rows a migration author never thought about.
     *
     * @throws SchemaException When the previous call was not a foreignId(), or
     *                         the derived table name is unusable.
     */
    public function constrained(?string $table = null): static
    {
        $column = $this->foreignColumn();

        $table ??= $this->pendingForeignKeyTable;

        if ($table === null || $table === '') {
            throw new SchemaException(sprintf(
                'Could not derive the table [%s] on [%s] refers to. A foreign key column must be named '
                . '<singular>_id — company_id, user_id — or the table must be named: '
                . 'constrained(\'the_table\').',
                $column,
                $this->table
            ));
        }

        $this->assertIdentifier($table, 'table');

        $this->constraints[] = sprintf(
            'CONSTRAINT `%s_%s_foreign` FOREIGN KEY (`%s`) REFERENCES `%s` (`id`)',
            // The constraint name carries the table's resolved spelling, so two
            // installs sharing one database do not collide over a name MySQL
            // treats as database-global.
            $this->resolve($this->table),
            $column,
            $column,
            $this->resolve($table)
        );

        return $this;
    }

    /**
     * A referenced table name as the database spells it.
     */
    private function resolve(string $table): string
    {
        return ($this->resolveTable)($table);
    }

    /**
     * Delete this row when the row it references is deleted.
     *
     * `ON DELETE CASCADE`. Correct for a row that has no meaning without its
     * parent — a deal stage belonging to a deleted pipeline, a pivot row
     * belonging to either side.
     *
     * @throws SchemaException When no constraint has been declared.
     */
    public function cascadeOnDelete(): static
    {
        return $this->withDeleteRule('CASCADE');
    }

    /**
     * Set this column to NULL when the row it references is deleted.
     *
     * `ON DELETE SET NULL`. Requires a nullable column — the rule cannot set a
     * `NOT NULL` column to null, and MySQL rejects the constraint outright — so
     * this refuses when {@see nullable()} has not been called, rather than
     * emitting a statement the database will refuse with a message about the
     * constraint.
     *
     * @throws SchemaException When the column is not nullable.
     */
    public function nullOnDelete(): static
    {
        $column = $this->foreignColumn();

        if (!str_contains($this->definition($column), ' NOT NULL')) {
            return $this->withDeleteRule('SET NULL');
        }

        throw new SchemaException(sprintf(
            'Column [%s] on table [%s] cannot use nullOnDelete() because it is NOT NULL. '
            . 'A SET NULL rule has nothing to write. Add ->nullable() to the column.',
            $column,
            $this->table
        ));
    }

    /**
     * Refuse to delete a referenced row while this one points at it.
     *
     * `ON DELETE RESTRICT`, which is also the default. Stated explicitly by a
     * migration that means it, so a reader does not have to know the default.
     *
     * @throws SchemaException When no constraint has been declared.
     */
    public function restrictOnDelete(): static
    {
        return $this->withDeleteRule('RESTRICT');
    }

    // ------------------------------------------------------------- compilation

    /**
     * The `CREATE TABLE` statement for this blueprint.
     *
     * @param string $qualifiedName The table name to CREATE, already prefixed
     *                              and escaped by the adapter.
     * @param string $charset       The charset/collation clause, or '' for none.
     */
    public function toCreateSql(string $qualifiedName, string $charset = ''): string
    {
        $definitions = $this->definitionList();

        $sql = sprintf(
            "CREATE TABLE `%s` (\n    %s\n)",
            $qualifiedName,
            implode(",\n    ", $definitions)
        );

        if ($charset !== '') {
            $sql .= ' ' . $charset;
        }

        return $sql;
    }

    /**
     * One `ALTER TABLE ... ADD COLUMN` statement per column defined here.
     *
     * @param string $qualifiedName The table to alter, already prefixed and
     *                              escaped.
     * @return list<string>
     */
    public function toAddColumnStatements(string $qualifiedName): array
    {
        $clauses = [];

        foreach ($this->columns as $column) {
            $clauses[] = sprintf('ADD COLUMN `%s` %s', $column['name'], $column['definition']);
        }

        foreach ($this->indexes as $index) {
            $clauses[] = 'ADD ' . $index;
        }

        foreach ($this->constraints as $constraint) {
            $clauses[] = 'ADD ' . $constraint;
        }

        foreach ($this->alterations as $action) {
            $clauses[] = $action;
        }

        if ($clauses === []) {
            return [];
        }

        // **One statement**, with every change comma-separated inside it, and
        // this is not an optimisation.
        //
        // A migration that replaces an index — `dropIndex(['name'])` followed by
        // `unique('name')`, which is what making a column unique looks like when
        // it already carried a plain index — compiles to a DROP and an ADD that
        // name the *same* index. Split across two statements they interleave
        // wrongly: the ADD would create the unique index and the DROP would then
        // remove it, leaving the column unindexed and the constraint silently
        // absent. Inside one statement MySQL evaluates DROP before ADD
        // regardless of the order they are written, so the old index goes and
        // the new one arrives. That is the only ordering that produces the
        // intended result, and it is not the ordering the source reads in.
        //
        // The alternative — one statement per change — was the earlier shape
        // here, and the FakeWpdb suite caught it: the two statements ran in
        // sequence and the second undid the first.
        return [sprintf('ALTER TABLE `%s` %s', $qualifiedName, implode(', ', $clauses))];
    }

    /**
     * Whether anything was defined at all.
     *
     * Read by {@see Schema::table()} to decide whether there is a statement to
     * issue: a callback that defined nothing — guarded by a conditional that did
     * not hold, say — must not produce an `ALTER TABLE` with an empty clause,
     * which is a syntax error rather than a no-op.
     */
    public function isEmpty(): bool
    {
        return $this->columns === []
            && $this->indexes === []
            && $this->constraints === []
            && $this->alterations === [];
    }

    // ---------------------------------------------------------------- internals

    /**
     * Append a column, or throw when its name is unusable.
     *
     * @param string $type      The SQL type fragment, without the trailing
     *                          nullability or modifiers.
     * @param bool   $primary   Whether to emit a table-level PRIMARY KEY for it.
     */
    private function addColumn(string $column, string $type, bool $primary = false): static
    {
        $this->assertIdentifier($column, 'column');

        $this->columns[] = [
            'name' => $column,
            'definition' => $type,
            'primary' => $primary,
        ];

        return $this;
    }

    /**
     * Rewrite the definition of the column the chain is currently on.
     *
     * The column's *name* is what the rewrite callback is given alongside its
     * definition, because {@see nullable()} has to consult state keyed by name —
     * and the alternative, passing the whole column array, invites a callback
     * that mutates it and silently disagrees with the list it came from.
     *
     * @param callable(string, string): string $rewrite
     *
     * @throws SchemaException When no column has been added yet.
     */
    private function modifyLastColumn(callable $rewrite): static
    {
        $index = count($this->columns) - 1;

        if ($index < 0) {
            throw new SchemaException(sprintf(
                'A modifier was chained on table [%s] before any column was added. '
                . 'Modifiers such as nullable(), default() and indexColumn() apply to the column '
                . 'defined immediately before them.',
                $this->table
            ));
        }

        $column = $this->columns[$index];

        $this->columns[$index]['definition'] = $rewrite($column['definition'], $column['name']);

        return $this;
    }

    /**
     * A column's current compiled definition.
     *
     * Used by the constraint rules, which have to ask about a column the chain
     * has already moved past — whether it is nullable, whether it is a date
     * type — rather than about the one being built.
     */
    private function definition(string $column): string
    {
        foreach ($this->columns as $candidate) {
            if ($candidate['name'] === $column) {
                return $candidate['definition'];
            }
        }

        return '';
    }

    /**
     * The column the most recent {@see foreignId()} added, which is the one a
     * `constrained()` call applies to.
     *
     * The *most recent* rather than "the last column": `foreignId()` is
     * routinely followed by `nullable()`, which does not disturb the pending
     * foreign key, and by nothing else. Tracking it through a separate pointer
     * is what lets `->nullable()->constrained()` and
     * `->constrained()->nullable()` both work.
     *
     * @throws SchemaException When no foreignId() is awaiting one.
     */
    private function foreignColumn(): string
    {
        $column = array_key_last($this->foreignColumns);

        if ($column === null) {
            throw new SchemaException(sprintf(
                'A foreign key rule was chained on table [%s] without a foreignId() column to apply it '
                . 'to. A key is declared by foreignId(\'company_id\')->constrained(), and the deletion '
                . 'rules follow that.',
                $this->table
            ));
        }

        return (string) $column;
    }

    /**
     * Apply an `ON DELETE` rule to the constraint most recently declared.
     *
     * @throws SchemaException When no constraint has been declared.
     */
    private function withDeleteRule(string $rule): static
    {
        $index = count($this->constraints) - 1;

        if ($index < 0) {
            throw new SchemaException(sprintf(
                'A deletion rule was chained on table [%s] without a constraint to apply it to. '
                . 'Call constrained() first: foreignId(\'company_id\')->constrained()->cascadeOnDelete().',
                $this->table
            ));
        }

        $this->constraints[$index] .= ' ON DELETE ' . $rule;

        return $this;
    }

    /**
     * The table a foreign key column name refers to.
     *
     * `company_id` names `companies` — singularise the stem, then pluralise it.
     * Both steps are needed and the order matters: `companies` is the plural of
     * `company`, so stripping `_id` and pluralising naively gives `companys`.
     *
     * English pluralisation is irregular enough that a general rule is a
     * liability, so this covers the endings that appear in table names — a
     * consonant before `y`, a sibilant needing `es` — and leaves anything else
     * alone. A name it gets wrong is not a silent failure: {@see constrained()}
     * takes the table explicitly precisely so a column that does not follow the
     * convention can say what it points at.
     */
    private function guessForeignTable(string $column): ?string
    {
        if (!str_ends_with($column, '_id')) {
            return null;
        }

        $stem = substr($column, 0, -3);

        if ($stem === '') {
            return null;
        }

        // A consonant before `y` takes `ies`; a vowel before it takes `s`.
        if (preg_match('/[^aeiou]y$/', $stem) === 1) {
            return substr($stem, 0, -1) . 'ies';
        }

        // A sibilant takes `es`, which is what turns `address` into `addresses`
        // rather than `addresss`.
        if (preg_match('/(s|x|z|ch|sh)$/', $stem) === 1) {
            return $stem . 'es';
        }

        return $stem . 's';
    }

    /**
     * Refuse to index a column whose type cannot carry an index.
     *
     * Only says anything about columns this blueprint defined; an index over a
     * column that came from somewhere else — a table being altered — is not this
     * class's to judge, because it never saw the type.
     *
     * @throws SchemaException
     */
    private function assertIndexable(string $column): void
    {
        $reason = $this->unindexable[$column] ?? null;

        if ($reason !== null) {
            throw SchemaException::unsupportedColumn($column, $this->table, $reason);
        }
    }

    /**
     * @return array{name: string, definition: string}
     *
     * @throws SchemaException When no column has been added yet.
     */
    private function lastColumn(): array
    {
        $last = $this->columns[count($this->columns) - 1] ?? null;

        if ($last === null) {
            throw new SchemaException(sprintf(
                'An index was requested for a column on table [%s] before any column was added. '
                . 'Index a named column with index(\'name\') instead.',
                $this->table
            ));
        }

        return $last;
    }

    /**
     * The column definitions plus the keys, in the order a CREATE TABLE wants
     * them: columns first, then the composite keys, then the primary key last.
     *
     * @return list<string>
     */
    private function definitionList(): array
    {
        $definitions = [];

        foreach ($this->columns as $column) {
            $definitions[] = sprintf('`%s` %s', $column['name'], $column['definition']);
        }

        foreach ($this->indexes as $index) {
            $definitions[] = $index;
        }

        // Constraints before the primary key. MySQL accepts either order, but
        // dbDelta() parses the key and constraint lines positionally and can
        // mis-read a body whose PRIMARY KEY is not last.
        foreach ($this->constraints as $constraint) {
            $definitions[] = $constraint;
        }

        $primary = $this->primaryKeyColumn();

        if ($primary !== null) {
            $definitions[] = sprintf('PRIMARY KEY (`%s`)', $primary);
        }

        return $definitions;
    }

    /**
     * The column declared as the table's primary key, if any.
     *
     * Only `id()` declares one, and it is emitted as a *table-level* key rather
     * than the inline `PRIMARY KEY` MySQL also accepts. That is not style:
     * `dbDelta()` is line-oriented and parses the key definitions at the end of
     * the column list, so an inline one is invisible to it — and a table created
     * without a primary key is a table the next `migrate` silently fails to add
     * one to.
     */
    private function primaryKeyColumn(): ?string
    {
        foreach ($this->columns as $column) {
            if ($column['primary'] ?? false) {
                return $column['name'];
            }
        }

        return null;
    }

    /**
     * Record a unique index, replacing any plain index over the same columns.
     *
     * @param list<string> $columns
     */
    private function addUnique(array $columns): static
    {
        $plain = $this->compileIndex($columns);

        // The plain index over exactly these columns is removed rather than
        // kept: a unique index serves the same lookups, and two B-trees over one
        // column is pure write cost. Matched on the compiled fragment because
        // that is the only place the column list is spelled out.
        $this->indexes = array_values(array_filter(
            $this->indexes,
            static fn (string $index): bool => $index !== $plain
        ));

        $this->indexes[] = substr_replace($plain, 'UNIQUE KEY', 0, 3);

        return $this;
    }

    /**
     * Compile an index over $columns into a `CREATE TABLE` clause.
     *
     * The name is always spelled out, never left to MySQL. Two reasons, and the
     * second is the one that bites:
     *
     *  - `Schema::table()` drops an index by name, so an unnamed one could not be
     *    dropped later without first asking the database what MySQL decided to
     *    call it.
     *  - `dbDelta()`, which compiles every `Schema::create()` here, falls back to
     *    the *raw* statement body once the table exists — and its own parser
     *    builds an index definition that always carries a name, reading an empty
     *    one as `` KEY `` `` (`` `col` ) `` for an unnamed clause. That can never
     *    match the real `SHOW INDEX` name, so every subsequent run of the same
     *    migration re-issues `ALTER TABLE ... ADD` for an index that is already
     *    there. On a unique index over existing duplicate rows that ALTER fails,
     *    which is the entire migration failing on its second execution.
     *
     * @param list<string> $columns
     */
    private function compileIndex(array $columns): string
    {
        $name = implode('_', $columns);

        return sprintf('KEY `%s` (%s)', $name, $this->quoteList($columns));
    }

    /**
     * @param list<string> $columns
     */
    private function quoteList(array $columns): string
    {
        $quoted = [];

        foreach ($columns as $column) {
            $quoted[] = '`' . $column . '`';
        }

        return implode(', ', $quoted);
    }

    /**
     * A default value as an SQL literal.
     *
     * Escaped rather than bound, because a `DEFAULT` is part of the schema and a
     * prepared statement cannot carry one — this is the one place in the builder
     * where a value becomes SQL text. `true`/`false` compile to `1`/`0`, which is
     * what `tinyint(1)` holds; the alternative, `DEFAULT TRUE`, is accepted by
     * MySQL 8 and rejected by 5.7 and by MariaDB, so writing the number is the
     * only spelling that works everywhere the plugin claims to run.
     *
     * Backslashes are doubled *before* quotes are doubled. MySQL treats a
     * backslash as an escape character inside a string literal, so a Windows
     * path ending in a separator — `C:\path\` — would otherwise escape its own
     * closing quote and swallow the rest of the statement. The order matters:
     * escaping quotes first would turn `\'` into `\''`, and the backslash pass
     * would then double the backslash that was already doing the escaping.
     */
    private static function compileDefault(string|int|float|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
    }

    /**
     * Assert a table or column name is usable as an identifier.
     *
     * @throws SchemaException
     */
    private function assertIdentifier(string $name, string $kind): void
    {
        // A dollar sign is included because MySQL permits one and WordPress
        // produces them in table names derived from a site's own; refusing it
        // would reject a name the host application considers entirely normal.
        if ($name === '' || preg_match('/^[A-Za-z0-9_$]+$/', $name) !== 1) {
            throw SchemaException::invalidIdentifier($name === '' ? '(empty ' . $kind . ' name)' : $name, $kind);
        }

        if (strlen($name) > 64) {
            throw new SchemaException(sprintf(
                '[%s] is longer than MySQL allows for a %s name (64 characters).',
                $name,
                $kind
            ));
        }
    }
}
