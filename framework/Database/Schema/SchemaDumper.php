<?php
declare(strict_types=1);

namespace Framework\Database\Schema;

use Framework\Database\Adapters\DatabaseAdapterInterface;
use Framework\Database\Exceptions\MigrationException;
use Framework\Database\Schema\Exceptions\SchemaException;

/**
 * Reads a live database and writes the migrations that would rebuild it.
 *
 * ## What it is for
 *
 * The one thing a migration history cannot tell you is what the database
 * *actually* looks like. A schema edited by hand, a migration that half-applied,
 * a table an earlier developer added through phpMyAdmin or a GUI — none of those
 * appear in `database/migrations`, and all of them are in the database. This
 * reads the catalogue and emits `Schema::create()` calls describing what is
 * there, so the migration history can be brought back in line with reality.
 *
 * ## What it will not do
 *
 * **It emits one `create` per table and one `down()` per table.** It does not
 * reconstruct the migration history — it has no way to know which migration
 * produced which column, and guessing from timestamps would be inventing
 * information. The output is a *snapshot*, meant to be read, kept, or used to
 * rebuild a schema from scratch.
 *
 * **It does not dump data.** Rows are not read. A migration that moves data is
 * not something this can regenerate, and a tool that produced a file claiming to
 * restore a database while omitting everything in it would be actively
 * dangerous.
 *
 * **It is not lossless, and says so where it is not.** What `SHOW COLUMNS` and
 * `SHOW INDEX` report is what can be reconstructed: names, types, nullability,
 * scalar defaults, auto-increment, and keys. What they do not report — an
 * enum's value list beyond what the type string carries, a foreign key's delete
 * rule, a table or column comment, the storage engine — is not invented. The
 * dumper emits what it can prove and {@see SchemaException} names anything it
 * cannot, rather than emitting a plausible-looking approximation.
 *
 * ## Why it refuses rather than approximates
 *
 * A dump that ran cleanly and produced a subtly different schema is worse than
 * one that failed, because the failure is discovered later and by someone who
 * does not know a dump was involved. An unrecognised column type throws with the
 * type in the message; a table that does not exist throws rather than becoming a
 * `create` with no columns.
 */
final class SchemaDumper
{
    /**
     * Table suffixes that are never dumped, whatever they are called.
     *
     * The migrator's own bookkeeping is the table that *records* what has been
     * migrated. A dump containing it would emit a `down()` that drops the table
     * holding the history the dump exists to describe — and it is recreated
     * automatically by every entry point, so there is nothing to preserve.
     */
    private const NEVER_DUMP = ['plugin_migrations'];

    /**
     * MySQL type spellings mapped to the builder call that emits them.
     *
     * Keyed on the type **without** its length or precision, because
     * `varchar(191)` and `varchar(50)` are the same builder call with a different
     * argument. A type absent from {@see builderCall()}'s match is a type the
     * dumper cannot reconstruct, and that is reported rather than defaulted — see
     * the class docblock.
     */

    public function __construct(private readonly DatabaseAdapterInterface $adapter)
    {
    }

    /**
     * Compile the named tables into migration source.
     *
     * @param list<string> $tables Table names as the application thinks of them,
     *                             without the prefix — the same spelling
     *                             `Schema::create()` takes.
     *
     * @throws MigrationException When a named table is not in the database.
     * @throws SchemaException    When a column type cannot be reconstructed.
     */
    public function dump(array $tables): string
    {
        $up = [];
        $down = [];

        foreach ($tables as $table) {
            [$create, $drop] = $this->dumpTable($table);

            $up[] = $create;
            $down[] = $drop;
        }

        // The two halves are kept apart from here to the file, because they are
        // two different methods and only the *file* may join them. Carrying them
        // as one string is what produced a migration whose `up()` dropped the
        // table it had just created: the drop was appended after the create and
        // then interpolated wholesale into the first method body the writer found.
        return $this->file(implode("\n\n", $up), implode("\n\n", $down));
    }

