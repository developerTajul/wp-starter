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
 * Holds no connection, issues no statement and knows no SQL dialect: it is a
 * description of columns and the name to put them under. Compiling it into a
 * statement is the adapter's job ({@see DatabaseAdapterInterface}), which is
 * what lets a test assert against a blueprint with nothing running below it.
 *
 * `Schema::create()` owns execution and hands the finished blueprint to the
 * adapter once the callback returns, so a half-built table is never created.
 * {@see Schema::create()} emits one `CREATE TABLE`; {@see Schema::table()} is
 * given a table that already exists and emits an `ALTER TABLE ... ADD COLUMN`
 * per column. For the WordPress adapter the second shape matters: `dbDelta()`
 * only diffs tables named by the statement itself, so a column added to an
 * existing table is a change it can never see.
 *
 * Column names are validated ({@see assertIdentifier()}): they are grammar and
 * cannot be bound. Defaults are values and are escaped as literals here, since
 * a prepared statement cannot carry a `DEFAULT` clause.
 */
class Blueprint
{
    /**
     * Column types this builder can express, mapped to the SQL a MySQL-family
     * database uses for them.
     *
     * A closed set, with no `raw()` escape hatch: an arbitrary string in a
     * migration would fail at migration time on a developer's machine or at
     * deploy time on the operator's, whichever it reached first.
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
     * Longest `varchar` MySQL will index on utf8mb4: 191 characters times four
     * bytes stays inside the 767-byte single-column index limit on the older
     * row formats WordPress still supports.
     */
    public const MAX_INDEXED_STRING_LENGTH = 191;

    private const MAX_VARCHAR_LENGTH = 65535;

    /**
     * Columns defined so far, each already compiled to an SQL fragment.
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
     * Consulted by {@see nullable()} so the order of `->default(0)->nullable()`
     * and `->nullable()->default(0)` cannot change the result: the answer comes
     * from whichever call recorded a default, not from the last one to run.
     *
     * @var array<string, bool>
     */
    private array $hasDefault = [];

    /**
     * `ALTER TABLE` actions that are neither a column nor a constraint, already
     * compiled. Index drops, in practice.
     *
     * Kept apart from {@see $columns} because the statement shapes differ: a
     * column compiles to `ADD COLUMN x ...`, one of these to a whole action
     * clause.
     *
     * @var list<string>
     */
    private array $alterations = [];

    /**
     * Columns added by {@see foreignId()} still awaiting a `constrained()` call,
     * by column name.
     *
     * `constrained()` follows `foreignId()` as a separate call, sometimes with
     * nothing between them, so it applies to the most recent one. A
     * `foreignId()` with no `constrained()` after it is a legitimate unsigned
     * big integer with no constraint, which is why this is a record rather than
     * a pending obligation.
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
     * Separate from {@see $indexes}: a `CONSTRAINT ... FOREIGN KEY` creates its
     * own index implicitly, so an explicit `KEY` over the same column would be a
     * second B-tree over the same data. A migration that asks for both (as the
     * Laravel originals do, since Laravel's `foreignId()->constrained()` emits
     * only the constraint) therefore ends up with one index.
     *
     * @var list<string>
     */
    private array $constraints = [];

    /**
     * Columns defined with a type that cannot carry an index, by the reason.
     *
     * Filled by the type methods because MySQL rejects `KEY (content)` on a
     * `longtext` column at creation. A caller asking for one has a design
     * problem worth reporting, not a statement worth letting the database
     * refuse with a message about key length.
     *
     * @var array<string, string>
     */
    private array $unindexable = [];

    /**
     * Columns that cannot carry a `DEFAULT`, by the reason.
     *
     * Populated by the type methods, consulted by {@see default()}. Three types
     * need it: `json` because MySQL rejects the clause outright, `enum`/`set`
     * because MySQL accepts a default outside the member list and silently
     * stores the empty member rather than refusing it.
     *
     * Refused at build time because neither failure is visible from the
     * migration source: the first is a statement error that does not name the
     * column, the second is not an error at all.
     *
     * @var array<string, string>
     */
    private array $noDefault = [];

    /**
     * Resolves a table name from how a schema names it to how the database
     * spells it: the prefix, plus whatever else the target needs.
     *
     * A callable rather than the adapter itself, so the blueprint depends on a
     * way to name a table rather than the whole database seam. That is what
     * makes it constructible in a test with `fn (string $t): string => 'wp_' . $t`.
     *
     * @var callable(string): string
     */
    private $resolveTable;

    /**
     * Reads an existing column's definition back from the database.
     *
     * Used by {@see renameColumn()} only. MySQL's `CHANGE COLUMN` makes the
     * caller restate the definition, but a rename supplies two names, so the
     * definition has to come from the target rather than from the caller or from
     * a schema-introspection dependency this framework does not carry.
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
     * column-building code to refuse what is legal in a `CREATE` and illegal in
     * an `ALTER`.
     */
    public function isCreating(): bool
    {
        return $this->creating;
    }

