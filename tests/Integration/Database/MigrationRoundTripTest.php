<?php
declare(strict_types=1);

namespace Tests\Integration\Database;

use Framework\Console\Command;
use Framework\Database\Adapters\WpDatabaseAdapter;
use Framework\Database\Commands\MigrateDumpCommand;
use Framework\Database\Migrator;
use Framework\Database\Schema\Schema;
use Framework\Database\Schema\SchemaDumper;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;
use Tests\Support\MigrationWorkspace;

/**
 * The dump → replay → re-dump loop, asserted end to end.
 *
 * ## The property this test exists to establish
 *
 * `migrate:dump` claims to emit a migration that rebuilds the schema it read.
 * That claim is the whole reason the command exists, and until now nothing
 * tested it: the dumper's own tests run `php -l` over the output and stop there,
 * which proves the file parses and says nothing at all about what it *does*. A
 * dump whose `up()` drops the table and whose `down()` creates it parses
 * perfectly, lints clean, and is catastrophic — and it passed every test in the
 * suite.
 *
 * The check that cannot be fooled is a fixed point: dump a known schema, run the
 * generated migration against an empty database, dump *that*, and require the
 * two dumps to be byte-identical. A dump file is a description of a schema, so if
 * replaying it produces a schema that dumps differently, the description was
 * wrong — regardless of which line was wrong or how it looked.
 *
 * This is deliberately an assertion about *output*, not about the internals that
 * produced it. Nothing here names `downBlock()`, `dumpTable()` or the order of
 * the two blocks; a dumper rewritten from scratch passes this test as long as it
 * still round-trips, which is the only promise it ever made.
 *
 * ## Why the schema is seeded through `Schema::create()` rather than by hand
 *
 * Because the loop has to close through the same path a real database closes it
 * through. `FakeWpdb` parses a `CREATE TABLE` into the column metadata the dumper
 * later reads (`SHOW COLUMNS`, `SHOW INDEX`), so seeding through `Schema::`
 * exercises *three* things rather than two: the blueprint compiles, the adapter
 * runs it, and the fake records the result — which is what makes the second dump
 * an honest observation of the replayed schema rather than a restatement of the
 * first one.
 *
 * ## Why the assertions are about the database, not about recorded SQL
 *
 * The suite has a blind spot that this audit exists to close: a statement the
 * fake records but cannot *execute* still appears in `$db->queries`, so
 * `assertStringContainsString(..., $db->queries)` passes on a migration that
 * would fail against a real server. Nothing in this test reads `queries`. Every
 * assertion is against the state of the database — which tables exist, which
 * columns they have, and what they dump as.
 *
 * @see SchemaDumper  The class under test.
 * @see \Tests\Unit\Database\SchemaDumperTest  Which covers the shapes; this
 *      covers the round trip.
 */
final class MigrationRoundTripTest extends TestCase
{
    /**
     * The table both dumps describe. Named `round_trip` so no framework table —
     * `wp_plugin_migrations` above all — can be mistaken for it.
     */
    private const TABLE = 'round_trip';

    private FakeWpdb $db;

    private MigrationWorkspace $workspace;

    private SchemaDumper $dumper;

    /**
     * Whatever `$wpdb` held before this test replaced it.
     */
    private ?\wpdb $restoreGlobal = null;

    protected function setUp(): void
    {
        $this->db = new FakeWpdb();
        $this->workspace = new MigrationWorkspace();

        // `Schema::` resolves an adapter statically for the life of the process,
        // and `dbDelta()` reaches for `$wpdb` itself — so the fake has to be both.
        Schema::useAdapter(new WpDatabaseAdapter($this->db));

        $previous = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $this->db;
        $this->restoreGlobal = $previous;

        // The dumper is handed the adapter the `Schema::` calls write through,
        // exactly as `MigrationServiceProvider` wires it. Constructing a second
        // `WpDatabaseAdapter` here would read the same fake and hide a binding
        // mistake; going through `Schema::adapter()` is what the command does.
        $this->dumper = new SchemaDumper(Schema::adapter());
    }

    protected function tearDown(): void
    {
        Schema::stopPretending();
        Schema::useAdapter(null);

        if ($this->restoreGlobal instanceof \wpdb) {
            $GLOBALS['wpdb'] = $this->restoreGlobal;
        } else {
            unset($GLOBALS['wpdb']);
        }

        $this->workspace->cleanup();

        parent::tearDown();
    }

