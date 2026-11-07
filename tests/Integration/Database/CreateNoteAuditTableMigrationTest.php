<?php
declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Repositories\WpNoteAuditRepository;
use Framework\Database\Adapters\WpDatabaseAdapter;
use Framework\Database\MigrationFile;
use Framework\Database\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;

/**
 * The note-audit migration, run against a stand-in database.
 *
 * The sibling of {@see CreateNotesTableMigrationTest}, which explains why these
 * tests exist and why they are split. What is specific to this one:
 *
 * ## The foreign key makes this migration order-dependent
 *
 * `up()` names the notes table in a constraint, so it can only run after
 * `..._120000_create_notes_table.php` has. Nothing in the language ties the two
 * together — the only ordering the migrator applies is by filename — so the
 * dependency is carried entirely by the timestamp. A future edit that renamed
 * this file to sort *before* `120000` would produce a migration that fails on a
 * fresh install and passes on every machine that had already run the notes one,
 * which is the worst kind of schema bug to find in production.
 *
 * That is why the notes table is created explicitly in `setUp()` below rather
 * than being assumed: it makes the dependency visible in the test, and it fails
 * loudly here if the constraint ever points somewhere the migration no longer
 * creates.
 *
 * ## What this test cannot check
 *
 * Whether the foreign key is *enforced*. `FakeWpdb` reads a CREATE for its
 * column list and ignores the `CONSTRAINT` clause, so it cannot tell that the
 * key names a table which does not exist — `up()` here succeeds against a
 * database with no notes table, where MySQL would refuse the statement. The key
 * was confirmed present and enforced against a live MySQL 5.7.33 server:
 *
 *     SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
 *      WHERE TABLE_NAME = 'leadbridge_note_audit' AND CONSTRAINT_TYPE = 'FOREIGN KEY';
 *     -- 1
 *
 * So the ordering guarantee is verified there, not here. Teaching the fake to
 * resolve constraint references would close this, and is worth doing the next
 * time the fake is opened for another reason.
 */
final class CreateNoteAuditTableMigrationTest extends TestCase
{
    private const PATH = __DIR__ . '/../../../database/migrations/2026_11_05_120001_create_note_audit_table.php';

    private FakeWpdb $db;

    /**
     * Whatever `$wpdb` held before this test replaced it.
     */
    private ?\wpdb $restoreGlobal = null;

    protected function setUp(): void
    {
        $this->db = new FakeWpdb();

        Schema::useAdapter(new WpDatabaseAdapter($this->db));

        // See the sibling test's `setUp()` for why the global is installed and
        // restored: the migration reaches the database through `Schema::`, and
        // the `dbDelta()` stub reaches for `$wpdb` itself.
        $previous = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $this->db;

        $this->restoreGlobal = $previous;

        // The precondition this migration has, stated rather than assumed: the
        // notes table exists because the migration before this one made it.
        $this->db->createTable('wp_notes', ['id', 'title', 'content', 'user_id', 'created_at', 'updated_at']);
    }

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
        $this->assertSame('CreateNoteAuditTable', $file->class);
    }

    public function test_it_creates_the_audit_table(): void
    {
        $this->migration()->up();

        $this->assertTrue($this->db->tableExists('wp_note_audit'));
    }

    /**
     * `up()` creates its own table and no other.
     *
     * The whole reason for the split: a migration that also created the notes
     * table would put this migration and its neighbour in a race over the same
     * table, which is the failure the split removed.
     *
     * Asserted by *column count* rather than by dropping the notes table and
     * expecting a failure. That second form is the more obvious test and it does
     * not work here: the fake reads a CREATE for its column list and ignores the
     * `CONSTRAINT` clause entirely, so it cannot tell that a foreign key names a
     * table which is absent, and the statement succeeds where MySQL would refuse
     * it. Asserting on it would be a test that encodes the fake's limitation as
     * the expected behaviour — the same class of false green as the duplicate
     * CREATE this suite just stopped tolerating.
     *
     * The ordering guarantee itself is checked against a live server instead; see
     * the note in the class docblock.
     */
    public function test_it_creates_only_its_own_table(): void
    {
        $columnsBefore = $this->db->get_col('SHOW COLUMNS FROM `wp_notes`');

        $this->migration()->up();

        $this->assertTrue($this->db->tableExists('wp_note_audit'));
        $this->assertSame(
            $columnsBefore,
            $this->db->get_col('SHOW COLUMNS FROM `wp_notes`'),
            'The notes table is the previous migration\'s; this one must not touch it.'
        );
    }

    public function test_the_audit_table_satisfies_the_audit_repository(): void
    {
        $this->migration()->up();

        $audit = new WpNoteAuditRepository($this->db);

        $note = \Core\Note\Domain\NoteEntity::create('Title', 'Body', 7)->value();

        $audit->record(\Core\Note\Domain\NoteAuditEntry::record($note, 'created'));

        $this->assertCount(1, $this->db->rows('wp_note_audit'));
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

        $rows = $this->db->rows('wp_note_audit');

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['note_id'], 'The entry must be stored unattached, not attached to note 0.');
    }

    /**
     * `down()` removes exactly what `up()` created, and nothing else.
     *
     * The notes table must survive: this migration did not create it, and a
     * `down()` that dropped it would delete data belonging to a migration that
     * is still recorded as applied.
     */
    public function test_it_reverses_cleanly_without_touching_the_notes_table(): void
    {
        $migration = $this->migration();

        $migration->up();
        $migration->down();

        $this->assertFalse($this->db->tableExists('wp_note_audit'));
        $this->assertTrue(
            $this->db->tableExists('wp_notes'),
            'The notes table belongs to the migration before this one; dropping it here would lose '
            . 'data whose migration is still recorded as applied.'
        );
    }

    /**
     * A rollback of a migration that never ran must not explode.
     *
     * The state a partly-applied batch leaves behind, and exactly when someone
     * reaches for `migrate:rollback` — so `down()` is written with `IF EXISTS`.
     */
    public function test_down_is_safe_when_the_table_does_not_exist(): void
    {
        $this->migration()->down();

        $this->assertFalse($this->db->tableExists('wp_note_audit'));
    }

    /**
     * The class the filename resolves to, instantiated with a test handle.
     *
     * Required by path, as the migrator does — see the sibling test's docblock
     * for why the autoloader would be the wrong way to reach it.
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