    // --------------------------------------------------------------- columns

    /**
     * An auto-incrementing unsigned big integer primary key.
     *
     * Refused when adding to an existing table: MySQL permits one
     * auto-increment column per table, so `id()` inside a `Schema::table()`
     * block is a request the database would always reject.
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
     * The default is 191 rather than Laravel's 255: 255 characters times four
     * bytes overruns the 767-byte single-column index limit on utf8mb4 for the
     * row formats WordPress still supports. Laravel leaves that to the
     * application to shorten in a service provider; this framework cannot assume
     * it was told. A column that genuinely needs 255 asks for it and is allowed
     * one, since most columns are never indexed.
     *
     * Above 65535 the type is not a `varchar` at all, so that is refused here.
     * A length over {@see MAX_INDEXED_STRING_LENGTH} is not refused: the column
     * may never be indexed, and the refusal belongs at the index
     * ({@see assertIndexable()}).
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
     * `longtext` rather than `text`, matching the project's hand-written
     * migrations: the two are identical until 65KB, where the 4-byte-per-row
     * overhead of the larger type is cheaper than a truncated body in
     * production.
     *
     * Unindexable without a prefix length, so no index is emitted for one.
     */
    public function text(string $column): static
    {
        $this->unindexable[$column] = sprintf(
            'MySQL cannot index a longtext column without a prefix length, so a KEY on [%s] would be '
            . 'rejected at creation. Index a separate, shorter column instead, a hash of the content, '
            . 'or its first 191 characters in a varchar().',
            $column
        );

        return $this->addColumn($column, 'longtext NOT NULL');
    }

    /**
     * A `longtext` column, spelled out.
     *
     * The same type {@see text()} emits. It exists so a dumper has a name that
     * says what it means: `SHOW COLUMNS` reports `longtext`, and a
     * reconstruction forced to call it `text()` reads as a deliberate downgrade
     * to anyone comparing the two.
     */
    public function longText(string $column): static
    {
        return $this->text($column);
    }

    /**
     * A JSON column.
     *
     * MySQL's native `json` type, not a `longtext` holding JSON: `json` validates
     * on write, so a malformed document is rejected at the statement rather than
     * discovered months later, and it normalises key order and whitespace.
     *
     * No default: MySQL does not permit a `DEFAULT` on a `json` column (before
     * 8.0.13 the clause is rejected outright, after only an expression default
     * is allowed), so {@see default()} refuses to apply to one. Make the column
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
     * Awkward to change (adding a value rewrites the table on older MySQL) but
     * still right for a set that is closed and known: a status, a role, a
     * direction. The alternative, a `varchar(191)` with the valid values in
     * application code, has the same alteration problem and no enforcement.
     *
     * Each member is quoted as a SQL literal through {@see compileDefault()}, so
     * a value containing a quote cannot break out of the list. MySQL's 255-byte
     * limit is on the total across the list and overshooting is a statement
     * error rather than a truncation, so it is reported here where the list is
     * visible.
     *
     * @param list<string> $values The permitted values, in the order MySQL stores
     *                             them. That is the order `ORDER BY` on the column
     *                             sorts by, which is not alphabetical.
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
     * The multi-select counterpart to {@see enum()}, carrying the same trade-off
     * further: at most 64 members, and querying one needs a `FIND_IN_SET()`
     * rather than an index-friendly equality. The alternative, a comma-joined
     * `varchar`, makes the same query and loses enforcement.
     *
     * MySQL stores a `SET` as a bitmask, so `ORDER BY` sorts by the declared
     * order, not alphabetically. Same escaping and 255-byte ceiling as
     * {@see enum()}.
     *
     * @param list<string> $values At most 64 permitted members.
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
     * Not a `varchar` with a short length. The distinction matters for a
     * fixed-width code (a country code, a currency code, a hash), where the
     * padding is meaningful and an index is denser than over a variable-length
     * column.
     *
     * `CHAR` right-pads with spaces and strips them on read, so `'ab '` and `'ab'`
     * compare equal. That is the point for a code and a trap elsewhere, so the
     * length is required: the caller has to know the value's real width.
     *
     * @throws SchemaException When the length is outside MySQL's 1-255 range.
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
     * Text rather than `BINARY(16)`: the binary form is half the size, but it is
     * unreadable in every `SELECT`, log line and manual repair, and it needs a
     * conversion applied identically on both sides of every comparison. For a
     * plugin table a human will inspect, readable wins.
     *
     * At `utf8mb4` a `CHAR(36)` is 144 bytes, inside the 767-byte index limit, so
     * a `UNIQUE` over it is fine.
     */
    public function uuid(string $column): static
    {
        return $this->addColumn($column, 'char(36) NOT NULL');
    }

