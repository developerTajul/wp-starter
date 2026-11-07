<?php
declare(strict_types=1);

namespace Tests\Unit\Database;

use Framework\Database\Adapters\WpDatabaseAdapter;
use Framework\Database\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;
use Tests\Support\MigrationWorkspace;
use Tests\Support\WpStub;

/**
 * The schema dumper: reading a live database back into migration source.
 *
 * ## What this is for
 *
 * The one thing a migration history cannot tell you is what the database
 * *actually* looks like — a schema edited by hand, a migration that half-applied,
 * a table an earlier developer added through phpMyAdmin. The dumper answers that
 * question by reading the catalogue and emitting the `Schema::create()` calls
 * that would rebuild what is there.
 *
 * ## What it deliberately is not
 *
 * It is not a migration-history reconstructor: it emits one `create` per table
 * and has no opinion about which migration produced what. It is not a data
 * dumper. And it does not claim to be lossless — see the round-trip tests, which
 * pin exactly which details survive and which cannot, because a dump that looked
 * authoritative while silently dropping a foreign key's delete rule would be
 * worse than no dump at all.
 */
final class SchemaDumperTest extends TestCase
{
    private MigrationWorkspace $workspace;
    private FakeWpdb $db;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->workspace = new MigrationWorkspace();
        $this->db = new FakeWpdb();