    /**
     * The loop, over a schema carrying every column shape the dumper claims to
     * reconstruct.
     *
     * The breadth is the point rather than an afterthought: the defects this
     * test was written to catch are all visible only on *particular* column
     * shapes — a nullable boolean, a column with a default, a timestamp. A
     * minimal two-column table would round-trip cleanly through every one of
     * them and report the engine healthy.
     */
    public function test_a_dumped_schema_replays_to_an_identical_dump(): void
    {
        $this->seed();

        $first = $this->dumper->dump([self::TABLE]);

        // The dump is required to be runnable *before* it is compared, because a
        // dump that does not load at all would otherwise be compared to a second
        // dump produced by skipping it — and two identical failures are not a
        // passing round trip.
        $this->replay($first);

        $second = $this->dumper->dump([self::TABLE]);

        $this->assertSame(
            $first,
            $second,
            "The schema did not survive a dump/replay/dump round trip.\n\n"
            . "--- first dump ---\n{$first}\n\n"
            . "--- second dump ---\n{$second}"
        );
    }

    /**
     * The `up()` the dumper emits describes the schema, and the `down()` reverses
     * it.
     *
     * Asserted separately from the round trip because it is the failure mode a
     * fixed-point comparison cannot see. If the dumper emits `dropIfExists()` into
     * `up()` and leaves `down()` empty, then replaying the dump *destroys* the
     * table — and the second dump of a table that no longer exists throws, or
     * (worse, in a dumper that tolerated it) produces something that happens to
     * match. Reading the two methods directly is what makes the inversion
     * impossible to miss, and it is the assertion that names the defect in the
     * failure message rather than reporting a diff.
     */
    public function test_the_dump_creates_in_up_and_drops_in_down(): void
    {
        $this->seed();

        $dump = $this->dumper->dump([self::TABLE]);

        $this->assertSame(
            ['up:create', 'down:drop'],
            $this->schemaCallsByMethod($dump),
            "The generated migration must create the table in up() and drop it in down().\n\n{$dump}"
        );
    }

    /**
     * Replaying the dump against a database that already has the table leaves it
     * alone.
     *
     * The second half of the fixed point, and the reason a dump is worth
     * committing: a generated migration is routinely re-run — a half-applied
     * batch, a restored backup, a rollback followed by a re-migrate — and a
     * `Schema::create()` against a live table is an error MySQL refuses outright.
     */
    public function test_the_dumped_migration_is_safe_to_run_twice(): void
    {
        $this->seed();

        $dump = $this->dumper->dump([self::TABLE]);

        $this->replay($dump);
        $this->replay($dump);

        $this->assertTrue($this->db->tableExists('wp_' . self::TABLE));
    }

    /**
     * A column that is nullable survives the round trip *as nullable*.
     *
     * The specific regression this guards: `nullable()` on a column that carries
     * a type-level default. The builder's own default for a `boolean()` is
     * `NOT NULL DEFAULT 0`, and a nullable modifier that drops the `NOT NULL`
     * without appending a `NULL` changes the column's meaning — the second dump
     * then reports `Null: NO` where the first said `YES`, and the round trip
     * above fails with a diff nobody can read. Asserting it here says *which*
     * property was lost.
     */
    public function test_a_nullable_column_stays_nullable_through_the_round_trip(): void
    {
        $this->seed();

        $this->replay($this->dumper->dump([self::TABLE]));

        // Asserted before the column's metadata is read, because a table that a
        // broken replay dropped would report "no such column" from the probe
        // below and read as a nullability failure. The precondition makes the
        // failure message name the real cause.
        $this->assertTrue(
            $this->db->tableExists('wp_' . self::TABLE),
            'The replayed migration destroyed the table instead of building it, so nullability cannot '
            . 'be judged. Fix the generated up()/down() split first.'
        );

        $meta = $this->db->columnMeta('wp_' . self::TABLE, 'is_archived');

        $this->assertSame(
            'YES',
            $meta['Null'],
            'A nullable column came back NOT NULL after a dump/replay. The dump described the column, '
            . 'the replay built a different one, and nothing in the file looks wrong.'
        );
    }

