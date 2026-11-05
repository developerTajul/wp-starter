<?php
declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Repositories\WpNoteAuditRepository;
use App\Repositories\WpNoteRepository;
use Framework\Database\MigrationFile;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;

/**
 * The notes migration, run against a stand-in database.
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
 */
final class CreateNotesTablesMigrationTest extends TestCase
{
    private const PATH = __DIR__ . '/../../../database/migrations/2026_11_05_120000_create_notes_tables.php';

    private FakeWpdb $db;

    protected function setUp(): void
    {
        $this->db = new FakeWpdb();
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
        $this->assertSame('CreateNotesTables', $file->class);
    }

    public function test_it_creates_both_tables(): void
    {
        $this->migration()->up();

        $this->assertTrue($this->db->tableExists('wp_notes'));
        $this->assertTrue(
            $this->db->tableExists('wp_note_audit'),
            'The audit table is half the migration: a note and its history are written together.'
        );
    }

    /**
     * The columns the repositories actually name exist.
     *
     * Asserted by *using* the repositories rather than by inspecting the schema
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

    public function test_the_audit_table_satisfies_the_audit_repository(): void
    {
        $this->migration()->up();

        $audit = new WpNoteAuditRepository($this->db);

        $note = \Core\Note\Domain\NoteEntity::create('Title', 'Body', 7)->value();

        $audit->record(\Core\Note\Domain\NoteAuditEntry::record($note, 'created'));

        $this->assertCount(1, $this->auditRows());
    }

    /**
     * **`note_id` must be nullable — the create path depends on it.**
     *
     * The audit entry is written inside the transaction that inserts the note,
     * before the id exists, so it carries a null. A schema with `NOT NULL` there
     * fails every create at insert time, and the failure surfaces as an audit
     * write error rather than as a schema problem — which is a long way from the
     * cause.
     *
     * This asserts the column accepts null by writing one through the repository,
     * which is the only way the null ever arrives.
     */
    public function test_the_audit_table_accepts_an_entry_with_no_note_id(): void
    {
        $this->migration()->up();

        $audit = new WpNoteAuditRepository($this->db);
        $unsaved = \Core\Note\Domain\NoteEntity::create('Title', 'Body', 7)->value();

        $this->assertNull($unsaved->id, 'Precondition: this note has not been stored.');

        $audit->record(\Core\Note\Domain\NoteAuditEntry::record($unsaved, 'created'));

        $rows = $this->auditRows();

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['note_id'], 'The entry must be stored unattached, not attached to note 0.');
    }

    /**
     * `down()` removes exactly what `up()` created, in the order that respects
     * the foreign key.
     */
    public function test_it_reverses_cleanly(): void
    {
        $migration = $this->migration();

        $migration->up();
        $migration->down();

        $this->assertFalse($this->db->tableExists('wp_note_audit'));
        $this->assertFalse($this->db->tableExists('wp_notes'));
    }

    /**
     * A rollback of a migration that never ran must not explode.
     *
     * This is the state a partly-applied batch leaves behind, and it is exactly
     * when someone reaches for `migrate:rollback` — so `down()` is written with
     * `IF EXISTS` and that has to keep being true.
     */
    public function test_down_is_safe_when_the_tables_do_not_exist(): void
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

    /**
     * @return list<array<string, mixed>>
     */
    private function auditRows(): array
    {
        return $this->db->rows('wp_note_audit');
    }
}