    /**
     * A `CHAR(26)` holding a ULID.
     *
     * 26 characters of Crockford base32: 48 bits of millisecond timestamp, 80
     * bits of randomness.
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
     * Both are a quoted list under a different keyword, with the same escaping
     * and the same 255-byte total.
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
                . 'values cannot be written to at all, name at least one, or use string().',
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

        // MySQL's limit on the combined length of an ENUM/SET definition. Checked
        // here because MySQL's "Column length too big" error names neither the
        // column nor by how much.
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
     * A `MEDIUMTEXT` column, up to 16MB.
     *
     * Between {@see text()}'s `longtext` (4GB) and a `varchar` (65KB); choosing
     * it for a column known to hold a few kilobytes states an intent the reader
     * can act on. Also indexable with a prefix length, where `longtext` is not.
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
     * A `TINYTEXT` column, up to 255 bytes.
     *
     * The `varchar(255)` counterpart, preferred for the reason `text()` is a
     * `longtext`: it is stored off-page past a threshold, so a row full of short
     * values stays small. For anything that also needs an index, `string()` is
     * the better choice.
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
     * For a value that is genuinely approximate and where storage matters: a
     * coordinate, a measurement. Not for money, since binary floating point
     * cannot represent 0.10 exactly and a sum of floats drifts. Use
     * {@see decimal()} for anything that has to add up.
     */
    public function float(string $column): static
    {
        return $this->addColumn($column, 'float NOT NULL');
    }

    /**
     * A double-precision float. The wider counterpart to {@see float()}, subject
     * to the same warning about money.
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
     * Distinct from an `integer` holding a year, which stores four times as much
     * to gain nothing.
     */
    public function year(string $column): static
    {
        return $this->addColumn($column, 'year(4) NOT NULL');
    }

    /**
     * A `TIME` column, holding a clock time with no date.
     *
     * The counterpart to {@see date()}. MySQL also uses `TIME` for elapsed
     * durations, so it accepts values past 24 hours and negative values, and a
     * schedule column and a duration column can share the type.
     */
    public function time(string $column): static
    {
        return $this->addColumn($column, 'time NOT NULL');
    }

    /**
     * A `BLOB` column, holding raw bytes.
     *
     * `blob` rather than `longblob`, for a value known to be small. MySQL's
     * `blob` is binary with no character set, so it neither validates nor
     * converts on read: right for a hash, a key or an encoded payload, wrong for
     * anything a human reads.
     *
     * No `default()`: MySQL refuses a `DEFAULT` on a blob column, as it does on
     * `json`, because there is no literal syntax for the value.
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
     * Not the same type as {@see binary()}: `BLOB` is variable-width with a
     * two-byte length prefix and compares by content, while `BINARY(n)` is
     * fixed-width, pads on the right, and compares by the padded bytes. A
     * `BINARY(16)` holding a UUID and a `BLOB` holding the same 16 bytes can
     * compare unequal against one literal, so they cannot stand in for each
     * other.
     *
     * The length is required because MySQL has no meaningful default for
     * `BINARY`, and a wrong one silently truncates or pads. Sixteen is the
     * common case (a UUID, or the output of `INET6_ATON()`) but not the only one.
     *
     * `default()` is refused for the same reason as `binary()`: no literal
     * syntax exists for a binary value, so an emitted default would be read as
     * characters rather than bytes.
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
     * The variable-width counterpart to {@see binaryFixed()}, for a value whose
     * length varies: a hash, a token, an encrypted payload. Like `BLOB` it is
     * variable-width, but it carries an explicit maximum the database enforces
     * on write, which is what matters for an indexed column.
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
     * Sixteen bytes rather than `CHAR(36)`, for a join table or a column indexed
     * and compared in bulk, where the storage and comparison cost are the point.
     * The caller takes on the conversion between the textual and binary forms,
     * applied identically on every read and write.
     *
     * A named method rather than `binaryFixed($c, 16)` so the intent is legible
     * in a migration and the length cannot be got wrong by a digit.
     */
    public function binaryUuid(string $column): static
    {
        return $this->binaryFixed($column, 16);
    }

    /**
     * Refuse a length MySQL cannot accept, at the point the mistake is made.
     *
     * MySQL's own rejection of `BINARY(0)` names the column type rather than the
     * call that produced it. A negative length is worse: it reaches the
     * statement as `binary(-1)` and fails as a syntax error on the whole CREATE.
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
     * 45 is the longest textual IPv6 form, so the column fits either family
     * without a length the caller has to reason about. Text rather than MySQL's
     * `INET6_ATON()` binary form, for the readability reason {@see uuid()} is
     * text: an address in a `SELECT` should be legible.
     */
    public function ipAddress(string $column): static
    {
        return $this->addColumn($column, 'varchar(45) NOT NULL');
    }

