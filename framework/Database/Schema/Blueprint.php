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
        ?callable $resolveTable = null
    ) {
        $this->resolveTable = $resolveTable ?? static fn (string $name): string => $name;
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
            $definition = str_replace(' NOT NULL', '', $definition);

            // A column that becomes nullable keeps any default it was given, but
            // one it inherited from its *type* — `boolean()` and `id()` both
            // emit one — loses it. The distinction is what stops a nullable
            // boolean from reporting `false` for a row whose value was never
            // set, which is the difference between "no" and "no answer".
            if (($this->hasDefault[$name] ?? false) === false) {
                $definition = preg_replace('/ DEFAULT .*$/', '', $definition) ?? $definition;
            }

            return $definition;
        });
    }

    /**
     * Give the most recently added column a default value.
     *
     * @throws SchemaException When no column has been added yet.
     */
    public function default(string|int|float|bool $value): static
    {
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

    // ---------------------------------------------------- indexes to drop

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
        if ($this->creating) {
            throw new SchemaException(sprintf(
                '%s was requested while creating table [%s]. Dropping an index is an ALTER TABLE '
                . 'operation and has nothing to drop on a table that does not exist yet; use '
                . 'Schema::table() with the table already created.',
                $clause,
                $this->table
            ));
        }

        $this->alterations[] = $clause;

        return $this;
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
     */
    private static function compileDefault(string|int|float|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . str_replace("'", "''", $value) . "'";
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
            throw SchemaException::invalidTableName($name === '' ? '(empty ' . $kind . ' name)' : $name);
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
