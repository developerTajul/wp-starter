<?php
declare(strict_types=1);

namespace Tests\Integration\App\Repositories;

use App\Repositories\WpNoteAuditRepository;
use Core\Note\Domain\NoteAuditEntry;
use Core\Note\Domain\NoteEntity;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\FakeWpdb;

/**
 * The `$wpdb`-backed audit trail, against a stand-in database.
 *
 * ## What this proves that the service tests cannot
 *
 * `NoteServiceTest` asserts that `recordAudit()` writes *an entry* to the sink.
 * It does that against an in-memory fake, so it proves the service calls the port
 * with the right value and proves nothing about whether the real adapter can
 * store it. This is where the INSERT is really issued, really parsed, and really
 * read back — a wrong column name, a mismatched placeholder count, or a nullable
 * `note_id` written as `0` all fail here rather than in production.
 *
 * ## The nullable `note_id`, which is the case worth having a test for
 *
 * The create path writes an audit entry *before* the note row exists, so the
 * entry carries a null `note_id`. It is the one column whose round trip is not a
 * plain copy, and both directions have a plausible wrong answer: writing null as
 * `0` (or as the empty string) attaches the entry to a note that may exist, and
 * reading null back with `(int)` turns "no id yet" into "note zero". Either
 * mistake produces a trail that reads as authoritative and is wrong.
 *
 * ## Setup mirrors a real install
 *
 * The table is created with the columns the migration would write, so the columns
 * the repository names are the columns that exist. A test that invented only the
 * columns it happened to use would pass while the real schema disagreed.
 */
final class WpNoteAuditRepositoryTest extends TestCase
{
    private FakeWpdb $db;

    private WpNoteAuditRepository $repository;

    private const TABLE = 'wp_note_audit';

    protected function setUp(): void
    {
        $this->db = new FakeWpdb();
        $this->db->createTable(self::TABLE, [
            'id',
            'note_id',
            'user_id',
            'event',
            'occurred_at',
        ]);

        $this->repository = new WpNoteAuditRepository($this->db);
    }

    // ------------------------------------------------------------------ writing

    public function test_an_entry_is_stored_and_can_be_read_back(): void
    {
        $this->repository->record($this->entry(noteId: 7, userId: 3, event: NoteAuditEntry::CREATED));

        $entries = $this->repository->forNote(7);

        $this->assertCount(1, $entries);
        $this->assertSame(7, $entries[0]->noteId);
        $this->assertSame(3, $entries[0]->userId);
        $this->assertSame(NoteAuditEntry::CREATED, $entries[0]->event);
    }

    /**
     * **The create path.** An entry written before the note has an id.
     *
     * Stored as SQL null and read back as PHP null — not as `0`, which would
     * point the entry at whatever note happens to hold that id.
     */
    public function test_an_entry_with_no_note_id_round_trips_as_null(): void
    {
        $this->repository->record($this->entry(noteId: null, userId: 3, event: NoteAuditEntry::CREATED));

        $row = $this->db->rows(self::TABLE)[0];

        $this->assertNull($row['note_id'], 'A null note id must be stored as SQL null, not as 0.');

        // Read back through the other accessor, since forNote() is keyed by id
        // and there is no id to key on — this is the state the create path leaves.
        $all = $this->readAll();

        $this->assertNull($all[0]->noteId, 'A stored null must not be read back as note zero.');
        $this->assertSame(NoteAuditEntry::CREATED, $all[0]->event);
    }

    public function test_entries_are_kept_per_note(): void
    {
        $this->repository->record($this->entry(noteId: 1, userId: 3, event: NoteAuditEntry::CREATED));
        $this->repository->record($this->entry(noteId: 2, userId: 3, event: NoteAuditEntry::CREATED));
        $this->repository->record($this->entry(noteId: 1, userId: 3, event: NoteAuditEntry::UPDATED));

        $this->assertSame(
            [NoteAuditEntry::CREATED, NoteAuditEntry::UPDATED],
            array_map(static fn (NoteAuditEntry $e): string => $e->event, $this->repository->forNote(1))
        );
        $this->assertCount(1, $this->repository->forNote(2));
    }

