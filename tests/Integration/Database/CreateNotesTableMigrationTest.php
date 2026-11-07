<?php
declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Repositories\WpNoteRepository;
use Framework\Database\Adapters\WpDatabaseAdapter;
use Framework\Database\MigrationFile;
use Framework\Database\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;

/**
 * The notes-table migration, run against a stand-in database.
 *
 * ## Why this test exists at all
 *
 * A migration is the one kind of code that only ever runs against a real
 * database, once, at deploy time — which is exactly the code most likely to be
 * wrong and least likely to be noticed. It is not covered by any of the tests
 * around it: the repositories are tested against tables *the test creates*, so
 * nothing anywhere asserts that the schema the migration writes matches the
 * columns the repositories name.
 *
 * This closes that gap. It runs the real migration file, found the way the
 * migrator finds it, and then asserts against the result.
 *
 * ## Why the *filename* is part of the test
 *
 * The migrator derives the class name from the filename rather than autoloading
 * it, so a file whose name and class disagree is a migration that silently never
 * runs — the discovery returns null and the migration is not merely skipped, it
 * is invisible. Nothing else in the suite would catch that, so the convention is
 * asserted here rather than assumed.
 *
 * ## Why the tables are split across two test classes
 *
 * Because they are split across two migrations. The previous single test ran one
 * `up()` and asserted both tables, which is a shape the migrator no longer has:
 * each migration now owns exactly one table, and a test that ran them together
 * would hide the property the split exists to establish — that one table being
 * absent is a state the migrator can record and resume from.
 *
 * The audit table is covered by {@see CreateNoteAuditTableMigrationTest}.
 */
final class CreateNotesTableMigrationTest extends TestCase
{
    private const PATH = __DIR__ . '/../../../database/migrations/2026_11_05_120000_create_notes_table.php';

    private FakeWpdb $db;

    /**
     * Whatever `$wpdb` held before this test replaced it.
     */
    private ?\wpdb $restoreGlobal = null;

    /**
     * The migration reaches the database through `Schema::`, which resolves an
     * adapter rather than taking a handle. That is the seam the other ten
     * migrations use, and `Schema::useAdapter()` is how a test substitutes one —
     * see {@see \Framework\Database\Schema\Schema}.
     */
    protected function setUp(): void
    {
        $this->db = new FakeWpdb();

        Schema::useAdapter(new WpDatabaseAdapter($this->db));

        // The migration reaches the database through `Schema::`, which resolves
        // an adapter rather than taking a handle — so the fake has to be the
        // global one for the `dbDelta()` stub to execute against it. That is also
        // how the real thing works: dbDelta reaches for `$wpdb` itself.
        $previous = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $this->db;

        $this->restoreGlobal = $previous;
    }

    /**
     * `Schema::` holds its adapter statically for the life of the process, so it
     * has to be handed back — otherwise the next test class to call `Schema::`
     * runs against this one's fake. The `$wpdb` global is restored for the same
     * reason, and because the suite's other tests install their own.
     */
    protected function tearDown(): void
    {
        Schema::useAdapter(null);

        if ($this->restoreGlobal instanceof \wpdb) {
            $GLOBALS['wpdb'] = $this->restoreGlobal;
        } else {
            unset($GLOBALS['wpdb']);
        }

        parent::tearDown();
    }

    /**
     * The filename the migrator discovers, and the class it derives.
     */
    public function test_the_file_follows_the_naming_convention(): void
    {
        $file = MigrationFile::fromPath(self::PATH);

        $this->assertNotNull(
            $file,
            'A migration whose filename does not match the convention is never discovered — it is '
            . 'not skipped with a warning, it is invisible.'
        );
        $this->assertSame('CreateNotesTable', $file->class);
    }

    public function test_it_creates_the_notes_table(): void
    {
        $this->migration()->up();

        $this->assertTrue($this->db->tableExists('wp_notes'));
    }

    /**
     * The audit table belongs to the *next* migration, and creating it here is
     * the mistake this asserts against — a migration that reaches across leaves
     * its neighbour recorded as applied with no table behind it.
     */
    public function test_it_creates_no_table_other_than_its_own(): void
    {
        $this->migration()->up();

        $this->assertFalse(
            $this->db->tableExists('wp_note_audit'),
            'The audit table is a separate migration; creating it here would break the one-table-per-'
            . 'migration rule this split exists to establish.'
        );
    }