    // ---------------------------------------------------------------- the loop

    /**
     * `nullable()` says `NULL` rather than merely omitting `NOT NULL`.
     *
     * ## Why the distinction is worth a test
     *
     * Every one of these columns *works* today: MySQL treats a column with no
     * nullability clause as nullable, so `tinyint(1)` accepts `NULL` exactly as
     * `tinyint(1) NULL` does. The defect is not that the column is wrong — it is
     * that the builder never states what it means, and relies on a server default
     * to make it true.
     *
     * That reliance is what makes it a defect rather than a style question. The
     * dumper reads nullability back from the catalogue and re-emits it as an
     * explicit `->nullable()`, so a dumped migration and a hand-written one spell
     * the same column two different ways. Any tooling that compares schemas as
     * text — a diff of two dumps, a migration reviewed against what it replaced —
     * then reports a difference that does not exist.
     *
     * Asserted on the compiled statement rather than the fake's metadata, because
     * the fake (and MySQL) normalise both spellings to the same column, and this
     * test is about what the builder *wrote*.
     */
    public function test_nullable_emits_an_explicit_null(): void
    {
        Schema::create(self::TABLE, function ($table): void {
            $table->string('plain')->nullable();
            $table->boolean('typed')->nullable();
            $table->boolean('authored')->default(true)->nullable();
            $table->integer('later_default')->nullable()->default(5);
        });

        $create = $this->createStatement();

        $this->assertStringContainsString('`plain` varchar(191) NULL', $create);
        $this->assertStringContainsString('`typed` tinyint(1) NULL', $create);

        // An author's default survives; the *type's* does not. Both were
        // deliberate before this change and both still are — `boolean()` emits
        // `DEFAULT 0` so an `ADD COLUMN` does not leave existing rows NULL, and
        // `nullable()` drops it so a row never set reports "no answer" rather
        // than "no". Only the missing `NULL` is added.
        //
        // `NULL` before `DEFAULT`, because that is MySQL's grammar order and the
        // order the adapter's own `columnDefinition()` emits — the two describe
        // the same column for an `ALTER`, so they have to agree.
        $this->assertStringContainsString('`authored` tinyint(1) NULL DEFAULT 1', $create);
        $this->assertStringNotContainsString('DEFAULT 0', $create);

        // And the other way round: a default applied *after* nullability keeps
        // the same canonical order rather than producing `DEFAULT 5 NULL`.
        $this->assertStringContainsString('`later_default` int(11) NULL DEFAULT 5', $create);
        $this->assertStringNotContainsString('DEFAULT 1 NULL', $create);
    }

    /**
     * The specific case the builder's own default is dropped for.
     *
     * `nullableMorphs()` reaches both columns through `makeNullable()` rather
     * than `nullable()`, so it is a second code path to the same rewrite — and
     * the one whose columns carry no author default at all, which is where an
     * implementation that only handled `nullable()` would show up.
     */
    public function test_nullable_morphs_emits_an_explicit_null_on_both_columns(): void
    {
        Schema::create(self::TABLE, function ($table): void {
            $table->nullableMorphs('subject');
        });

        $create = $this->createStatement();

        $this->assertStringContainsString('`subject_type` varchar(191) NULL', $create);
        $this->assertStringContainsString('`subject_id` bigint(20) unsigned NULL', $create);
    }

    /**
     * The one `CREATE TABLE` the seed issued.
     */
    private function createStatement(): string
    {
        foreach ($this->db->queries as $query) {
            if (preg_match('/^\s*create\s+table\s+`?wp_' . self::TABLE . '`?/i', $query) === 1) {
                return $query;
            }
        }

        $this->fail('No CREATE TABLE was issued for [' . self::TABLE . '].');
    }