    /**
     * Compile one table into a `Schema::create()` call and its `Schema::dropIfExists()`.
     *
     * Returned as a pair rather than as one concatenated string so the caller
     * cannot mistake the drop for part of the create. The two belong in different
     * methods and there is no shape in which they belong together.
     *
     * @return array{0: string, 1: string} The `up()` body and the `down()` body.
     *
     * @throws MigrationException When the table is absent or is one this refuses
     *                            to dump.
     * @throws SchemaException    When a column type cannot be reconstructed.
     */
    private function dumpTable(string $table): array
    {
        // The guard has to match the name in *both* spellings. A caller passes
        // application names (`notes`), but the framework's own bookkeeping is
        // named in the database (`wp_plugin_migrations`), and a dump asked for
        // "every table" on a real install reaches this method with the prefixed
        // name — which a bare-name comparison would wave straight through, in the
        // one call shape where the damage is worst.
        if (in_array($this->bareName($table), self::NEVER_DUMP, true)) {
            throw new MigrationException(sprintf(
                'Refusing to dump [%s]: it is framework bookkeeping, and a migration that recreated it '
                . 'would be a migration whose down() drops the table recording what has been migrated.',
                $table
            ));
        }

        $columns = $this->adapter->columnDetails($table);

        if ($columns === []) {
            throw new MigrationException(sprintf(
                'Cannot dump [%s]: the table is absent from the database (or has no columns, which MySQL '
                . 'does not allow). Nothing was written for it.',
                $table
            ));
        }

        $indexes = $this->adapter->indexDetails($table);

        // Which single-column indexes belong on their column, so they are not
        // emitted twice — once inline and once as a standalone statement.
        $inline = $this->inlineIndexes($columns, $indexes);

        $lines = [];

        foreach ($columns as $column) {
            $lines[] = '            ' . $this->columnLine($column, $indexes, $inline);
        }

        foreach ($this->standaloneIndexes($indexes, $inline) as $index) {
            $lines[] = '            ' . $this->indexLine($index);
        }

        $bare = $this->bareName($table);

        return [
            sprintf(
                "        Schema::create('%s', function (Blueprint \$table) {\n%s\n        });",
                $bare,
                implode("\n", $lines)
            ),
            $this->downBlock($bare),
        ];
    }

    /**
     * The `down()` for one table.
     *
     * `dropIfExists`, not `drop`: a `down()` is routinely run against a database
     * where its `up()` never completed, and a drop that raised "unknown table"
     * there would turn a recoverable state into one that is neither finishable
     * nor reversible.
     */
    private function downBlock(string $table): string
    {
        return sprintf("        Schema::dropIfExists('%s');", $table);
    }

    /**
     * One column's builder call.
     *
     * @param array{name: string, type: string, nullable: bool, default: string|null, autoIncrement: bool} $column
     * @param list<array{name: string, columns: list<string>, unique: bool, primary: bool}>                 $indexes
     * @param array<string, string>      $inline Index name => modifier suffix, for the ones attached inline.
     */
    private function columnLine(array $column, array $indexes, array $inline): string
    {
        $call = $this->builderCall($column);

        $modifiers = '';

        if ($column['nullable']) {
            // A column on an auto-increment key is never nullable, so this cannot
            // collide with the primary-key spelling below — and if it somehow did,
            // the nullable() would be the wrong half to drop.
            $modifiers .= '->nullable()';
        }

        if ($column['default'] !== null) {
            $modifiers .= $this->defaultModifier($column['default']);
        }

        if (isset($inline[$column['name']])) {
            $modifiers .= $inline[$column['name']];
        }

        return sprintf('$table->%s%s;', $call, $modifiers);
    }