    /**
     * The columns the repository actually names exist.
     *
     * Asserted by *using* the repository rather than by inspecting the schema
     * string: a column the migration omitted and the repository named would
     * otherwise look fine in both files and fail at runtime.
     */
    public function test_the_notes_table_satisfies_the_note_repository(): void
    {
        $this->migration()->up();

        $repository = new WpNoteRepository($this->db);

        // A write and a read, so every column in the INSERT and every column the
        // hydration path reads is exercised against the real schema.
        $stored = $repository->save(
            \Core\Note\Domain\NoteEntity::create('Title', 'Body', 7)->value()
        );

        $this->assertNotNull($stored->id);
        $this->assertSame('Title', $repository->find((int) $stored->id)?->title);
    }

    /**
     * **The replay case, and the reason this migration was rewritten.**
     *
     * A tracking table can be dropped, restored from an older backup, or lost in
     * a partial restore, and any of those replays every `up()` against a schema
     * that is already there. This migration used to issue a plain `CREATE TABLE`
     * — which cannot survive that, and fails with "Table ... already exists",
     * leaving the schema ahead of the record and the run stuck.
     *
     * Going through `Schema::create()` routes it to `dbDelta()`, which diffs a
     * live table instead of re-creating it, so the replay is a no-op.
     */
    public function test_up_succeeds_when_run_a_second_time(): void
    {
        $migration = $this->migration();

        $migration->up();
        $migration->up();

        $this->assertTrue($this->db->tableExists('wp_notes'));
    }

    /**
     * And the replay must not *empty* the table it was already holding.
     *
     * A `CREATE TABLE IF NOT EXISTS` would pass the test above and fail this one,
     * which is the difference that matters: the replay is supposed to leave data
     * alone, not merely avoid an error.
     */
    public function test_a_replay_preserves_the_rows_already_stored(): void
    {
        $this->migration()->up();

        $repository = new WpNoteRepository($this->db);
        $stored = $repository->save(
            \Core\Note\Domain\NoteEntity::create('Kept', 'Body', 7)->value()
        );

        $this->migration()->up();

        $this->assertSame('Kept', $repository->find((int) $stored->id)?->title);
    }

    /**
     * `down()` removes exactly what `up()` created.
     */
    public function test_it_reverses_cleanly(): void
    {
        $migration = $this->migration();

        $migration->up();
        $migration->down();

        $this->assertFalse($this->db->tableExists('wp_notes'));
    }

    /**
     * A rollback of a migration that never ran must not explode.
     *
     * This is the state a partly-applied batch leaves behind, and it is exactly
     * when someone reaches for `migrate:rollback` — so `down()` is written with
     * `IF EXISTS` and that has to keep being true. It matters more now than it
     * did: with one table per migration, the audit table being absent while this
     * one is present is a state the split makes *reachable*, and rolling back
     * has to survive it.
     */
    public function test_down_is_safe_when_the_table_does_not_exist(): void
    {
        $this->migration()->down();

        $this->assertFalse($this->db->tableExists('wp_notes'));
    }

    /**
     * The class the filename resolves to, instantiated with a test handle.
     *
     * ## Why the file is `require`d here
     *
     * Because that is what the migrator does. A migration is *not* PSR-4
     * autoloadable — its class name is derived from its filename, so the two do
     * not have to agree and the autoloader has no way to find it. The migrator
     * includes the file by path and then checks the class exists.
     *
     * A test that relied on the autoloader would need `Database\` mapped in
     * `composer.json`, which the real discovery does not — so it would pass here
     * and the migration would fail to load in a WP-CLI run. Requiring the path is
     * what keeps the test honest about how the class is actually reached.
     */
    private function migration(): object
    {
        $file = MigrationFile::fromPath(self::PATH);

        $this->assertNotNull($file, 'The migration file must follow the naming convention.');

        require_once self::PATH;

        $fqcn = 'Database\\Migrations\\' . $file->class;

        $this->assertTrue(
            class_exists($fqcn),
            sprintf('The filename [%s] must declare a class named [%s].', basename(self::PATH), $file->class)
        );

        return new $fqcn($this->db);
    }
}
