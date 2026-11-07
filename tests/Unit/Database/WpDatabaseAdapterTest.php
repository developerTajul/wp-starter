<?php
declare(strict_types=1);

namespace Tests\Unit\Database;

use Framework\Database\Adapters\WpDatabaseAdapter;
use Framework\Database\Schema\Blueprint;
use Framework\Database\Schema\Exceptions\SchemaException;
use Framework\Database\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;

/**
 * Table-name prefixing, and the foreign keys that depend on it.
 *
 * Both are regressions this file exists to catch, and neither had a test before
 * it. The adapter used to carry a whitelist of "core" tables it left unprefixed,
 * on the belief that WordPress does not prefix its own — it does, always, with
 * whatever `$table_prefix` is set to. So on a site configured `leadbridge_`,
 * `constrained('users')` compiled to `REFERENCES users`, which is a table that
 * does not exist, and the migration died at `CREATE TABLE`. Nothing in the suite
 * could see it: no test asked `tableName()` anything, and every test that ran a
 * migration ran it against a fake whose prefix happened to hide the difference.
 *
 * The prefix is `leadbridge_` rather than the fake's default `wp_` throughout,
 * deliberately. A prefix of `wp_` is the one value where the buggy whitelist and
 * the correct implementation agree, so a test written against it would have
 * passed either way.
 */
final class WpDatabaseAdapterTest extends TestCase
{
    private FakeWpdb $db;

    private WpDatabaseAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new FakeWpdb();
        $this->db->prefix = 'leadbridge_';
        $this->adapter = new WpDatabaseAdapter($this->db);