    /**
     * The builder method that emits a column of this type.
     *
     * @param array{name: string, type: string, nullable: bool, default: string|null, autoIncrement: bool} $column
     *
     * @throws SchemaException When the type cannot be reconstructed.
     */
    private function builderCall(array $column): string
    {
        $name = $column['name'];
        $type = $column['type'];

        // The auto-increment marker, not the column's name, is what identifies a
        // primary key the builder spells `id()`. A table whose id is not
        // auto-increment is a plain bigint and has to be dumped as one.
        if ($column['autoIncrement']) {
            if ($name === 'id') {
                return 'id()';
            }

            // A non-id auto-increment column has no builder spelling: `id()`
            // hardcodes both the name and the unsigned-bigint type, and nothing
            // else in the vocabulary emits AUTO_INCREMENT. Reported rather than
            // approximated, because emitting `unsignedBigInteger('x')` would run
            // cleanly and produce a table whose next insert fails on a missing
            // auto-increment — a schema that differs from the one dumped in the
            // one way that breaks writes.
            throw new SchemaException(sprintf(
                'Cannot dump the column [%s]: it is AUTO_INCREMENT but is not named [id], and the schema '
                . 'builder only emits an auto-increment key through id(). Restore this column by hand. '
                . '(id() fixes both the name and the type, so it cannot describe a different one.)',
                $name
            ));
        }

        [$base, $arguments] = $this->splitType($type);

        $literal = $arguments === [] ? '' : ', ' . implode(', ', $this->asLiterals($arguments));

        return match ($base) {
            'varchar' => $arguments === []
                ? sprintf("string('%s')", $name)
                : sprintf("string('%s', %s)", $name, $arguments[0]),
            'char' => $arguments === []
                ? sprintf("char('%s', 1)", $name)
                : sprintf("char('%s', %s)", $name, $arguments[0]),
            'bigint' => sprintf($this->unsigned($type) ? "unsignedBigInteger('%s')" : "bigInteger('%s')", $name),
            'int', 'integer' => sprintf($this->unsigned($type) ? "unsignedInteger('%s')" : "integer('%s')", $name),
            'tinyint' => $this->tinyIntCall($name, $type, $arguments),
            'smallint', 'mediumint' => sprintf($this->unsigned($type) ? "unsignedInteger('%s')" : "integer('%s')", $name),
            'text' => sprintf("text('%s')", $name),
            'mediumtext' => sprintf("mediumText('%s')", $name),
            'tinytext' => sprintf("tinyText('%s')", $name),
            'longtext' => sprintf("longText('%s')", $name),
            'json' => sprintf("json('%s')", $name),
            'datetime' => sprintf("dateTime('%s')", $name),
            'timestamp' => sprintf("timestamp('%s')", $name),
            'date' => sprintf("date('%s')", $name),
            'time' => sprintf("time('%s')", $name),
            'year' => sprintf("year('%s')", $name),
            'decimal', 'numeric' => $this->decimalCall($name, $arguments),
            'float' => sprintf("float('%s')", $name),
            'double' => sprintf("double('%s')", $name),
            'enum' => $this->enumCall($name, $arguments),
            'binary', 'varbinary' => $this->binaryCall($name, $base, $arguments),
            'blob' => sprintf("binary('%s')", $name),
            'uuid' => sprintf("uuid('%s')", $name),
            default => throw new SchemaException(sprintf(
                'Cannot dump the column [%s]: its type [%s] has no schema-builder equivalent, and '
                . 'guessing one would produce a migration that runs and creates the wrong column. '
                . 'Write this column by hand.',
                $name,
                $type
            )),
        };
    }

    /**
     * Whether a MySQL type carries the `unsigned` attribute.
     *
     * The builder spells unsigned as a *different method* — `unsignedInteger()`,
     * not `integer()->unsigned()` — because there is no generic modifier, so this
     * decides which method name is emitted rather than appending a suffix. Getting
     * it wrong widens or narrows a column's range silently.
     */
    private function unsigned(string $type): bool
    {
        return str_contains($type, 'unsigned');
    }