    /**
     * A `VARCHAR(17)` holding a MAC address in the usual colon-separated form.
     *
     * 17 is exactly `aa:bb:cc:dd:ee:ff`.
     */
    public function macAddress(string $column): static
    {
        return $this->addColumn($column, 'varchar(17) NOT NULL');
    }

    /**
     * A signed integer column.
     *
     * For a value that can go negative: a count, an offset, a delta. Not for an
     * identifier; use {@see unsignedBigInteger()} for a foreign key, since a
     * negative id is not a row that can exist.
     */
    public function integer(string $column): static
    {
        return $this->addColumn($column, 'int(11) NOT NULL');
    }

    /**
     * A signed big integer.
     *
     * Distinct from {@see unsignedBigInteger()} by what it can hold: the unsigned
     * spelling silently truncates a negative value to zero rather than rejecting
     * it.
     *
     * The dumper needs it because `bigint(20)` and `bigint(20) unsigned` are
     * definitions `SHOW COLUMNS` reports differently, and collapsing both to the
     * unsigned method would widen every signed column in a dump.
     */
    public function bigInteger(string $column): static
    {
        return $this->addColumn($column, 'bigint(20) NOT NULL');
    }

    /**
     * A signed one-byte integer.
     *
     * The signed counterpart to {@see unsignedTinyInteger()}, and separate for
     * the same reason `bigInteger()` is: a `tinyint(4)` holding `-1` is not a
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
     * A migration that guessed `integer()` for a foreign key would produce a
     * column holding the wrong range of values.
     */
    public function unsignedBigInteger(string $column): static
    {
        return $this->addColumn($column, 'bigint(20) unsigned NOT NULL');
    }

    /**
     * A boolean column, stored as MySQL's one-byte `tinyint(1)`.
     *
     * The width is written out because it is what tells a client library to
     * report the value as a bool; PHP's mysqli and PDO both use it. WordPress
     * does not, so `$wpdb` hands back `'0'`/`'1'` regardless. Defaulted to false
     * so an `ADD COLUMN` does not leave existing rows NULL.
     */
    public function boolean(string $column): static
    {
        return $this->addColumn($column, 'tinyint(1) NOT NULL DEFAULT 0');
    }

    /**
     * `created_at` and `updated_at`, both datetime, both not null.
     *
     * No `DEFAULT CURRENT_TIMESTAMP`: a row's creation time is the moment the
     * application decided it, which inside a long-running transaction is not the
     * moment MySQL wrote it. A database default makes the two indistinguishable
     * after the fact, which is exactly what an audit trail needs to keep apart.
     *
     * No `ON UPDATE CURRENT_TIMESTAMP` either: an `UPDATE` that deliberately
     * leaves `updated_at` alone is legitimate, and a column-level trigger cannot
     * be told to allow one.
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
     * and converted on read using the connection's timezone, so the same row
     * reads back differently through two differently configured connections.
     * `datetime` stores what it is given.
     */
    public function datetime(string $column): static
    {
        return $this->addColumn($column, 'datetime NOT NULL');
    }

    /**
     * A `DATE` column, holding a calendar day with no time component.
     *
     * Distinct from {@see datetime()} for anything compared or sorted as a day:
     * storing a date as a datetime invites a `< '2026-11-05'` comparison to
     * exclude rows on the 5th itself.
     */
    public function date(string $column): static
    {
        return $this->addColumn($column, 'date NOT NULL');
    }

    /**
     * A `TIMESTAMP` column.
     *
     * Differs from {@see datetime()} in that MySQL converts it on read using the
     * connection's timezone. Use `datetime()` unless the column is genuinely a
     * moment in absolute time.
     */
    public function timestamp(string $column): static
    {
        return $this->addColumn($column, 'timestamp NOT NULL');
    }

    /**
     * A fixed-precision decimal.
     *
     * `decimal` rather than a float: a float cannot represent 0.10 exactly in
     * binary, so summing money stored as one accumulates a drift that shows up
     * as a total off by a cent. Storage is exact at `$scale` places.
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
     * An unsigned integer, for a value that cannot go negative: an ordering, a
     * count.
     */
    public function unsignedInteger(string $column): static
    {
        return $this->addColumn($column, 'int(10) unsigned NOT NULL');
    }