        Schema::useAdapter(new WpDatabaseAdapter($this->db));
    }

    protected function tearDown(): void
    {
        Schema::stopPretending();
        Schema::useAdapter(null);

        $this->workspace->cleanup();
    }

    /**
     * The dumper emits a `Schema::create()` per table.
     *
     * The shape of the output is the contract: a file a developer can drop into
     * `database/migrations/` and run. Asserted on the calls rather than on the
     * whole file text, because the file also carries a timestamp-derived class
     * name and a header comment, and a test that pinned those would fail on every
     * unrelated change to the formatting.
     */
    public function test_it_emits_a_create_call_per_table(): void
    {
        $this->db->createTable('wp_notes', ['id', 'title']);
        $this->db->setColumnMeta('wp_notes', 'id', ['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Extra' => 'auto_increment']);
        $this->db->setColumnMeta('wp_notes', 'title', ['Type' => 'varchar(191)', 'Null' => 'NO']);

        $sql = $this->dumper()->dump(['wp_notes']);

        $this->assertStringContainsString("Schema::create('notes'", $sql);
        $this->assertStringContainsString("\$table->id()", $sql);
        $this->assertStringContainsString("\$table->string('title'", $sql);
    }

    /**
     * The table name in the emitted call is the *unprefixed* one.
     *
     * The database stores `wp_notes` and the schema language says `notes`;
     * `Schema::create()` prefixes it back. A dumper that emitted the stored name
     * would produce a migration creating `wp_wp_notes` — and it would look right,
     * which is what makes this worth an explicit test rather than an assumption.
     */
    public function test_it_strips_the_table_prefix_from_the_emitted_name(): void
    {
        $this->db->createTable('wp_notes', ['id']);

        $sql = $this->dumper()->dump(['wp_notes']);

        $this->assertStringNotContainsString("Schema::create('wp_notes'", $sql);
        $this->assertStringNotContainsString('wp_wp_notes', $sql);
    }

    /**
     * A table the plugin does not own is skipped unless it is asked for.
     *
     * `dump()` takes an explicit list. Without one, dumping "every table" on a
     * real WordPress install emits a `create` for `wp_posts`, `wp_options` and
     * forty others — and a developer who then runs that migration has written a
     * second, conflicting definition of WordPress's own schema. So the caller
     * names what it wants, and there is no "all tables" default to reach for by
     * accident.
     */
    public function test_it_dumps_only_the_tables_it_is_given(): void
    {
        $this->db->createTable('wp_notes', ['id']);
        $this->db->createTable('wp_unrelated', ['id']);

        $sql = $this->dumper()->dump(['wp_notes']);

        $this->assertStringContainsString("Schema::create('notes'", $sql);
        $this->assertStringNotContainsString('unrelated', $sql);
    }

    /**
     * A column's nullability survives the round trip.
     *
     * The single most consequential attribute to get wrong: a column dumped as
     * NOT NULL that is actually nullable will reject inserts on the restored
     * database, and nothing in the dump's own output would show it.
     */
    public function test_a_nullable_column_is_dumped_as_nullable(): void
    {
        $this->db->createTable('wp_notes', ['id', 'subtitle']);
        $this->db->setColumnMeta('wp_notes', 'subtitle', ['Type' => 'varchar(191)', 'Null' => 'YES']);

        $sql = $this->dumper()->dump(['wp_notes']);

        $this->assertMatchesRegularExpression(
            "/\\\$table->string\('subtitle'[^;]*\)->nullable\(\);/",
            $sql,
            'A nullable column must be dumped with ->nullable(). Got: ' . $sql
        );
    }

    /**
     * A column's default survives the round trip.
     *
     * Only for scalar defaults. A `CURRENT_TIMESTAMP` default is an expression,
     * not a value, and the blueprint spells it `useCurrent()` rather than
     * `default()` — handled separately below and deliberately not guessed at here.
     */
    public function test_a_scalar_default_is_dumped(): void
    {
        $this->db->createTable('wp_notes', ['id', 'status']);
        $this->db->setColumnMeta('wp_notes', 'status', [
            'Type' => 'varchar(20)',
            'Null' => 'NO',
            'Default' => 'draft',
        ]);

        $sql = $this->dumper()->dump(['wp_notes']);

        $this->assertStringContainsString("->default('draft')", $sql);
    }

    /**
     * A `CURRENT_TIMESTAMP` default becomes `useCurrent()`, not `default(...)`.
     *
     * MySQL reports the default as the string `CURRENT_TIMESTAMP` and drops the
     * parentheses, so a dumper treating it as a literal would emit
     * `->default('CURRENT_TIMESTAMP')` — a column whose default is that word,
     * which is a valid MySQL default and quietly wrong. The blueprint has a
     * dedicated modifier for the expression, and this is what decides between
     * them.
     */
    public function test_a_current_timestamp_default_becomes_use_current(): void
    {
        $this->db->createTable('wp_notes', ['id', 'created_at']);
        $this->db->setColumnMeta('wp_notes', 'created_at', [
            'Type' => 'timestamp',
            'Null' => 'NO',
            'Default' => 'CURRENT_TIMESTAMP',
        ]);

        $sql = $this->dumper()->dump(['wp_notes']);

        $this->assertStringContainsString('->useCurrent()', $sql);
        $this->assertStringNotContainsString("default('CURRENT_TIMESTAMP')", $sql);
    }

    /**
     * An auto-increment primary key becomes `id()`.
     *
     * `id()` is not sugar for "a bigint" — it emits the auto-increment primary
     * key, and a dump that emitted a plain column would restore a table with no
     * primary key at all. The `auto_increment` marker is what identifies it, and
     * it is read from `Extra` rather than inferred from the name.
     */
    public function test_an_auto_increment_primary_key_becomes_id(): void
    {
        $this->db->createTable('wp_notes', ['id', 'title']);
        $this->db->setColumnMeta('wp_notes', 'id', [
            'Type' => 'bigint(20) unsigned',
            'Null' => 'NO',
            'Extra' => 'auto_increment',
        ]);
        $this->db->setColumnMeta('wp_notes', 'title', ['Type' => 'varchar(191)', 'Null' => 'NO']);

        $sql = $this->dumper()->dump(['wp_notes']);

        $this->assertStringContainsString('$table->id();', $sql);
        $this->assertStringNotContainsString("integer('id')", $sql);
    }

    /**
     * Indexes are dumped after the columns.
     *
     * A compiled `CREATE TABLE` cannot carry an index before the column it names
     * exists, so the ordering is not cosmetic — and it is also the order a
     * reader expects, which is the same order the blueprint emits.
     */
    public function test_a_plain_index_is_dumped(): void
    {
        $this->db->createTable('wp_notes', ['id', 'title']);
        $this->db->setColumnMeta('wp_notes', 'title', ['Type' => 'varchar(191)', 'Null' => 'NO']);
        $this->db->addIndex('wp_notes', 'title', unique: false);

        $sql = $this->dumper()->dump(['wp_notes']);

        $this->assertStringContainsString('->index()', $sql);
    }

    /**
     * A unique index is dumped as `unique()`, not `index()`.
     *
     * The two differ exactly in what they forbid, and a dump that flattened both
     * to `index()` would restore a table that accepts duplicate rows the original
     * rejected — a data-integrity loss that produces no error anywhere.
     */
    public function test_a_unique_index_is_dumped_as_unique(): void
    {
        $this->db->createTable('wp_notes', ['id', 'slug']);
        $this->db->setColumnMeta('wp_notes', 'slug', ['Type' => 'varchar(191)', 'Null' => 'NO']);
        $this->db->addIndex('wp_notes', 'slug', unique: true);

        $sql = $this->dumper()->dump(['wp_notes']);

        $this->assertStringContainsString('->unique()', $sql);
    }

    /**
     * A single-column index is attached to its column, not listed separately.
     *
     * Both spellings compile to the same `KEY` clause, so this is about which one
     * a reader gets. Inline is what a hand-written migration looks like and what
     * keeps the column's constraints next to the column; a dumper emitting a
     * block of `$table->index([...])` calls at the bottom of every table would be
     * technically equivalent and much harder to read.
     */
    public function test_a_single_column_index_is_attached_to_its_column(): void
    {
        $this->db->createTable('wp_notes', ['id', 'title']);
        $this->db->setColumnMeta('wp_notes', 'title', ['Type' => 'varchar(191)', 'Null' => 'NO']);
        $this->db->addIndex('wp_notes', 'title', unique: false);

        $sql = $this->dumper()->dump(['wp_notes']);

        $this->assertMatchesRegularExpression(
            "/\\\$table->string\('title'[^;]*->index\(\);/",
            $sql,
            'A single-column index belongs on the column. Got: ' . $sql
        );
    }

    /**
     * A composite index is emitted as its own statement.
     *
     * It cannot be attached to any one column, and emitting it twice — once per
     * participating column — would create two indexes where the database has one.
     */
    public function test_a_composite_index_is_emitted_separately(): void
    {
        $this->db->createTable('wp_notes', ['id', 'note_id', 'tag_id']);
        $this->db->setColumnMeta('wp_notes', 'note_id', ['Type' => 'bigint(20) unsigned', 'Null' => 'NO']);
        $this->db->setColumnMeta('wp_notes', 'tag_id', ['Type' => 'bigint(20) unsigned', 'Null' => 'NO']);
        $this->db->addIndex('wp_notes', 'note_id_tag_id', unique: false, columns: ['note_id', 'tag_id']);

        $sql = $this->dumper()->dump(['wp_notes']);

        $this->assertStringContainsString("\$table->index(['note_id', 'tag_id']);", $sql);
    }

    /**
     * The tracking table is refused, not silently omitted.
     *
     * It is the framework's own bookkeeping: a dump containing it would emit a
     * `down()` that drops the table recording what has been migrated — deleting
     * the history the dump exists to describe. It is also recreated automatically
     * by every entry point, so there is nothing to preserve.
     *
     * **Refused rather than skipped**, and that distinction is the point. A
     * dumper that quietly dropped a table it was explicitly asked for would
     * return a file that looks complete and is missing schema; a caller who
     * named it has a reason, and the reason is worth hearing. The refusal names
     * the table so the fix is obvious.
     */
    public function test_the_tracking_table_is_refused(): void
    {
        $this->db->createTable('wp_notes', ['id']);

        $this->expectException(\Framework\Database\Exceptions\MigrationException::class);
        $this->expectExceptionMessage('bookkeeping');

        $this->dumper()->dump(['wp_notes', 'wp_plugin_migrations']);
    }

    /**
     * The refusal holds for the prefixed spelling too.
     *
     * A caller passes application names, but a dump asked for "every table" on a
     * real install reaches the dumper with names as the database holds them —
     * `wp_plugin_migrations`. A guard comparing the bare name alone would pass
     * that one straight through, in exactly the call shape where it matters
     * most, so both spellings are pinned.
     */
    public function test_the_tracking_table_is_refused_under_its_prefixed_name(): void
    {
        $this->db->createTable('wp_notes', ['id']);

        $this->expectException(\Framework\Database\Exceptions\MigrationException::class);
        $this->expectExceptionMessage('bookkeeping');

        $this->dumper()->dump([$this->db->prefix . 'plugin_migrations']);
    }

    /**
     * A dropped/absent table is refused rather than emitted empty.
     *
     * Emitting `Schema::create('notes', function () {})` for a table that does
     * not exist would produce a migration that creates a table with no columns.
     * The distinction between "no such table" and "a table with nothing in it" —
     * which MySQL does not allow either — has to be made here.
     */
    public function test_an_unknown_table_is_refused(): void
    {
        $this->expectException(\Framework\Database\Exceptions\MigrationException::class);
        $this->expectExceptionMessage('absent');

        $this->dumper()->dump(['wp_absent']);
    }

    /**
     * The output is valid PHP.
     *
     * The lowest bar and the one worth holding: the dumper's product is a source
     * file, and a syntax error in it is discovered by the developer at
     * `migrate` time with a message about the migration, not about the dumper.
     */
    public function test_the_output_is_syntactically_valid_php(): void
    {
        $this->db->createTable('wp_notes', ['id', 'title']);
        $this->db->setColumnMeta('wp_notes', 'id', ['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Extra' => 'auto_increment']);
        $this->db->setColumnMeta('wp_notes', 'title', ['Type' => 'varchar(191)', 'Null' => 'YES']);

        $sql = $this->dumper()->dump(['wp_notes']);

        $file = $this->workspace->writeRaw('dump-check.php', $sql);

        $output = [];
        $status = 0;
        exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $status);

        $this->assertSame(0, $status, "The dumped source is not valid PHP:\n" . implode("\n", $output) . "\n\n" . $sql);
    }

    /**
     * Every column type the blueprint can emit is dumped back to a builder call.
     *
     * Asserted table-driven because the failure mode is a single missing case: a
     * type the dumper does not recognise would silently become a `string()`, and
     * only the type that happened to be missing from a hand-written test would
     * catch it.
     */
    public function test_common_column_types_round_trip(): void
    {
        $cases = [
            'bigint(20) unsigned' => "unsignedBigInteger('c')",
            'bigint(20)' => "bigInteger('c')",
            'int(11)' => "integer('c')",
            'int(10) unsigned' => "unsignedInteger('c')",
            'tinyint(1)' => "boolean('c')",
            'tinyint(3) unsigned' => "unsignedTinyInteger('c')",
            'tinyint(4)' => "tinyInteger('c')",
            'varchar(191)' => "string('c'",
            'varchar(50)' => "string('c', 50)",
            'text' => "text('c')",
            'mediumtext' => "mediumText('c')",
            'tinytext' => "tinyText('c')",
            'longtext' => "longText('c')",
            'datetime' => "dateTime('c')",
            'date' => "date('c')",
            'time' => "time('c')",
            'year' => "year('c')",
            'decimal(8,2)' => "decimal('c', 8, 2)",
            'float' => "float('c')",
            'double' => "double('c')",
            'json' => "json('c')",
            'char(2)' => "char('c', 2)",
        ];

        foreach ($cases as $type => $expected) {
            $db = new FakeWpdb();
            $db->createTable('wp_notes', ['id', 'c']);
            $db->setColumnMeta('wp_notes', 'c', ['Type' => $type, 'Null' => 'YES']);

            $dumper = new \Framework\Database\Schema\SchemaDumper(new WpDatabaseAdapter($db));
            $sql = $dumper->dump(['wp_notes']);

            $this->assertStringContainsString(
                '$table->' . $expected,
                $sql,
                "The type [{$type}] should dump to [\$table->{$expected}]. Got:\n" . $sql
            );
        }
    }

    /**
     * An unrecognised type is reported, not silently coerced.
     *
     * The alternative — falling back to `string()` — produces a dump that runs
     * cleanly and creates the wrong column, which is the worst available outcome:
     * no error, and a schema subtly unlike the one that was dumped.
     */
    public function test_an_unknown_column_type_is_reported(): void
    {
        $this->db->createTable('wp_notes', ['id', 'odd']);
        $this->db->setColumnMeta('wp_notes', 'odd', ['Type' => 'geometry', 'Null' => 'YES']);

        $this->expectException(\Framework\Database\Schema\Exceptions\SchemaException::class);
        $this->expectExceptionMessage('geometry');

        $this->dumper()->dump(['wp_notes']);
    }

    /**
     * A `blob` column dumps as `binary()`, which is what builds it.
     *
     * The asymmetry this pins: `Blueprint::binary()` emits `blob NOT NULL`, so a
     * column created through the builder reports its type as `blob`. The dumper's
     * map had `binary`/`varbinary` but no `blob`, so a table containing a column
     * *this framework itself created* hit the `default =>` arm and threw — the
     * dump refused to describe a schema it had written. Round-tripping a table
     * through `migrate:dump` therefore failed on any table with a `binary()`.
     */
    public function test_a_blob_column_dumps_as_binary(): void
    {
        $this->db->createTable('wp_notes', ['id', 'digest']);
        $this->db->setColumnMeta('wp_notes', 'digest', ['Type' => 'blob', 'Null' => 'NO']);

        $sql = $this->dumper()->dump(['wp_notes']);

        $this->assertStringContainsString("\$table->binary('digest')", $sql);
    }

    /**
     * A `binary(16)` column keeps its length, rather than becoming a `binary()`.
     *
     * `binary`/`varbinary` are *not* `blob`. MySQL's `BINARY(16)` is fixed-width
     * and pads with `\0`; `BLOB` is variable-width with a two-byte length prefix.
     * They are different storage and different comparison semantics, so mapping a
     * `binary(16)` — the shape of a UUID or an `INET6_ATON()` value — onto
     * `binary()`, which emits `blob NOT NULL`, produced a dump that runs cleanly
     * and creates a different column than the one it described. Silently wrong is
     * the outcome this dumper's `default =>` arm exists to avoid.
     */
    public function test_a_binary_column_keeps_its_length(): void
    {
        $this->db->createTable('wp_notes', ['id', 'uuid']);
        $this->db->setColumnMeta('wp_notes', 'uuid', ['Type' => 'binary(16)', 'Null' => 'NO']);

        $sql = $this->dumper()->dump(['wp_notes']);

        $this->assertStringContainsString("\$table->binaryUuid('uuid')", $sql);
        $this->assertStringNotContainsString("\$table->binary('uuid')", $sql);
    }

    public function test_a_varbinary_column_keeps_its_length(): void
    {
        $this->db->createTable('wp_notes', ['id', 'token']);
        $this->db->setColumnMeta('wp_notes', 'token', ['Type' => 'varbinary(64)', 'Null' => 'NO']);

        $sql = $this->dumper()->dump(['wp_notes']);

        $this->assertStringContainsString("\$table->varbinary('token', 64)", $sql);
    }

    // ----------------------------------------------------------------- helpers

    private function dumper(): \Framework\Database\Schema\SchemaDumper
    {
        return new \Framework\Database\Schema\SchemaDumper(new WpDatabaseAdapter($this->db));
    }
}