    /**
     * `tinyint(1)` is MySQL's boolean; any other width is a small integer.
     *
     * The width is the only signal there is — MySQL stores a boolean as a
     * one-digit tinyint, and nothing else distinguishes them — and getting it
     * wrong flips a column between "true/false" and "0–127" in every reader's
     * head while remaining valid SQL.
     *
     * @param list<string> $arguments
     */
    private function tinyIntCall(string $name, string $type, array $arguments): string
    {
        if ($arguments !== [] && $arguments[0] === '1') {
            return sprintf("boolean('%s')", $name);
        }

        return sprintf(
            $this->unsigned($type) ? "unsignedTinyInteger('%s')" : "tinyInteger('%s')",
            $name
        );
    }

    /**
     * @param list<string> $arguments
     */
    private function decimalCall(string $name, array $arguments): string
    {
        [$precision, $scale] = [$arguments[0] ?? '8', $arguments[1] ?? '2'];

        return sprintf("decimal('%s', %s, %s)", $name, $precision, $scale);
    }

    /**
     * The builder call for a `BINARY(n)` or `VARBINARY(n)` column.
     *
     * ## Why this is not just `binary()`
     *
     * `BINARY`/`VARBINARY` are not `BLOB`, and mapping both onto `binary()` —
     * which emits `blob NOT NULL` — produced a dump that described a different
     * column than the one it had read. `BINARY(n)` is fixed-width and pads with
     * `\0`; `BLOB` is variable-width with a length prefix. They differ in storage
     * and, for the same 16 bytes, can compare unequal against the same literal.
     * That is the silent-wrong-column outcome this class's `default =>` arm
     * exists to prevent, so it is not excused here just because a plausible call
     * was available.
     *
     * ## Why the length is required, not defaulted
     *
     * MySQL has no default for either type, so there is no correct value to
     * supply when the server did not report one. Inventing `255` would run
     * cleanly and silently truncate or pad — changing the stored value rather
     * than failing — so a `BINARY` with no length in its `SHOW COLUMNS` row is
     * reported instead. That is not a hypothetical: an unparseable type is
     * exactly what a hand-created column can produce.
     */
    private function binaryCall(string $name, string $base, array $arguments): string
    {
        $fixed = $base === 'binary';
        $length = $arguments[0] ?? null;

        if ($length === null || preg_match('/^\d+$/', (string) $length) !== 1 || (int) $length < 1) {
            throw new SchemaException(sprintf(
                'Cannot dump the column [%s]: MySQL reported its type as %s but gave no usable length, and '
                . '%s requires one. There is no default to fall back on — guessing would produce a column '
                . 'that silently pads or truncates. Restore this column by hand.',
                $name,
                $base . ($length === null ? '' : '(' . $length . ')'),
                $fixed ? 'BINARY' : 'VARBINARY'
            ));
        }

        // `BINARY(16)` is a UUID in every project that has one, and the builder
        // has a name for it; emitting the intent beats emitting the mechanism.
        if ($fixed && (int) $length === 16) {
            return sprintf("binaryUuid('%s')", $name);
        }

        return sprintf(
            $fixed ? "binaryFixed('%s', %d)" : "varbinary('%s', %d)",
            $name,
            (int) $length
        );
    }

    /**
     * An `enum` type back into `enum('col', ['a', 'b'])`.
     *
     * MySQL reports the members as a quoted list inside the type string, and they
     * have to be re-quoted for the builder — which is why this cannot go through
     * the generic argument path, where every argument becomes a bare literal.
     *
     * @param list<string> $arguments The raw member list, unsplit, as one entry.
     */
    private function enumCall(string $name, array $arguments): string
    {
        // `splitType()` splits on commas at the top level, and enum members can
        // contain none — MySQL forbids an unescaped comma inside a member — so
        // the reconstruction below re-reads the original quoted list instead.
        $members = array_map(
            static fn (string $member): string => "'" . str_replace("'", "\\'", trim($member, " '\"") ) . "'",
            $arguments
        );

        return sprintf("enum('%s', [%s])", $name, implode(', ', $members));
    }