        Schema::useAdapter($this->adapter);
    }

    /**
     * `Schema::` holds its adapter statically for the life of the process, so it
     * has to be handed back — otherwise the next test class to call `Schema::`
     * runs against this one's fake.
     */
    protected function tearDown(): void
    {
        Schema::useAdapter(null);

        parent::tearDown();
    }

    public function test_table_name_prefixes_every_table_including_wordpress_core(): void
    {
        $this->assertSame('leadbridge_users', $this->adapter->tableName('users'));
        $this->assertSame('leadbridge_posts', $this->adapter->tableName('posts'));
    }

    public function test_table_name_leaves_an_already_qualified_name_alone(): void
    {
        $this->assertSame('leadbridge_leads', $this->adapter->tableName('leadbridge_leads'));
    }

    /**
     * An empty prefix is a real configuration, and `str_starts_with($name, '')`
     * is true for every name — so a guard written without checking for it first
     * reports every table as already qualified and prefixes nothing.
     */
    public function test_table_name_prefixes_nothing_when_the_prefix_is_empty(): void
    {
        $this->db->prefix = '';

        $this->assertSame('users', $this->adapter->tableName('users'));
    }

    /**
     * The FK path end to end: a constraint must leave the CREATE (dbDelta cannot
     * parse one without inventing a column named `CONSTRAINT`) and arrive as a
     * separate `ALTER TABLE`, naming the *prefixed* table it points at.
     */
    public function test_a_foreign_key_is_added_by_alter_and_points_at_the_prefixed_table(): void
    {
        Schema::create('deal_stages', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
        });

        $sql = implode("\n", $this->db->queries);

        $this->assertStringContainsString(
            'ALTER TABLE `leadbridge_deal_stages` ADD CONSTRAINT '
            . '`leadbridge_deal_stages_pipeline_id_foreign` FOREIGN KEY (`pipeline_id`) '
            . 'REFERENCES `leadbridge_users` (`id`) ON DELETE CASCADE',
            $sql
        );
    }

    /**
     * A re-run is the case that made the old shape fail: dbDelta saw the
     * `CONSTRAINT` line as a column, diffed it against the live table, and
     * emitted `ALTER TABLE ... ADD COLUMN CONSTRAINT ...` — valid on the first
     * run and a syntax error on every one after it.
     */
    public function test_running_the_same_create_twice_adds_no_second_constraint(): void
    {
        $create = static function (): void {
            Schema::create('deal_stages', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('pipeline_id')->constrained('users')->cascadeOnDelete();
                $table->string('name');
            });
        };

        $create();

        $this->db->resetQueries();
        $create();

        $repeated = array_filter(
            $this->db->queries,
            static fn (string $query): bool => stripos($query, 'ADD CONSTRAINT') !== false
        );

        $this->assertSame([], array_values($repeated));
        $this->assertSame(
            ['leadbridge_deal_stages_pipeline_id_foreign'],
            $this->db->constraints('leadbridge_deal_stages')
        );
    }

    /**
     * The CREATE itself must carry no constraint, or dbDelta misparses it — the
     * bug is invisible in the statement list and only shows up on the second run.
     */
    public function test_the_create_statement_carries_no_constraint_clause(): void
    {
        Schema::create('deal_stages', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
        });

        $create = array_values(array_filter(
            $this->db->queries,
            static fn (string $query): bool => stripos($query, 'CREATE TABLE') !== false
        ));

        $this->assertStringNotContainsString('CONSTRAINT', implode("\n", $create));
    }

    // ------------------------------------------------------------ existence probes

    /**
     * The three catalogue probes answer about the *prefixed* table.
     *
     * A probe that forgot the prefix would ask whether `notes` exists on a site
     * whose table is `leadbridge_notes`, and answer "no" for a table that is
     * plainly there — which turns every guard built on it into a guard that never
     * fires, so the migration runs its `ALTER` a second time and fails.
     */
    public function test_has_table_reports_a_prefixed_table(): void
    {
        $this->db->createTable('leadbridge_notes', ['id', 'title']);

        $this->assertTrue($this->adapter->hasTable('notes'));
        $this->assertFalse($this->adapter->hasTable('absent'));
    }

    public function test_has_column_reports_a_prefixed_tables_column(): void
    {
        $this->db->createTable('leadbridge_notes', ['id', 'title']);

        $this->assertTrue($this->adapter->hasColumn('notes', 'title'));
        $this->assertFalse($this->adapter->hasColumn('notes', 'slug'));
    }

    /**
     * A column probe against a table that does not exist is `false`, not an
     * error: the guard it backs is "add the column if it is missing", and a
     * not-yet-created table is the most common reason it is missing.
     */
    public function test_has_column_is_false_for_an_absent_table(): void
    {
        $this->assertFalse($this->adapter->hasColumn('absent', 'title'));
    }

    public function test_has_index_reports_an_index_by_name(): void
    {
        $this->db->createTable('leadbridge_notes', ['id', 'title']);

        // Added by ALTER rather than by creating a table with `->index()`, and
        // that is a limit of the fake, not a preference: its CREATE parser reads
        // column definitions only, so a `KEY` line inside a `CREATE TABLE` body
        // registers nothing. It has no index-diffing of its own and should not
        // grow any — dbDelta is the thing that diffs, and modelling that here
        // would be modelling the helper the fake exists to stay out of the way of.
        //
        // The ALTER path is the one it *does* model, precisely because the
        // adapter's own index replacement goes through it. So this is the honest
        // way to put an index in front of the probe.
        $this->db->query('ALTER TABLE `leadbridge_notes` ADD KEY `title` (`title`)');

        $this->assertTrue($this->adapter->hasIndex('notes', 'title'));
        $this->assertFalse($this->adapter->hasIndex('notes', 'absent'));
    }

    /**
     * An index probe against a table that does not exist is `false`, not an
     * error — the same contract as {@see test_has_column_is_false_for_an_absent_table},
     * and the case a `hasIndex()` guard written before a `DROP INDEX` hits on a
     * fresh install.
     */
    public function test_has_index_is_false_for_an_absent_table(): void
    {
        $this->assertFalse($this->adapter->hasIndex('absent', 'title'));
    }

    /**
     * Index names carry underscores as a matter of course — the builder derives
     * them from column lists, so `note_id_occurred_at` is the normal shape.
     *
     * That makes this the other half of the `LIKE` hazard: a probe that compared
     * with `LIKE` would let `note_id_occurred_at` be answered by
     * `noteXidXoccurredXat`, and a guard built on it would skip a `DROP` for an
     * index that is really there.
     */
    public function test_a_metacharacter_in_an_index_name_is_not_a_wildcard(): void
    {
        $this->db->createTable('leadbridge_notes', ['id']);

        $this->assertFalse(
            $this->adapter->hasIndex('notes', 'noteXidXoccurredXat'),
            'No such index exists; only a wildcard comparison would find one.'
        );
    }

    /**
     * The `_` in a WordPress prefix is a LIKE metacharacter.
     *
     * `leadbridge_notes` unescaped matches `leadbridgeXnotes`, so a sibling table
     * makes the probe report a table that is not the one asked about. The
     * underscore must be escaped before it reaches `LIKE`.
     */
    public function test_an_underscore_in_the_prefix_does_not_wildcard_the_probe(): void
    {
        $this->db->createTable('leadbridgeXnotes', ['id']);

        $this->assertFalse(
            $this->adapter->hasTable('notes'),
            'A sibling table matching the unescaped pattern must not be reported as this one.'
        );
    }

    // ------------------------------------------------------------ drop column

    /**
     * A dropped column is really gone from the table, not merely reported gone.
     *
     * Asserted through {@see DatabaseAdapterInterface::hasColumn()} as well as the
     * recorded statement, because the statement text alone proves only that the
     * builder spelled `DROP COLUMN` — it does not prove the clause was shaped so
     * MySQL accepts it. The probe reads the fake's own column list, which the
     * ALTER parser maintains, so the two assertions together say "this statement
     * removed that column".
     */
    public function test_drop_column_removes_the_column_from_the_table(): void
    {
        $this->db->createTable('leadbridge_notes', ['id', 'title', 'slug']);

        Schema::table('notes', static function (Blueprint $table): void {
            $table->dropColumn('slug');
        });

        $this->assertFalse($this->adapter->hasColumn('notes', 'slug'));
        $this->assertTrue($this->adapter->hasColumn('notes', 'title'));
        $this->assertStringContainsString(
            'ALTER TABLE `leadbridge_notes` DROP COLUMN `slug`',
            implode("\n", $this->db->queries)
        );
    }

    /**
     * Several columns drop in one statement.
     *
     * The builder already compiles every change into a single `ALTER TABLE`, and
     * a multi-column drop is the case where that matters most: each `DROP COLUMN`
     * carries its own implied data loss, so splitting them would leave a caller
     * who caught a mid-way failure with a table that lost some columns and not
     * others, and no single statement to re-run. It also keeps the drop
     * atomically ordered against any index changes in the same callback.
     */
    public function test_drop_column_accepts_a_list_and_drops_them_together(): void
    {
        $this->db->createTable('leadbridge_notes', ['id', 'title', 'slug', 'excerpt']);

        Schema::table('notes', static function (Blueprint $table): void {
            $table->dropColumn(['slug', 'excerpt']);
        });

        $this->assertStringContainsString(
            'ALTER TABLE `leadbridge_notes` DROP COLUMN `slug`, DROP COLUMN `excerpt`',
            implode("\n", $this->db->queries)
        );
        $this->assertFalse($this->adapter->hasColumn('notes', 'slug'));
        $this->assertFalse($this->adapter->hasColumn('notes', 'excerpt'));
        $this->assertTrue($this->adapter->hasColumn('notes', 'title'));
    }

    /**
     * Dropping a column from a table being *created* is refused, as dropping an
     * index is.
     *
     * MySQL has no `DROP` inside `CREATE TABLE`, so the alternative to refusing is
     * emitting a statement the server rejects — and the author would be reading a
     * syntax error when the mistake is "you dropped a column you have not made
     * yet". The refusal names the column and the table, which is what makes it
     * actionable.
     */
    public function test_drop_column_is_refused_while_creating_a_table(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/DROP COLUMN `slug` was requested while creating table \[notes\]/');

        Schema::create('notes', static function (Blueprint $table): void {
            $table->id();
            $table->dropColumn('slug');
        });
    }

    /**
     * A dropped column name is an identifier, so an unusable one is refused
     * before it can reach a statement — the same guard `addColumn()` applies.
     *
     * The message has to name a *column*. The shared identifier guard used to
     * report every rejection as a table-name problem, which for a caller who
     * passed a column sends them looking in the wrong place entirely.
     */
    public function test_drop_column_refuses_an_unusable_name(): void
    {
        $this->db->createTable('leadbridge_notes', ['id']);

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/not a usable column name/');

        Schema::table('notes', static function (Blueprint $table): void {
            $table->dropColumn('not a column');
        });
    }

    // ------------------------------------------------------------- renames

    /**
     * `renameColumn()` is an ALTER, and the column really moves.
     *
     * `RENAME COLUMN old TO new` is the modern spelling and needs MySQL 8.0;
     * `CHANGE COLUMN old new <type>` works everywhere and is what the builder
     * compiles to, because the plugin's stated floor is older than 8.0. Either
     * way the requirement is the same and is what this asserts: the old name is
     * gone, the new one is present, and the *data* is still there — a `CHANGE`
     * that got the definition wrong would silently reset every value, which is
     * why the assertion reads the row rather than only the column list.
     */
    public function test_rename_column_moves_the_column_and_keeps_its_data(): void
    {
        $this->db->createTable('leadbridge_notes', ['id', 'body']);
        $this->db->seed('leadbridge_notes', ['id' => 1, 'body' => 'hello']);

        Schema::table('notes', static function (Blueprint $table): void {
            $table->renameColumn('body', 'content');
        });

        $this->assertFalse($this->adapter->hasColumn('notes', 'body'));
        $this->assertTrue($this->adapter->hasColumn('notes', 'content'));
        $this->assertStringContainsString(
            'ALTER TABLE `leadbridge_notes` CHANGE COLUMN `body` `content`',
            implode("\n", $this->db->queries)
        );
        $this->assertSame(
            [['id' => 1, 'content' => 'hello']],
            $this->db->rows('leadbridge_notes'),
            'The value must survive the rename, not merely the column name.'
        );
    }

    /**
     * A rename carries the column's definition with it, because `CHANGE COLUMN`
     * makes the caller restate it.
     *
     * Getting this wrong is the trap the method exists to close: the builder
     * knows the definition it was given, so the caller supplies only the two
     * names and never has to repeat the type. A bare `CHANGE COLUMN old new`
     * with no definition is a syntax error, so a compiler that forgot the
     * definition would fail outright rather than quietly.
     */
    public function test_rename_column_restates_the_definition(): void
    {
        $this->db->createTable('leadbridge_notes', ['id', 'body']);

        Schema::table('notes', static function (Blueprint $table): void {
            $table->string('body')->nullable();
            $table->renameColumn('body', 'content');
        });

        $this->assertStringContainsString(
            'CHANGE COLUMN `body` `content` varchar(191) NULL',
            implode("\n", $this->db->queries)
        );
    }

    /**
     * Renaming to a name that is already taken is refused before it reaches the
     * database.
     *
     * MySQL would fail with a duplicate-column error, but by then the migration
     * has half-run. Catching it here names both columns, and catches the mistake
     * that is easiest to make when two columns differ by a suffix.
     */
    public function test_rename_column_refuses_a_name_that_is_already_taken(): void
    {
        $this->db->createTable('leadbridge_notes', ['id', 'body', 'content']);

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/content.*already|already.*content/');

        Schema::table('notes', static function (Blueprint $table): void {
            $table->renameColumn('body', 'content');
        });
    }

    /**
     * A rename from a table being created is refused, like every other ALTER.
     */
    public function test_rename_column_is_refused_while_creating_a_table(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/RENAME COLUMN `body` TO `content`.*creating table \[notes\]/s');

        Schema::create('notes', static function (Blueprint $table): void {
            $table->id();
            $table->renameColumn('body', 'content');
        });
    }

    /**
     * `Schema::rename()` renames the *table*, and addresses both names through
     * the prefix.
     *
     * A rename that prefixed only one side would move `wp_notes` to `archive`,
     * which is a table no other part of the application would ever find again —
     * and unlike most mistakes here, the data would appear to have vanished
     * rather than the migration having failed.
     */
    public function test_rename_table_prefixes_both_names(): void
    {
        $this->db->createTable('leadbridge_notes', ['id', 'title']);

        Schema::rename('notes', 'archive');

        $this->assertStringContainsString(
            'RENAME TABLE `leadbridge_notes` TO `leadbridge_archive`',
            implode("\n", $this->db->queries)
        );
        $this->assertFalse($this->adapter->hasTable('notes'));
        $this->assertTrue($this->adapter->hasTable('archive'));
    }

    /**
     * Renaming a table that is not there is an error, not a silent no-op.
     *
     * Unlike `dropIfExists()`, there is no tolerant spelling of this: a caller
     * who renames a missing table has a wrong assumption about the schema, and a
     * no-op would let the migration record itself as applied while the table it
     * believes in does not exist.
     */
    public function test_rename_table_fails_when_the_table_is_absent(): void
    {
        $this->expectException(SchemaException::class);

        Schema::rename('absent', 'archive');
    }

    // ------------------------------------------------------------- change

    /**
     * `change()` rewrites a column's definition in place.
     *
     * The definition comes from the fluent chain, not from introspection — the
     * caller states the whole new definition, which is the rule this framework
     * settled on because the alternative is Doctrine DBAL, and Doctrine is a
     * dependency this deliberately does not carry. So
     * `$table->string('body', 500)->nullable()->change()` means exactly what it
     * reads: the column becomes `varchar(500)`, nullable.
     *
     * **No `NULL` keyword**, because the builder omits it everywhere: `string()`
     * emits `NOT NULL` and `nullable()` strips that, so nullability is expressed
     * by the *absence* of `NOT NULL`. That is valid — MySQL reads a bare type as
     * nullable — and this test pins the behaviour rather than assuming the more
     * explicit spelling, because a `change()` that silently gained `NOT NULL`
     * would be the destructive direction.
     */
    public function test_change_rewrites_the_definition_from_the_chain(): void
    {
        $this->db->createTable('leadbridge_notes', ['id', 'body']);

        Schema::table('notes', static function (Blueprint $table): void {
            $table->string('body', 500)->nullable()->change();
        });

        $this->assertStringContainsString(
            'ALTER TABLE `leadbridge_notes` MODIFY COLUMN `body` varchar(500)',
            implode("\n", $this->db->queries)
        );
        $this->assertStringNotContainsString(
            'MODIFY COLUMN `body` varchar(500) NOT NULL',
            implode("\n", $this->db->queries),
            'nullable() must have stripped NOT NULL, or the change makes the column stricter.'
        );
    }

    /**
     * `change()` moves no data, and the column keeps its name.
     *
     * A `MODIFY COLUMN` that changed the name would be a rename, and one that
     * recreated the column would empty it. Asserted on the stored row, because
     * "the column still exists" is true of a drop-and-add too — and that is the
     * failure a schema-change helper is most likely to have.
     */
    public function test_change_keeps_the_column_name_and_its_data(): void
    {
        $this->db->createTable('leadbridge_notes', ['id', 'body']);
        $this->db->seed('leadbridge_notes', ['id' => 1, 'body' => 'kept']);

        Schema::table('notes', static function (Blueprint $table): void {
            $table->text('body')->change();
        });

        $this->assertTrue($this->adapter->hasColumn('notes', 'body'));
        $this->assertSame(
            [['id' => 1, 'body' => 'kept']],
            $this->db->rows('leadbridge_notes')
        );
        $this->assertStringContainsString(
            'MODIFY COLUMN `body` longtext',
            implode("\n", $this->db->queries)
        );
    }

    /**
     * Changing a column that is not there is refused.
     *
     * `MODIFY COLUMN` on an absent column is a MySQL error, so the statement
     * would fail anyway — but at the end of a batch, after the sibling clauses in
     * the same `ALTER TABLE` had already run. Refusing first names the column and
     * the table while the caller can still act on it.
     */
    public function test_change_refuses_a_column_that_does_not_exist(): void
    {
        $this->db->createTable('leadbridge_notes', ['id']);

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/body.*no such column|no such column.*body/');

        Schema::table('notes', static function (Blueprint $table): void {
            $table->string('body', 500)->change();
        });
    }

    /**
     * `change()` with nothing chained before it is refused with a message about
     * the chain, not about the database.
     *
     * A bare `change()` reached the column-modifier guard, which reports "a
     * modifier was chained before any column was added" and lists `nullable()`
     * and `default()` — true, but it does not mention `change()`, and the author
     * is looking at a line that says `change()`.
     */
    public function test_change_without_a_preceding_column_explains_itself(): void
    {
        $this->db->createTable('leadbridge_notes', ['id']);

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/change\(\)/');

        Schema::table('notes', static function (Blueprint $table): void {
            $table->change();
        });
    }

    /**
     * `change()` is refused while creating a table, like every other ALTER.
     */
    public function test_change_is_refused_while_creating_a_table(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/MODIFY COLUMN `body`.*creating table \[notes\]/s');

        Schema::create('notes', static function (Blueprint $table): void {
            $table->id();
            $table->string('body')->change();
        });
    }

    // ------------------------------------------------------- positioning

    /**
     * `after()` places an added column immediately after a named one.
     *
     * Without it MySQL appends to the end, so a column declared between two others
     * in a migration lands last in the table — which is cosmetic in the schema and
     * very much not cosmetic in `SELECT *`, where the column order is what every
     * `while ($row = ...)` loop and every positional `fetch_row()` index depends
     * on.
     */
    public function test_after_positions_an_added_column(): void
    {
        $this->db->createTable('leadbridge_notes', ['id', 'created']);

        Schema::table('notes', static function (Blueprint $table): void {
            $table->string('title')->after('id');
        });

        $this->assertStringContainsString(
            'ADD COLUMN `title` varchar(191) NOT NULL AFTER `id`',
            implode("\n", $this->db->queries)
        );
        $this->assertSame(
            ['id', 'title', 'created'],
            $this->db->columns('leadbridge_notes'),
            'The column has to land after id, not at the end.'
        );
    }

    /**
     * `first()` moves a column to the front of the table.
     *
     * The clause is `FIRST`, with no column name, and it goes where `AFTER` would
     * — making it a keyword rather than an identifier is the whole reason this is
     * a separate method and not `after(null)`.
     */
    public function test_first_positions_a_column_at_the_front(): void
    {
        $this->db->createTable('leadbridge_notes', ['id', 'created']);

        Schema::table('notes', static function (Blueprint $table): void {
            $table->string('label')->first();
        });

        $this->assertStringContainsString(
            'ADD COLUMN `label` varchar(191) NOT NULL FIRST',
            implode("\n", $this->db->queries)
        );
        $this->assertSame(
            ['label', 'id', 'created'],
            $this->db->columns('leadbridge_notes')
        );
    }

    /**
     * The last positioning call wins, rather than both being emitted.
     *
     * `FIRST AFTER x` is a syntax error, so `->first()->after('id')` has to mean
     * one thing. Last-wins is the rule `default()` already follows for the same
     * reason — a second call is a correction, not an addition — and the
     * alternative, refusing it, would break the reordering idiom.
     */
    public function test_the_last_positioning_call_wins(): void
    {
        $this->db->createTable('leadbridge_notes', ['id', 'created']);

        Schema::table('notes', static function (Blueprint $table): void {
            $table->string('title')->first()->after('created');
        });

        $sql = implode("\n", $this->db->queries);

        $this->assertStringContainsString('AFTER `created`', $sql);
        $this->assertStringNotContainsString('FIRST', $sql);
    }

    /**
     * `after()` works on `change()` too, because MySQL's `MODIFY COLUMN` takes the
     * same clauses.
     *
     * This is how a column is repositioned once it already exists — there is no
     * separate `moveColumn()`, and `AFTER` is the mechanism MySQL actually offers.
     */
    public function test_after_repositions_a_changed_column(): void
    {
        $this->db->createTable('leadbridge_notes', ['id', 'created', 'title']);

        Schema::table('notes', static function (Blueprint $table): void {
            $table->string('title')->after('id')->change();
        });

        $this->assertStringContainsString(
            'MODIFY COLUMN `title` varchar(191) NOT NULL AFTER `id`',
            implode("\n", $this->db->queries)
        );
    }

    /**
     * `after()` before any column is defined is refused, naming `after()`.
     *
     * The shared column-modifier guard reports "a modifier was chained before any
     * column was added" and lists `nullable()` and `default()` — true, but it
     * never mentions the method the author actually called.
     */
    public function test_after_without_a_column_explains_itself(): void
    {
        $this->db->createTable('leadbridge_notes', ['id']);

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/after\(\'id\'\) was called on table \[notes\] before any column/');

        Schema::table('notes', static function (Blueprint $table): void {
            $table->after('id');
        });
    }

    /**
     * A positioning clause is an identifier, so an unusable column name is
     * refused before it reaches a statement.
     */
    public function test_after_refuses_an_unusable_name(): void
    {
        $this->db->createTable('leadbridge_notes', ['id']);

        $this->expectException(SchemaException::class);

        Schema::table('notes', static function (Blueprint $table): void {
            $table->string('title')->after('not a column');
        });
    }

    /**
     * Positioning is meaningless in a `CREATE TABLE`, and MySQL rejects it.
     *
     * A new table's column order is the order the migration declared them, so
     * `after()` there is a request to express something the caller already
     * controls — refused with that said, rather than emitted and refused by the
     * server.
     */
    public function test_positioning_is_refused_while_creating_a_table(): void
    {
        $this->expectException(SchemaException::class);

        Schema::create('notes', static function (Blueprint $table): void {
            $table->id();
            $table->string('title')->after('id');
        });
    }

    // ------------------------------------------------------- extended types

    public function test_json_compiles_to_the_native_json_type(): void
    {
        $this->assertColumnDefinition(
            'json NOT NULL',
            static fn (Blueprint $table) => $table->json('meta'),
            'meta'
        );
    }

    /**
     * MySQL rejects `DEFAULT` on a `json` column, so the refusal has to happen
     * here — where the message can name the column — rather than as a statement
     * error from the server that does not.
     */
    public function test_json_refuses_a_default(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/json column/');

        Schema::create('notes', static function (Blueprint $table): void {
            $table->id();
            $table->json('meta')->default('{}');
        });
    }

    public function test_enum_compiles_with_quoted_values(): void
    {
        $this->assertColumnDefinition(
            "enum('draft', 'published', 'archived') NOT NULL",
            static fn (Blueprint $table) => $table->enum('status', ['draft', 'published', 'archived']),
            'status'
        );
    }

    /**
     * A value containing a quote must not break out of the list.
     *
     * Asserted on the doubled quote rather than on "no exception", because the
     * failure mode of getting this wrong is a statement that parses into
     * something different from what was written — not a crash.
     */
    public function test_enum_escapes_quotes_in_values(): void
    {
        $this->assertColumnDefinition(
            "enum('it''s', 'fine') NOT NULL",
            static fn (Blueprint $table) => $table->enum('label', ["it's", 'fine']),
            'label'
        );
    }

    public function test_enum_refuses_an_empty_list(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/empty ENUM list/');

        Schema::create('notes', static function (Blueprint $table): void {
            $table->id();
            $table->enum('status', []);
        });
    }

    /**
     * An empty member is indistinguishable from "nothing selected" in MySQL's own
     * encoding, so it is refused rather than stored.
     */
    public function test_enum_refuses_an_empty_member(): void
    {
        $this->expectException(SchemaException::class);

        Schema::create('notes', static function (Blueprint $table): void {
            $table->id();
            $table->enum('status', ['draft', '']);
        });
    }

    /**
     * MySQL's ceiling is 255 bytes across the whole list, and its own error does
     * not say which column or by how much.
     */
    public function test_enum_refuses_a_list_over_255_bytes(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/255 bytes/');

        Schema::create('notes', static function (Blueprint $table): void {
            $table->id();
            $table->enum('status', array_fill(0, 20, str_repeat('x', 20)));
        });
    }

    public function test_set_compiles_with_quoted_members(): void
    {
        $this->assertColumnDefinition(
            "set('email', 'sms') NOT NULL",
            static fn (Blueprint $table) => $table->set('channels', ['email', 'sms']),
            'channels'
        );
    }

    /**
     * MySQL stores a `SET` as a 64-bit mask, so 65 members is a request the
     * database cannot honour — and it is refused here with the reason rather
     * than as an opaque truncation.
     */
    public function test_set_refuses_more_than_64_members(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/64-bit mask/');

        Schema::create('notes', static function (Blueprint $table): void {
            $table->id();
            $table->set('flags', array_map(static fn (int $i): string => 'f' . $i, range(1, 65)));
        });
    }

    /**
     * A `DEFAULT` outside an `ENUM`'s member list is *not* rejected by MySQL — it
     * is silently stored as the empty member, which is a schema that accepts a
     * value nothing meant to allow.
     */
    public function test_enum_refuses_a_default(): void
    {
        $this->expectException(SchemaException::class);

        Schema::create('notes', static function (Blueprint $table): void {
            $table->id();
            $table->enum('status', ['draft', 'published'])->default('draft');
        });
    }

    public function test_char_compiles_with_its_length(): void
    {
        $this->assertColumnDefinition(
            'char(2) NOT NULL',
            static fn (Blueprint $table) => $table->char('country', 2),
            'country'
        );
    }

    public function test_char_refuses_a_length_mysql_cannot_take(): void
    {
        $this->expectException(SchemaException::class);

        Schema::create('notes', static function (Blueprint $table): void {
            $table->id();
            $table->char('country', 256);
        });
    }

    public function test_uuid_compiles_to_char_36(): void
    {
        $this->assertColumnDefinition(
            'char(36) NOT NULL',
            static fn (Blueprint $table) => $table->uuid('external_id'),
            'external_id'
        );
    }

    public function test_ulid_compiles_to_char_26(): void
    {
        $this->assertColumnDefinition(
            'char(26) NOT NULL',
            static fn (Blueprint $table) => $table->ulid('external_id'),
            'external_id'
        );
    }

    /**
     * A `CHAR(36)` is 144 bytes at utf8mb4, well inside the 767-byte index limit,
     * so a `UNIQUE` over a uuid column has to be accepted rather than refused by
     * {@see Blueprint::assertIndexable()}.
     */
    public function test_uuid_is_indexable(): void
    {
        $this->db->createTable('leadbridge_notes', ['id']);

        Schema::table('notes', static function (Blueprint $table): void {
            $table->uuid('external_id')->unique();
        });

        $this->assertTrue($this->db->hasIndex('leadbridge_notes', 'external_id'));
    }

    // ---------------------------------------------------------- helper columns

    /**
     * `softDeletes()` is a nullable datetime, and nullability is the whole point:
     * a `deleted_at` that is `NOT NULL` has to hold a sentinel for "not deleted",
     * which is the thing the column exists to avoid.
     */
    public function test_soft_deletes_is_a_nullable_datetime(): void
    {
        $this->assertColumnDefinition(
            'datetime NULL',
            static fn (Blueprint $table) => $table->softDeletes(),
            'deleted_at'
        );
    }

    public function test_soft_deletes_accepts_a_custom_column_name(): void
    {
        $this->assertColumnDefinition(
            'datetime NULL',
            static fn (Blueprint $table) => $table->softDeletes('archived_at'),
            'archived_at'
        );
    }

    /**
     * Not indexed, and asserted rather than left implicit: a bare index on
     * `deleted_at` is almost always the wrong one, because the useful index is
     * composite and depends on what the table filters by.
     */
    public function test_soft_deletes_adds_no_index(): void
    {
        Schema::create('notes', static function (Blueprint $table): void {
            $table->id();
            $table->softDeletes();
        });

        $this->assertFalse($this->db->hasIndex('leadbridge_notes', 'deleted_at'));
    }

    public function test_remember_token_is_a_nullable_varchar_100(): void
    {
        $this->assertColumnDefinition(
            'varchar(100) NULL',
            static fn (Blueprint $table) => $table->rememberToken(),
            'remember_token'
        );
    }

    /**
     * Both morph columns are null.
     *
     * This is the reason `nullableMorphs()` exists rather than callers chaining
     * `nullable()`: that modifier reaches only the *last* column, so
     * `$table->morphs('subject')->nullable()` would leave `subject_type` NOT NULL
     * — a relation that can have an id and be forbidden a type, which is exactly
     * backwards.
     */
    public function test_nullable_morphs_makes_both_columns_nullable(): void
    {
        $this->assertColumnDefinition(
            'varchar(191) NULL',
            static fn (Blueprint $table) => $table->nullableMorphs('subject'),
            'subject_type'
        );

        $this->assertColumnDefinition(
            'bigint(20) unsigned NULL',
            static fn (Blueprint $table) => $table->nullableMorphs('subject'),
            'subject_id'
        );
    }

    /**
     * The distinction from `morphs()`, asserted directly: the non-nullable form
     * still carries `NOT NULL` on both columns.
     */
    public function test_morphs_remains_not_null(): void
    {
        $this->assertColumnDefinition(
            'varchar(191) NOT NULL',
            static fn (Blueprint $table) => $table->morphs('subject'),
            'subject_type'
        );
    }

    /**
     * `nullableMorphs()` keeps the composite index `morphs()` adds — the index is
     * the reason the helper exists, and dropping it in the nullable variant would
     * make the two differ in a way nothing announces.
     *
     * Asserted on the compiled `CREATE` rather than through {@see FakeWpdb}, and
     * that is a property of the fake rather than a shortcut: its CREATE parser
     * reads column definitions only and never registers `KEY` lines, so a
     * `hasIndex()` assertion here would pass or fail on the fake's parsing rather
     * than on whether the index was emitted. The `ALTER TABLE` path — which the
     * fake *does* model, and which is how the adapter installs an index on an
     * existing table — is covered by `test_uuid_is_indexable()`.
     */
    public function test_nullable_morphs_keeps_the_composite_index(): void
    {
        $sql = $this->createSqlFor(static function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('subject');
        });

        $this->assertStringContainsString(
            'KEY `subject_type_subject_id` (`subject_type`, `subject_id`)',
            $sql
        );
    }

    public function test_foreign_uuid_is_a_char_36(): void
    {
        $this->assertColumnDefinition(
            'char(36) NOT NULL',
            static fn (Blueprint $table) => $table->foreignUuid('company_id'),
            'company_id'
        );
    }

    public function test_foreign_ulid_is_a_char_26(): void
    {
        $this->assertColumnDefinition(
            'char(26) NOT NULL',
            static fn (Blueprint $table) => $table->foreignUlid('company_id'),
            'company_id'
        );
    }

    /**
     * A `foreignUuid()` derives its target from the column name exactly as
     * `foreignId()` does, so `company_id` has to constrain to `companies`.
     *
     * Asserted on the emitted constraint rather than on a property, because the
     * derivation is shared with `foreignId()` and what matters is that the two
     * really do share it.
     */
    public function test_foreign_uuid_derives_its_target_table(): void
    {
        $sql = $this->createSqlFor(static function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('company_id')->constrained();
        });

        $this->assertStringContainsString('leadbridge_companies', $sql);
        $this->assertStringContainsString('FOREIGN KEY', $sql);
    }

    /**
     * `constrained()` on a `foreignUuid()` must still work — it is the same
     * mechanism `foreignId()` uses, and a helper that emitted a column without
     * registering it as a foreign key would produce a constraint that never
     * materialises.
     */
    public function test_foreign_uuid_registers_the_column_as_a_foreign_key(): void
    {
        $sql = $this->createSqlFor(static function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
        });

        $this->assertStringContainsString('ON DELETE CASCADE', $sql);
    }

    // -------------------------------------------------------- secondary types

    public function test_medium_text_compiles(): void
    {
        $this->assertColumnDefinition(
            'mediumtext NOT NULL',
            static fn (Blueprint $table) => $table->mediumText('body'),
            'body'
        );
    }

    public function test_tiny_text_compiles(): void
    {
        $this->assertColumnDefinition(
            'tinytext NOT NULL',
            static fn (Blueprint $table) => $table->tinyText('excerpt'),
            'excerpt'
        );
    }

    /**
     * A text-family column is refused as an index target.
     *
     * MySQL will not index one without a prefix length, so emitting the `KEY`
     * would produce a `CREATE TABLE` the server rejects — with a message about
     * key length that does not name the column. The refusal here says which one.
     */
    public function test_medium_text_refuses_an_index(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/mediumtext column without a prefix length/');

        Schema::create('notes', static function (Blueprint $table): void {
            $table->id();
            $table->mediumText('body')->index();
        });
    }

    public function test_float_and_double_compile(): void
    {
        $this->assertColumnDefinition(
            'float NOT NULL',
            static fn (Blueprint $table) => $table->float('lat'),
            'lat'
        );

        $this->assertColumnDefinition(
            'double NOT NULL',
            static fn (Blueprint $table) => $table->double('lng'),
            'lng'
        );
    }

    public function test_year_compiles_to_year_4(): void
    {
        $this->assertColumnDefinition(
            'year(4) NOT NULL',
            static fn (Blueprint $table) => $table->year('founded'),
            'founded'
        );
    }

    public function test_time_compiles(): void
    {
        $this->assertColumnDefinition(
            'time NOT NULL',
            static fn (Blueprint $table) => $table->time('starts_at'),
            'starts_at'
        );
    }

    public function test_binary_compiles_to_blob(): void
    {
        $this->assertColumnDefinition(
            'blob NOT NULL',
            static fn (Blueprint $table) => $table->binary('checksum'),
            'checksum'
        );
    }

    /**
     * MySQL refuses `DEFAULT` on a blob column, for the same reason it refuses
     * one on `json`: there is no literal syntax for the value.
     */
    public function test_binary_refuses_a_default(): void
    {
        $this->expectException(SchemaException::class);

        Schema::create('notes', static function (Blueprint $table): void {
            $table->id();
            $table->binary('checksum')->default('abc');
        });
    }

    /**
     * 45 is the longest textual IPv6 form, so the column fits either family.
     */
    public function test_ip_address_compiles_to_varchar_45(): void
    {
        $this->assertColumnDefinition(
            'varchar(45) NOT NULL',
            static fn (Blueprint $table) => $table->ipAddress('created_ip'),
            'created_ip'
        );
    }

    public function test_mac_address_compiles_to_varchar_17(): void
    {
        $this->assertColumnDefinition(
            'varchar(17) NOT NULL',
            static fn (Blueprint $table) => $table->macAddress('device_mac'),
            'device_mac'
        );
    }

    /**
     * The `noDefault` refusal must not leak across columns.
     *
     * `json()` records the column as default-less; if that record were keyed
     * wrongly — by position, or by the table — a later `string()->default()` on
     * the *same* migration would be refused for a reason that does not apply to
     * it. Cheaper to assert than to debug.
     */
    public function test_a_no_default_type_does_not_block_a_later_column(): void
    {
        $sql = $this->createSqlFor(static function (Blueprint $table): void {
            $table->id();
            $table->json('meta');
            $table->string('title')->default('untitled');
        });

        $this->assertStringContainsString("`title` varchar(191) NOT NULL DEFAULT 'untitled'", $sql);
    }

    /**
     * The compiled `CREATE TABLE` for a blueprint built by the given callback.
     *
     * @param callable(Blueprint): mixed $define
     */
    private function createSqlFor(callable $define): string
    {
        $blueprint = new Blueprint(
            'notes',
            creating: true,
            resolveTable: static fn (string $t): string => 'leadbridge_' . $t
        );

        $define($blueprint);

        return $blueprint->toCreateSql('leadbridge_notes');
    }

    /**
     * Assert the definition the builder compiles for one column.
     *
     * Asserted against the compiled `CREATE TABLE` rather than read back through
     * `SHOW COLUMNS`, because {@see FakeWpdb} does not parse a `CREATE` into
     * column metadata — a documented limit of the fake, not an oversight — and
     * inventing metadata here would test the fake rather than the compiler.
     *
     * The column is pulled out by name from the definition list rather than
     * matched as a substring, so an assertion cannot accidentally pass because
     * the expected fragment appears in some *other* column's definition.
     *
     * @param callable(Blueprint): mixed $define
     * @param string $column The column whose definition is being asserted.
     */
    private function assertColumnDefinition(string $expected, callable $define, string $column): void
    {
        $sql = $this->createSqlFor($define);

        $this->assertSame(1, preg_match('/^\s*`' . preg_quote($column, '/') . '`\s+(.+?),?$/m', $sql, $m),
            "The compiled CREATE must define a column [$column]. Got:\n" . $sql);

        $this->assertSame($expected, rtrim(trim($m[1]), ','));
    }
}