    /**
     * Oldest first, which is the order a trail is read in.
     *
     * The entries are written in the reverse of their timestamps so that insertion
     * order and time order disagree — a repository that returned rows in the order
     * they were inserted would pass a test where the two agreed.
     */
    public function test_entries_come_back_oldest_first(): void
    {
        $this->repository->record($this->entry(noteId: 1, userId: 3, event: 'third', at: '2026-03-01 00:00:00'));
        $this->repository->record($this->entry(noteId: 1, userId: 3, event: 'first', at: '2026-01-01 00:00:00'));
        $this->repository->record($this->entry(noteId: 1, userId: 3, event: 'second', at: '2026-02-01 00:00:00'));

        $this->assertSame(
            ['first', 'second', 'third'],
            array_map(static fn (NoteAuditEntry $e): string => $e->event, $this->repository->forNote(1))
        );
    }

    public function test_a_note_with_no_history_has_an_empty_trail(): void
    {
        $this->assertSame([], $this->repository->forNote(999));
    }

    // ------------------------------------------------------------------ failure

    /**
     * **A failed write must raise.**
     *
     * This is the one behaviour an audit sink cannot get wrong in the quiet
     * direction. A swallowed failure leaves a trail with holes that nothing
     * reports — and a missing entry is indistinguishable from an event that never
     * occurred. Raising is what lets the surrounding transaction roll back, so the
     * change the entry describes does not happen either.
     */
    public function test_a_failed_insert_raises_rather_than_reporting_success(): void
    {
        $this->db->failOn('INSERT INTO');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not record the');

        $this->repository->record($this->entry(noteId: 1, userId: 3, event: NoteAuditEntry::CREATED));
    }

    /**
     * The error names the event, so a trail that fails on one kind of write says
     * which — an audit sink used by several operations otherwise reports a bare
     * "could not write", which is the least useful moment to be vague.
     */
    public function test_a_failed_insert_names_the_event(): void
    {
        $this->db->failOn('INSERT INTO');

        try {
            $this->repository->record($this->entry(noteId: 1, userId: 3, event: NoteAuditEntry::DELETED));
            $this->fail('The insert should have raised.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(NoteAuditEntry::DELETED, $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ schema

    /**
     * The table name is the prefix and the constant, and no caller should have to
     * reassemble it. Asserted because a migration and this repository agreeing on
     * the name is what makes either work.
     */
    public function test_the_table_name_is_built_from_the_prefix(): void
    {
        $this->assertSame('wp_note_audit', $this->repository->getTableName());
    }

    /**
     * Without a handle anywhere, the repository says so rather than fataling on a
     * null property — the same contract the note repository has.
     */
    public function test_a_missing_database_handle_is_reported_clearly(): void
    {
        $repository = new WpNoteAuditRepository();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No $wpdb instance is available');

        $repository->record($this->entry(noteId: 1, userId: 3, event: NoteAuditEntry::CREATED));
    }

    // ------------------------------------------------------------------ harness

    /**
     * An audit entry about a note at a given id, or about an unsaved note.
     *
     * A null `$noteId` is built through {@see NoteEntity::create()} rather than
     * `reconstitute()`, and that is not incidental: `create()` is the only factory
     * that produces an entity with no id, which is exactly the state the create
     * path audits from. `reconstitute()` demands an int, because a row read out of
     * storage always has one.
     */
    private function entry(
        ?int $noteId,
        int $userId,
        string $event,
        string $at = '2026-01-01 00:00:00'
    ): NoteAuditEntry {
        if ($noteId === null) {
            $note = NoteEntity::create('Title', 'Body', $userId)->value();
        } else {
            $note = NoteEntity::reconstitute(
                $noteId,
                'Title',
                'Body',
                $userId,
                new \DateTimeImmutable('2026-01-01 00:00:00')
            );
        }

        return NoteAuditEntry::record(
            $note,
            $event,
            new \DateTimeImmutable($at, new \DateTimeZone('UTC'))
        );
    }

    /**
     * Every stored entry, read through the repository even when the note id is
     * null and {@see forNote()} has nothing to key on.
     *
     * Deliberately issues a SELECT the repository itself does not offer: the point
     * is to read the columns as stored, so a repository that round-tripped a null
     * wrongly cannot hide it by being the only reader.
     *
     * @return list<NoteAuditEntry>
     */
    private function readAll(): array
    {
        $reflection = new \ReflectionMethod($this->repository, 'hydrate');

        return array_map(
            fn (array $row): NoteAuditEntry => $reflection->invoke($this->repository, $row),
            $this->db->rows(self::TABLE)
        );
    }
}