    /**
     * A one-byte unsigned integer, for a value with a small fixed ceiling: a
     * percentage, a rating, a small count.
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
     * The column name is the whole input; the referenced table is derived from
     * it by {@see guessForeignTable()} (`company_id` names `companies`). That
     * derivation is Laravel's, which is why the `_id` suffix is not optional; a
     * column named `author` would resolve to a table called `author`.
     *
     * Emitted as an unsigned big integer so it matches the `id()` column it
     * points at. InnoDB rejects a mismatch as an incompatible constraint.
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
     * Two columns because a morph identifies its target by type and id together;
     * `Lead` number 7 and `Company` number 7 are different rows in different
     * tables. `{name}_id` is an unsigned big integer but not a foreign key: it
     * points at whichever table `{name}_type` names, and MySQL cannot express a
     * constraint whose target is another column's value.
     *
     * The composite index is the point of the helper. A morph lookup filters on
     * both columns, and an index on `{name}_id` alone leaves the type as a
     * post-filter over every row that shares an id, which is every relation in
     * the table since ids repeat across types.
     */
    public function morphs(string $name): static
    {
        $this->assertIdentifier($name, 'column');

        // 191, not 255: the type column is indexed below, and a longer varchar
        // cannot carry that index on utf8mb4. MySQL refuses the CREATE with
        // "specified key was too long". WordPress uses 191 for its own indexed
        // string columns for the same reason.
        $this->addColumn($name . '_type', 'varchar(' . self::MAX_INDEXED_STRING_LENGTH . ') NOT NULL');
        $this->addColumn($name . '_id', 'bigint(20) unsigned NOT NULL');
        $this->indexes[] = $this->compileIndex([$name . '_type', $name . '_id']);

        return $this;
    }

    /**
     * A nullable polymorphic relation: `{name}_type` and `{name}_id`, both null.
     *
     * The pair {@see morphs()} cannot express, for a relation that is only
     * sometimes set: an audit or activity row whose subject is optional.
     *
     * Both columns are nullable rather than one, because a morph with a type and
     * no id (or the reverse) is a relation that names nothing. The composite
     * index still works: `(NULL, NULL)` is stored and does not collide with any
     * other row, since a NULL never compares equal to another NULL.
     */
    public function nullableMorphs(string $name): static
    {
        $this->morphs($name);

        // By name rather than through `nullable()`, which only reaches the last
        // column. The last column here is `{name}_id`, so the type column would
        // otherwise silently stay NOT NULL.
        $this->makeNullable($name . '_type');
        $this->makeNullable($name . '_id');

        return $this;
    }

    /**
     * A nullable `deleted_at` datetime, for soft deletes.
     *
     * A column and nothing more: this framework has no model layer, so a query
     * meaning "only live rows" writes `WHERE deleted_at IS NULL` itself. What
     * the helper buys is the convention of one name, one type, and a nullable
     * column, so "not deleted" is NULL rather than a sentinel like
     * `'0000-00-00'` that some MySQL configurations reject outright.
     *
     * Not indexed: the usual companion is a composite index over
     * `(deleted_at, <what the table filters by>)`, which only the caller knows.
     */
    public function softDeletes(string $column = 'deleted_at'): static
    {
        $this->assertIdentifier($column, 'column');

        return $this->datetime($column)->nullable();
    }