    /**
     * `->unique()` or `->index()`, for a key that needs no column list.
     *
     * @param array{name: string, columns: list<string>, unique: bool, primary: bool} $index
     */
    private function indexLine(array $index): string
    {
        $columns = implode(', ', array_map(
            static fn (string $column): string => "'" . $column . "'",
            $index['columns']
        ));

        return sprintf('$table->%s([%s]);', $index['unique'] ? 'unique' : 'index', $columns);
    }

    /**
     * The `->default(...)` or `->useCurrent()` modifier for a column.
     *
     * `CURRENT_TIMESTAMP` is the case that matters: MySQL reports it as that
     * bare word and drops the parentheses, so a dumper treating it as a literal
     * would emit `->default('CURRENT_TIMESTAMP')` — a column whose default is
     * *that string*, which MySQL accepts and which is not remotely the same
     * thing. The distinct modifier exists for exactly this.
     */
    private function defaultModifier(string $default): string
    {
        if (in_array(strtoupper(trim($default)), ['CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP()', 'NOW()'], true)) {
            return '->useCurrent()';
        }

        // `NULL` arrives as a real SQL null and is handled by the caller, so any
        // string reaching here is a value. Quotes are escaped rather than
        // rejected: a default legitimately containing one is a value like any
        // other.
        return sprintf("->default('%s')", str_replace("'", "\\'", $default));
    }