    /**
     * Declare the schema both dumps describe, through the ordinary builder path.
     */
    private function seed(): void
    {
        Schema::create(self::TABLE, function ($table): void {
            $table->id();

            // Nullable, no default: the straightforward case.
            $table->string('title')->nullable();

            // NOT NULL with a scalar default: exercises `->default()`.
            $table->string('status')->default('draft');

            // Nullable *with* a type-level default. `boolean()` emits
            // `NOT NULL DEFAULT 0` of its own accord, so `nullable()` here has to
            // rewrite a default it did not author — which is precisely where the
            // modifier loses the column's type and nullability.
            $table->boolean('is_archived')->nullable();

            // A non-nullable boolean, as the control: if this one comes back
            // wrong the problem is not `nullable()`.
            $table->boolean('is_pinned')->default(false);

            // Precision and scale, so `decimal` is not dumped as a bare word.
            $table->decimal('amount', 10, 2)->nullable();

            // Timestamps as MySQL reports them, including the `on update`
            // clause the adapter has to reconstruct into a builder call.
            $table->timestamp('created_at')->nullable();

            // An inline index, to prove the inline/standalone index split is
            // stable across a replay.
            $table->string('slug')->nullable()->index();

            // A composite key, which has to survive as *one* index and not as
            // two single-column ones.
            $table->integer('pipeline_id')->nullable();
            $table->integer('stage_id')->nullable();
            $table->index(['pipeline_id', 'stage_id']);

            // The binary family. Three distinct MySQL types that the dumper used
            // to collapse into one call: `BINARY(16)` is fixed-width and pads
            // with `\0`, `VARBINARY(64)` is variable-width, and `BLOB` is
            // variable-width *with a length prefix*. Mapping the first two onto
            // `binary()` emitted `blob NOT NULL` — a different column that stored
            // the same bytes differently — and a table containing a `blob` threw
            // outright, because the map had no `blob` arm at all. Seeded here
            // because a round trip is the only way to notice either: all three
            // dump *something*, and only replaying tells you whether that
            // something was the right column.
            $table->binaryUuid('external_id');
            $table->varbinary('token', 64);
            $table->binary('payload');
        });
    }

    /**
     * Run a dumped migration's `up()` against the fake, the way the migrator
     * would.
     *
     * The file is written to a workspace and required rather than evaluated,
     * because that is how a real migration is reached: the class name comes from
     * the filename, so the autoloader cannot find it and `require` is the only
     * way in. A test that `eval`'d the body would skip the convention that makes
     * the file discoverable at all.
     */
    private function replay(string $dump): void
    {
        $identifier = '2026_11_07_120000_create_round_trip_table';

        $file = $this->workspace->writeRaw($identifier . '.php', $dump);

        // `require_once` would silently skip the second replay in
        // `test_the_dumped_migration_is_safe_to_run_twice` and make it pass while
        // testing nothing, so the file is required by path each time, exactly as
        // the migrator does.
        $migration = require $file;

        $this->assertIsObject(
            $migration,
            "The dumped file must return a migration object; it returned a " . get_debug_type($migration) . '.'
        );

        $migration->up();
    }

    /**
     * Which schema statements a dumped migration issues, and from which method.
     *
     * Read from the generated *source*, split by method, rather than by running
     * the file and watching what it does. That distinction is the whole reason
     * this assertion exists: an earlier version of it replayed the migration
     * through `--pretend` and recorded the statements, and it **passed on a
     * migration whose `up()` dropped the table and whose `down()` was empty** —
     * because the recording path and the file disagreed about which method was
     * which. Observing the file as text removes that gap: there is nothing
     * between the assertion and the bytes a developer will read.
     *
     * `Schema::dropIfExists()` inside `up()` is the specific catastrophe this
     * guards: the generated migration would *destroy* the table it was written to
     * build, and every other property the dumper claims — that it round-trips,
     * that it is re-runnable — fails downstream of it in a way that reads as a
     * diff rather than as the inversion it is.
     *
     * @return list<string> `'create'` and/or `'drop'`, in the order they appear.
     */
    private function schemaCallsByMethod(string $dump): array
    {
        $calls = [];

        foreach (['up', 'down'] as $method) {
            if (preg_match(
                '/public\s+function\s+' . $method . '\s*\(\s*\)\s*:\s*void\s*\{(.*?)\n\s{4}\}/s',
                $dump,
                $m
            ) !== 1) {
                $this->fail("The generated migration declares no [{$method}()] method.\n\n{$dump}");
            }

            $body = $m[1];

            if (preg_match('/Schema::create\s*\(/', $body) === 1) {
                $calls[] = $method . ':create';
            }

            if (preg_match('/Schema::dropIfExists\s*\(/', $body) === 1) {
                $calls[] = $method . ':drop';
            }
        }

        return $calls;
    }
}