    /**
     * A nullable `remember_token` varchar, for "remember me" sessions.
     *
     * 100 characters, matching Laravel's own migrations. Nullable because most
     * rows never have one, and an empty-string default would make "no token" and
     * "a token that happens to be empty" indistinguishable.
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
     * it points at, including the fixed-versus-variable distinction. A
     * `varchar(36)` here produces a constraint the server refuses.
     *
     * The column name is the whole input; the referenced table is derived the
     * same way {@see foreignId()} derives it, through
     * {@see guessForeignTable()}.
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
     * @see foreignUuid() for the fixed width, {@see ulid()} for the 26 chars.
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
     * Applies to the most recently added column, so it must directly follow it.
     * `$table->string('title'); $table->string('body')->nullable();` leaves
     * `title` untouched.
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
     * The by-name counterpart to {@see nullable()}, which only reaches the most
     * recent column. A helper that defines two columns needs this, since
     * `nullable()` after it reaches only the second.
     *
     * Not part of the public builder API; `private` would force the two morph
     * helpers to duplicate the definition rewrite below.
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
     * type supplied.
     *
     * A column that becomes nullable keeps a default the author gave it but
     * loses one it inherited from its type (`boolean()` and `id()` emit one).
     * That is what stops a nullable boolean reporting `false` for a row whose
     * value was never set, the difference between "no" and "no answer".
     *
     * `NULL` is written although omitting `NOT NULL` already makes a column
     * nullable, because {@see SchemaDumper} re-emits nullability explicitly, so
     * a dump and a hand-written migration would otherwise differ textually on
     * the same column.
     *
     * It goes before any surviving default, as MySQL's grammar requires
     * (`type [NULL | NOT NULL] [DEFAULT v] [extra]`); `tinyint(1) DEFAULT 1 NULL`
     * is a syntax error. {@see WpDatabaseAdapter::columnDefinition()} builds a
     * definition the same way round, and the two have to match for an `ALTER` to
     * be a no-op against a column this created.
     */
    private function dropNotNull(string $definition, string $column): string
    {
        $definition = str_replace(' NOT NULL', '', $definition);

        if (($this->hasDefault[$column] ?? false) === false) {
            $definition = preg_replace('/ DEFAULT .*$/', '', $definition) ?? $definition;
        }

        $definition = rtrim($definition);

        // Idempotent: `nullable()` can legitimately be reached twice for one
        // column (a doubled modifier, or a helper that nullables a whole group).
        // Without this the second pass emits `... NULL NULL`, which MySQL
        // rejects, turning a harmless repeat into a migration that will not run.
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
        // method defined it.
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
            // An existing default is replaced, not appended to. A type may
            // already carry one (`boolean()` does, so an ADD COLUMN does not
            // leave every existing row NULL) and appending would emit
            // `DEFAULT 0 DEFAULT 1`, a syntax error rather than an override.
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
     * Valid on a `timestamp` or `datetime` column only; MySQL applies
     * `DEFAULT CURRENT_TIMESTAMP` to those types and to nothing else.
     *
     * The one place in this builder where a database-side default is right.
     * {@see timestamps()} refuses one because a row's `created_at` should be the
     * moment the application decided rather than the moment MySQL wrote it. A
     * column like `converted_at` records when the database accepted the row,
     * which is what the application means by it.
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
     * Without it MySQL appends a new column to the end of the table. That is
     * cosmetic in the schema but not in `SELECT *`, where column order drives
     * every positional `fetch_row()` index, and it is the difference a
     * `dbDelta()`-managed table shows between two sites that applied different
     * batches.
     *
     * Also how an existing column is repositioned: MySQL's `MODIFY COLUMN`
     * takes `AFTER`, so `->after('id')->change()` moves a column already there.
     * There is no separate `moveColumn()`.
     *
     * The last positioning call wins; see {@see first()}.
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
     * and `AFTER NULL` is a syntax error.
     *
     * The last positioning call wins: `->first()->after('id')` positions after
     * `id`, `->after('id')->first()` positions first. Both clauses cannot be
     * emitted (`FIRST AFTER x` does not parse), and a second call is a
     * correction rather than an addition. Refusing the pair would break the
     * reordering idiom that makes the two methods useful together.
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
     * means removing the old one.
     *
     * @param string $clause     The SQL, `FIRST` or `AFTER \`x\``.
     * @param string $describeAs How the call reads, for the refusal message.
     *
     * @throws SchemaException When no column has been added, or the table is
     *                         being created.
     */
    private function position(string $clause, string $describeAs): static
    {
        // Refused on a CREATE: MySQL rejects FIRST/AFTER inside CREATE TABLE, and
        // a new table's column order is already the order the migration declared
        // them in.
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
     * The composite form is what covers an `ORDER BY`; an index on the first
     * column alone leaves MySQL sorting every row it returns.
     *
     * For an existing table each index is its own `ALTER TABLE ... ADD KEY`.
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
     * A unique index enforces a rule like "one company per contact" in the
     * schema. The alternative, a read-then-write check in the application, can
     * race between the read and the write.
     *
     * A column list replaces the same-named plain index rather than sitting
     * beside it: a unique index serves every lookup the plain one did, so
     * keeping both maintains a second B-tree on every write for nothing. Same
     * reasoning as `2026_10_31_222317_make_companies_name_unique.php` applies by
     * hand.
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
     * For the query shape with both a join and a sort (`WHERE note_id = ?
     * ORDER BY occurred_at`), where an index on the first column alone leaves
     * MySQL sorting every row it returns.
     *
     * The second column is named and appended rather than inferred and
     * prepended, because the leading column is the one being filtered on. The
     * pair is always added: MySQL will not use a composite index's trailing part
     * to avoid a sort when the leading part is `NULL`, and a migration is not
     * the place to guess whether a nullable column is usually null.
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
     * The caller states the whole definition. Laravel reads the current one via
     * Doctrine DBAL and applies a diff; this has no Doctrine, so `change()`
     * emits the fluent chain's definition verbatim as `MODIFY COLUMN`. Anything
     * not restated is lost: a `change()` on a `NOT NULL` column without
     * `->nullable()` keeps it `NOT NULL`, and an unstated default is dropped.
     * `$table->string('body')->change()` reads like "make body a string" and
     * actually means `varchar(191) NOT NULL`, discarding whatever else it had.
     *
     * The column must already exist. MySQL's `MODIFY COLUMN` errors on a missing
     * column, and by then the other clauses of the same `ALTER TABLE` have run,
     * so this is checked first and the message names the column.
     *
     * @throws SchemaException When no column was defined before it, the column
     *                         does not exist, or the blueprint is creating a
     *                         table.
     */
    public function change(): static
    {
        // Checked before the column is popped, because this case needs the
        // column's name for its message.
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

        // Popped, not copied: a `change()` replaces the column. Leaving it in
        // $columns would emit both an `ADD COLUMN` and a `MODIFY COLUMN`, and on
        // a table where the column exists the ADD is what fails.
        $column = array_pop($this->columns);

        $this->assertColumnExists($column['name']);

        $this->alterations[] = sprintf('MODIFY COLUMN `%s` %s', $column['name'], $column['definition']);

        // The default-tracking map is keyed by name and read by nullable(). The
        // column it described is no longer in the list being compiled, so the
        // entry is dropped rather than left for a later same-named definition to
        // read.
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
     * Two names and no type: the definition is read back from the database
     * through the adapter, so a rename cannot reset a column's type or
     * nullability. Restating a definition by hand is how a `varchar(255)`
     * quietly becomes a `varchar(191)`, or a `NOT NULL` loses its not.
     *
     * MySQL's `CHANGE COLUMN` moves the values with the name; the data is
     * preserved. It is `CHANGE COLUMN` rather than the modern `RENAME COLUMN`
     * because that one needs MySQL 8.0.
     *
     * Refused when the source column does not exist (nothing to rename, and
     * inventing a definition would create the column empty) and when the
     * destination does, since MySQL would fail with a duplicate-column error
     * after other clauses in the same statement had already run.
     *
     * @throws SchemaException When the blueprint is creating a table, the
     *                         definition cannot be read, or either name is
     *                         unusable.
     */
    public function renameColumn(string $from, string $to): static
    {
        $this->assertIdentifier($from, 'column');
        $this->assertIdentifier($to, 'column');

        // Before the definitions are read: a rename inside a `CREATE TABLE` is
        // invalid whatever the database holds, and reading first would report
        // "no such column" against a table that, on a first install, does not
        // exist yet.
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
     * This destroys the data in the column and nothing here can undo it. A
     * `down()` has to re-add the column and it comes back empty, so a migration
     * that drops one is only reversible in the structural sense.
     *
     * A list compiles into one `ALTER TABLE` with a `DROP COLUMN` clause per
     * name, not one statement each (see {@see toAddColumnStatements()}), so a
     * multi-column drop either happens or does not and a caller catching a
     * failure is never left guessing which columns went.
     *
     * Refused when creating a table, since MySQL rejects `DROP` inside
     * `CREATE TABLE`.
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
     * Named by columns rather than by index name, so a migration does not have
     * to know how the database spelled the key. The same call works against an
     * index this builder created and one created another way, since both are
     * looked up by what they cover.
     *
     * Refused when creating a table, since MySQL rejects `DROP` inside
     * `CREATE TABLE`.
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
     * Distinct from {@see dropIndex()} in name only: they compile to the same
     * statement, because MySQL has one namespace for both and `DROP KEY <name>`
     * removes whichever it is. This pairs with {@see unique()} so a caller
     * dropping a unique index says so.
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
     * MySQL has no `DROP`, `CHANGE` or `MODIFY` inside a `CREATE TABLE`, so the
     * alternative is a statement the server rejects and a syntax error where the
     * real mistake is altering a table that does not exist yet.
     *
     * `$describeAs` lets a caller name the operation in its own vocabulary when
     * the compiled clause reads differently: a rename is `CHANGE COLUMN` to MySQL
     * but `RENAME COLUMN` to the person who wrote it.
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
     * (`company_id` names `companies`) and the referenced column is that table's
     * `id`. With one, the table is named outright, which a column outside the
     * convention needs: no rule turns `assigned_to` into `users`.
     *
     * The table is stored as the schema name, not a resolved one. Resolution
     * happens at compilation, through the adapter, which prefixes every name
     * with `$wpdb->prefix`, plugin tables (`leads` to `wp_leads`) and WordPress
     * core tables alike (`users` to `wp_users`).
     *
     * The constraint stays ON DELETE RESTRICT unless a deletion rule is chained
     * after it. A cascade is a decision about what a delete means, and
     * defaulting to it would silently delete rows a migration author never
     * considered.
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
                . '<singular>_id, company_id, user_id, or the table must be named: '
                . 'constrained(\'the_table\').',
                $column,
                $this->table
            ));
        }

        $this->assertIdentifier($table, 'table');

        $this->constraints[] = sprintf(
            'CONSTRAINT `%s_%s_foreign` FOREIGN KEY (`%s`) REFERENCES `%s` (`id`)',
            // The constraint name carries the resolved table spelling, so two
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
     * `ON DELETE CASCADE`, for a row with no meaning without its parent: a deal
     * stage belonging to a deleted pipeline, a pivot row belonging to either
     * side.
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
     * `ON DELETE SET NULL`. Requires a nullable column: the rule cannot set a
     * `NOT NULL` column to null and MySQL rejects the constraint outright, so
     * this refuses when {@see nullable()} has not been called.
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
     * `ON DELETE RESTRICT`, which is also the default. Stated explicitly so a
     * reader does not have to know the default.
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

        // One statement, with every change comma-separated inside it, and this
        // is correctness rather than an optimisation.
        //
        // A migration that replaces an index (dropIndex followed by unique on
        // the same column) compiles to a DROP and an ADD naming the same index.
        // Split across two statements the ADD would create the unique index and
        // the DROP would then remove it, leaving the column unindexed. Inside
        // one statement MySQL evaluates DROP before ADD regardless of written
        // order, so the old index goes and the new one arrives.
        return [sprintf('ALTER TABLE `%s` %s', $qualifiedName, implode(', ', $clauses))];
    }

    /**
     * Whether anything was defined at all.
     *
     * Read by {@see Schema::table()} to decide whether there is a statement to
     * issue: a callback that defined nothing must not produce an `ALTER TABLE`
     * with an empty clause, which is a syntax error rather than a no-op.
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
     *
     * @throws SchemaException When the name is not a usable identifier.
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
     * The rewrite callback is given the column's name alongside its definition,
     * because {@see nullable()} consults state keyed by name. Passing the whole
     * column array instead would let a callback mutate it and disagree with the
     * list it came from.
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
     * Used by the constraint rules, which ask about a column the chain has moved
     * past (is it nullable, is it a date type) rather than the one being built.
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
     * The most recent rather than "the last column": `nullable()` routinely sits
     * between `foreignId()` and `constrained()` and does not disturb the pending
     * key. Tracking it separately is what lets `->nullable()->constrained()` and
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
     * `company_id` names `companies`: strip `_id`, then pluralise. Pluralising
     * the stem without removing the trailing `y` first would give `companys`.
     *
     * English pluralisation is irregular, so this covers only the endings that
     * appear in table names (a consonant before `y`, a sibilant needing `es`) and
     * leaves the rest alone. A name it gets wrong is not a silent failure:
     * {@see constrained()} takes the table explicitly so a column outside the
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

        // A sibilant takes `es`: `address` becomes `addresses`.
        if (preg_match('/(s|x|z|ch|sh)$/', $stem) === 1) {
            return $stem . 'es';
        }

        return $stem . 's';
    }

    /**
     * Refuse to index a column whose type cannot carry an index.
     *
     * Only columns this blueprint defined are judged: an index over a column
     * from elsewhere was never seen, so its type is unknown here.
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
        // dbDelta() parses the key and constraint lines positionally and
        // misreads a body whose PRIMARY KEY is not last.
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
     * Only `id()` declares one, emitted as a table-level key rather than the
     * inline `PRIMARY KEY` MySQL also accepts: `dbDelta()` is line-oriented and
     * parses key definitions at the end of the column list, so an inline one is
     * invisible to it, and the next `migrate` would not add the key.
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

        // The plain index over exactly these columns is removed: a unique index
        // serves the same lookups, and two B-trees over one column is pure write
        // cost. Matched on the compiled fragment, the only place the column list
        // is spelled out.
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
     * The name is always spelled out, never left to MySQL. `Schema::table()`
     * drops an index by name, and unnamed keys are the case that bites under
     * `dbDelta()`: once the table exists `dbDelta()` falls back to the raw
     * statement body, and its parser reads an unnamed clause as an empty-name
     * `KEY`. That can never match the real `SHOW INDEX` name, so every later run
     * of the same migration re-issues `ALTER TABLE ... ADD` for an index that is
     * already there. On a unique index over existing duplicate rows that ALTER
     * fails, so the whole migration fails on its second execution.
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
     * Escaped rather than bound: a `DEFAULT` is part of the schema and a prepared
     * statement cannot carry one. `true`/`false` compile to `1`/`0` because
     * `DEFAULT TRUE` is accepted by MySQL 8 but rejected by 5.7 and MariaDB.
     *
     * Backslashes are doubled before quotes are doubled. MySQL treats a backslash
     * as an escape inside a string literal, so a Windows path ending in a
     * separator (`C:\path\`) would otherwise escape its own closing quote and
     * swallow the rest of the statement. Escaping quotes first would turn `\'`
     * into `\''`, and the backslash pass would then double the escape character.
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
        // A dollar sign is allowed because MySQL permits one and WordPress
        // produces it in table names derived from a site's own.
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