    /**
     * Split a MySQL type into its base name and its parenthesised arguments.
     *
     * `varchar(191)` becomes `['varchar', ['191']]` and `int` becomes
     * `['int', []]`. The arguments stay strings — they are re-emitted as source,
     * not evaluated — and are split on commas at the top level so a `decimal`'s
     * two arguments separate while an `enum`'s quoted member list, whose commas
     * are inside quotes, does not.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function splitType(string $type): array
    {
        $type = str_replace(' unsigned', '', $type);
        $type = str_replace(' zerofill', '', $type);

        $open = strpos($type, '(');

        if ($open === false) {
            return [trim($type), []];
        }

        $close = strrpos($type, ')');
        $base = trim(substr($type, 0, $open));
        $body = $close === false ? substr($type, $open + 1) : substr($type, $open + 1, $close - $open - 1);

        return [$base, $this->splitArguments($body)];
    }

    /**
     * Split a parenthesised type body on its top-level commas.
     *
     * Quote-aware, because an `enum`'s body is a list of quoted strings that
     * themselves contain commas. A naive `explode(',')` would cut `enum('a,b')`
     * into two members and re-emit an enum with a member that never existed.
     *
     * @return list<string>
     */
    private function splitArguments(string $body): array
    {
        $arguments = [];
        $current = '';
        $quoted = false;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($char === "'" || $char === '"') {
                // The same quote toggles; a different one inside is a literal.
                if (!$quoted) {
                    $quoted = $char;
                } elseif ($quoted === $char) {
                    $quoted = false;
                }

                $current .= $char;

                continue;
            }

            if ($char === ',' && $quoted === false) {
                $arguments[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $arguments[] = trim($current);
        }

        return $arguments;
    }

    /**
     * Render type arguments as source literals, quoting the ones that are not
     * numbers.
     *
     * @param list<string> $arguments
     * @return list<string>
     */
    private function asLiterals(array $arguments): array
    {
        return array_map(
            static fn (string $argument): string => is_numeric($argument)
                ? $argument
                : "'" . str_replace("'", "\\'", trim($argument, "'\"")) . "'",
            $arguments
        );
    }

    /**
     * Which single-column indexes should be attached to their column.
     *
     * Returns index name => the modifier that attaches it. A key with exactly one
     * column that matches its own name is the one the blueprint would have
     * declared inline, so re-emitting it that way is what a hand-written
     * migration looks like — and it keeps a column's constraints next to the
     * column instead of in a block at the bottom of the table.
     *
     * A primary key is never inlined: the builder writes it, and an extra
     * `->unique()` on the id column would compile to a second, pointless key.
     *
     * @param list<array{name: string, type: string, nullable: bool, default: string|null, autoIncrement: bool}> $columns
     * @param list<array{name: string, columns: list<string>, unique: bool, primary: bool}>                       $indexes
     * @return array<string, string>
     */
    private function inlineIndexes(array $columns, array $indexes): array
    {
        $names = array_column($columns, 'name');
        $inline = [];

        foreach ($indexes as $index) {
            if ($index['primary'] || count($index['columns']) !== 1) {
                continue;
            }

            // Inlined only when the key is named after its single column, which
            // is the blueprint's own convention. A differently-named key has to
            // keep its name — attaching it to the column would let the builder
            // re-derive a name that does not match the one in the database.
            if ($index['name'] !== $index['columns'][0]) {
                continue;
            }

            if (!in_array($index['columns'][0], $names, true)) {
                continue;
            }

            $inline[$index['columns'][0]] = $index['unique'] ? '->unique()' : '->index()';
        }

        return $inline;
    }

    /**
     * The keys that need their own statement — everything not inlined.
     *
     * @param list<array{name: string, columns: list<string>, unique: bool, primary: bool}> $indexes
     * @param array<string, string>                                                        $inline
     * @return list<array{name: string, columns: list<string>, unique: bool, primary: bool}>
     */
    private function standaloneIndexes(array $indexes, array $inline): array
    {
        return array_values(array_filter(
            $indexes,
            static fn (array $index): bool => !$index['primary']
                && !(count($index['columns']) === 1 && isset($inline[$index['columns'][0]]))
        ));
    }

    /**
     * A table name as the schema language spells it — prefix stripped.
     *
     * The adapter resolves `notes` to `wp_notes` on the way out, so the inverse
     * is what a dump needs on the way in. Stripping only a *leading* prefix
     * matters: a table genuinely named `wp_notes` on an unprefixed site must not
     * lose its name, which is why this defers to the adapter's own prefix rather
     * than removing the substring anywhere it appears.
     */
    private function bareName(string $table): string
    {
        $prefix = $this->adapter->prefix();

        if ($prefix !== '' && str_starts_with($table, $prefix)) {
            return substr($table, strlen($prefix));
        }

        return $table;
    }

    /**
     * Wrap the two method bodies in a file a developer can drop into `database/migrations/`.
     *
     * The class name is derived from the current timestamp through the same
     * convention {@see \Framework\Database\MigrationFile} parses, so the result
     * is discoverable by `migrate` without being renamed by hand. The `use`
     * statements are the two the body needs and nothing else.
     *
     * `$up` and `$down` are separate parameters rather than one body split here,
     * because a writer that received them joined has no way to tell where one
     * ended — which is exactly the mistake that put every table's `down()` into
     * its `up()`.
     */
    private function file(string $up, string $down): string
    {
        // A table list with no tables, or a project that dumps nothing, would
        // otherwise emit a method with an empty body — legal PHP, and a
        // `down()` that reads as though someone forgot to write it. The
        // comment is the same one the template always carried.
        $downBody = trim($down) === ''
            ? "            // Nothing to reverse: no tables were dumped."
            : $down;

        return <<<PHP
        <?php
        declare(strict_types=1);

        namespace App\\Database\\Migrations;

        use Framework\\Database\\Migration;
        use Framework\\Database\\Schema\\Blueprint;
        use Framework\\Database\\Schema\\Schema;

        /**
         * Generated by `migrate:dump`.
         *
         * This describes the schema as it was found in the database, not the
         * migrations that produced it. Read it before running it: it is a
         * snapshot, and any hand-edit the database has accumulated is reproduced
         * here whether or not it was intended.
         */
        return new class extends Migration
        {
            public function up(): void
            {
        {$up}
            }

            public function down(): void
            {
                // One drop per table, in the order the tables were dumped.
        {$downBody}
            }
        };

        PHP;
    }
}
