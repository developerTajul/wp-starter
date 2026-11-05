<?php
declare(strict_types=1);

namespace Tests\Integration\App\Repositories;

use App\Repositories\WpNoteRepository;
use Core\Note\Domain\NoteEntity;
use Core\Shared\PaginatedResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\FakeWpdb;

/**
 * The `$wpdb`-backed note repository, against a stand-in database.
 *
 * ## What kind of test this is, and why it lives here
 *
 * It is an *integration* test in the sense that matters: the repository's own
 * SQL is really issued, really parsed, and really answered from rows — the only
 * thing replaced is the MySQL server. {@see FakeWpdb} executes the statements
 * against arrays, so a query with a wrong column name, a mismatched placeholder
 * count or a `LIMIT`/`OFFSET` the fake cannot parse fails loudly here rather than
 * passing as a mock's canned return value.
 *
 * That is the point of testing it at this level rather than asserting that
 * `get_row()` was called. A mock would happily accept `SELECT * FROM notes
 * WHERE id = %d` with the arguments in the wrong order; this does not, because
 * the row that comes back would be the wrong one.
 *
 * ## What it does not prove
 *
 * That the SQL is valid *MySQL*. `FakeWpdb` understands a narrow subset and
 * throws on anything else, which catches shape errors and typos but not a
 * semantic difference between the fake's MINI engine and InnoDB — a real cost,
 * and the reason `tests/manual/` holds probes that run against a live database.
 *
 * ## The setup mirrors a real install
 *
 * The table is created with the same `dbDelta()`-style definition the migration
 * would write, including the index the list query depends on, so the columns the
 * repository names are the columns that exist. A test that invented only the
 * columns it happened to use would pass while the real schema disagreed.
 */
final class WpNoteRepositoryTest extends TestCase
{
    private FakeWpdb $db;

    private WpNoteRepository $repository;

    private const TABLE = 'wp_notes';

    protected function setUp(): void
    {
        $this->db = new FakeWpdb();
        $this->db->createTable(self::TABLE, [
            'id',
            'title',
            'content',
            'user_id',
            'created_at',
            'updated_at',
        ]);

        $this->repository = new WpNoteRepository($this->db);
    }

    /**
     * Seed a stored row directly, so tests can fix the ids they assert on.
     *
     * @param array<string, mixed> $overrides
     */
    private function seed(int $id, int $userId, string $title = 'Existing', string $createdAt = '2026-01-01 00:00:00'): void
    {
        $this->db->seed(self::TABLE, [
            'id' => $id,
            'title' => $title,
            'content' => 'Body',
            'user_id' => $userId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function note(?int $id, int $userId, string $title = 'A note'): NoteEntity
    {
        $built = NoteEntity::create($title, 'Body', $userId, new \DateTimeImmutable('2026-02-01 09:00:00'));

        $this->assertTrue($built->isOk(), 'The fixture note should be valid.');

        /** @var NoteEntity $entity */
        $entity = $built->value();

        if ($id === null) {
            return $entity;
        }

        $stamped = $entity->withId($id);
        $this->assertTrue($stamped->isOk());

        /** @var NoteEntity $stampedEntity */
        $stampedEntity = $stamped->value();

        return $stampedEntity;
    }

    // -------------------------------------------------------------------- find

    public function test_find_returns_a_hydrated_entity(): void
    {
        $this->seed(7, userId: 3, title: 'Ada');

        $note = $this->repository->find(7);

        $this->assertInstanceOf(NoteEntity::class, $note);
        $this->assertSame(7, $note->id);
        $this->assertSame('Ada', $note->title);
        $this->assertSame('Body', $note->content);
        $this->assertSame(3, $note->userId);
        $this->assertSame('2026-01-01 00:00:00', $note->createdAt->format('Y-m-d H:i:s'));
    }

    /**
     * The timestamps come back as `DateTimeImmutable` values read as UTC, not as
     * strings and not in the process's default zone — the entity's contract.
     */
    public function test_find_reads_timestamps_as_utc_date_times(): void
    {
        $this->seed(7, userId: 3, createdAt: '2026-01-01 12:00:00');

        $note = $this->repository->find(7);

        $this->assertInstanceOf(\DateTimeImmutable::class, $note->createdAt);
        $this->assertSame('UTC', $note->createdAt->getTimezone()->getName());
        $this->assertSame('2026-01-01T12:00:00+00:00', $note->createdAt->format(\DateTimeInterface::ATOM));
    }

    public function test_find_returns_null_for_an_absent_row(): void
    {
        $this->assertNull($this->repository->find(999));
    }

    /**
     * The repository has no ownership opinion of its own — `find()` is the
     * unguarded read the contract describes, and the guard is `findOwnedBy()`.
     */
    public function test_find_returns_another_users_note(): void
    {
        $this->seed(7, userId: 3);

        $this->assertNotNull($this->repository->find(7));
    }

    // -------------------------------------------------------------- findOwnedBy

    public function test_find_owned_by_returns_the_callers_note(): void
    {
        $this->seed(7, userId: 3, title: 'Mine');

        $note = $this->repository->findOwnedBy(7, 3);

        $this->assertInstanceOf(NoteEntity::class, $note);
        $this->assertSame('Mine', $note->title);
    }

    /**
     * The security property this method exists for: an unauthorized read must
     * not load the row at all, and "not yours" must be indistinguishable from
     * "does not exist" so a 403 cannot confirm a note's existence.
     */
    public function test_find_owned_by_returns_null_for_another_users_note(): void
    {
        $this->seed(7, userId: 3);

        $this->assertNull($this->repository->findOwnedBy(7, 4));
    }

    public function test_find_owned_by_returns_null_for_an_absent_note(): void
    {
        $this->assertNull($this->repository->findOwnedBy(999, 3));
    }

    /**
     * The ownership predicate is in the query, so the row never comes back — the
     * table is asserted to have been narrowed by SQL rather than by a check the
     * repository could omit.
     */
    public function test_find_owned_by_pushes_the_owner_predicate_into_the_query(): void
    {
        $this->seed(7, userId: 3);

        $this->repository->findOwnedBy(7, 4);

        $this->assertStringContainsString('user_id', implode("\n", $this->db->queries));
    }

    // ----------------------------------------------------------- paginateForUser

    public function test_paginate_returns_a_page_of_the_callers_notes(): void
    {
        $this->seed(1, userId: 7, title: 'One');
        $this->seed(2, userId: 7, title: 'Two');
        $this->seed(3, userId: 7, title: 'Three');

        $page = $this->repository->paginateForUser(7, 2, 1);

        $this->assertInstanceOf(PaginatedResult::class, $page);
        $this->assertCount(2, $page->items());
        $this->assertSame(3, $page->total());
        $this->assertSame(2, $page->lastPage());
        $this->assertTrue($page->hasMorePages());
    }

    /**
     * The total is the count across every page, not the size of the slice. A
     * repository that passed `count($rows)` here reports "1 of 1" for every page.
     */
    public function test_paginate_reports_the_total_across_all_pages(): void
    {
        foreach (range(1, 5) as $i) {
            $this->seed($i, userId: 7);
        }

        $page = $this->repository->paginateForUser(7, 2, 2);

        $this->assertCount(2, $page->items());
        $this->assertSame(5, $page->total());
        $this->assertSame(2, $page->currentPage());
    }

    public function test_paginate_offsets_to_the_requested_page(): void
    {
        $this->seed(1, userId: 7, title: 'First', createdAt: '2026-01-03 00:00:00');
        $this->seed(2, userId: 7, title: 'Second', createdAt: '2026-01-02 00:00:00');
        $this->seed(3, userId: 7, title: 'Third', createdAt: '2026-01-01 00:00:00');

        // Newest first, so page 2 of size 2 holds the oldest note.
        $page = $this->repository->paginateForUser(7, 2, 2);

        $this->assertCount(1, $page->items());
        $this->assertSame('Third', $page->items()[0]->title);
    }

    public function test_paginate_returns_newest_first(): void
    {
        $this->seed(1, userId: 7, title: 'Older', createdAt: '2026-01-01 00:00:00');
        $this->seed(2, userId: 7, title: 'Newer', createdAt: '2026-01-02 00:00:00');

        $page = $this->repository->paginateForUser(7, 10, 1);

        $this->assertSame(['Newer', 'Older'], array_map(
            static fn (NoteEntity $note): string => $note->title,
            $page->items()
        ));
    }

    public function test_paginate_excludes_other_users_notes(): void
    {
        $this->seed(1, userId: 7);
        $this->seed(2, userId: 8);

        $page = $this->repository->paginateForUser(7, 10, 1);

        $this->assertSame(1, $page->total());
        $this->assertCount(1, $page->items());
        $this->assertSame(7, $page->items()[0]->userId);
    }

    /**
     * An empty result is an empty page with a zero total, not a failure and not
     * a null. `PaginatedResult` is the shape a caller can render either way.
     */
    public function test_paginate_returns_an_empty_page_when_the_user_has_no_notes(): void
    {
        $page = $this->repository->paginateForUser(7, 10, 1);

        $this->assertSame([], $page->items());
        $this->assertSame(0, $page->total());
        $this->assertSame(1, $page->lastPage());
    }

    /**
     * A page past the end is empty but still reports the page that was asked
     * for, because that is what a client building a "previous" link needs.
     */
    public function test_paginate_past_the_end_is_an_empty_page_not_an_error(): void
    {
        $this->seed(1, userId: 7);

        $page = $this->repository->paginateForUser(7, 10, 99);

        $this->assertSame([], $page->items());
        $this->assertSame(99, $page->currentPage());
        $this->assertSame(1, $page->total());
    }

    /**
     * The repository clamps too, because it computes LIMIT and OFFSET before any
     * value object exists to do it — a negative OFFSET is rejected by MySQL and
     * silently read as zero by SQLite.
     */
    public function test_paginate_clamps_a_non_positive_page_size(): void
    {
        $this->seed(1, userId: 7);
        $this->seed(2, userId: 7);

        $page = $this->repository->paginateForUser(7, 0, 1);

        $this->assertSame(1, $page->perPage());
        $this->assertCount(1, $page->items());
    }

    public function test_paginate_clamps_a_page_below_one(): void
    {
        $this->seed(1, userId: 7);

        $page = $this->repository->paginateForUser(7, 10, -4);

        $this->assertSame(1, $page->currentPage());
        $this->assertCount(1, $page->items());
    }

    public function test_paginate_caps_an_enormous_page_size(): void
    {
        $this->seed(1, userId: 7);

        $page = $this->repository->paginateForUser(7, 10000000, 1);

        $this->assertSame(PaginatedResult::MAX_PER_PAGE, $page->perPage());
    }

    // -------------------------------------------------------------------- save

    public function test_save_inserts_a_note_and_assigns_an_id(): void
    {
        $saved = $this->repository->save($this->note(null, userId: 7, title: 'Fresh'));

        $this->assertNotNull($saved->id, 'save() must return the id storage assigned.');
        $this->assertTrue($saved->exists());
        $this->assertSame('Fresh', $saved->title);
    }

    /**
     * The insert really reached the table: reading the assigned id back finds the
     * same note, which proves the column mapping round-trips.
     */
    public function test_save_writes_the_row_so_it_can_be_read_back(): void
    {
        $saved = $this->repository->save($this->note(null, userId: 7, title: 'Round trip'));

        $found = $this->repository->find((int) $saved->id);

        $this->assertNotNull($found);
        $this->assertSame('Round trip', $found->title);
        $this->assertSame(7, $found->userId);
    }

    public function test_save_persists_the_timestamps(): void
    {
        $note = $this->note(null, userId: 7);
        $saved = $this->repository->save($note);

        $found = $this->repository->find((int) $saved->id);

        $this->assertNotNull($found);
        $this->assertSame(
            '2026-02-01T09:00:00+00:00',
            $found->createdAt->format(\DateTimeInterface::ATOM)
        );
    }

    /**
     * Saving a note that already has an id updates its row rather than inserting
     * a second one — the contract's whole reason for a single `save()`.
     */
    public function test_save_updates_an_existing_note_without_duplicating_it(): void
    {
        $this->seed(7, userId: 3, title: 'Before');

        $edited = $this->note(7, userId: 3, title: 'After');

        $saved = $this->repository->save($edited);

        $this->assertSame(7, $saved->id);
        $this->assertCount(1, $this->db->rows(self::TABLE), 'An update must not insert a second row.');
        $this->assertSame('After', $this->repository->find(7)?->title);
    }

    public function test_save_does_not_change_the_created_at_timestamp(): void
    {
        $this->seed(7, userId: 3, createdAt: '2026-01-01 00:00:00');

        $this->repository->save($this->note(7, userId: 3, title: 'Edited'));

        $row = $this->db->rows(self::TABLE)[0];

        $this->assertSame('2026-01-01 00:00:00', $row['created_at']);
    }

    public function test_a_storage_failure_on_insert_is_thrown_not_swallowed(): void
    {
        $this->db->failOn('INSERT INTO');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not store a new note');

        $this->repository->save($this->note(null, userId: 7));
    }

    public function test_a_storage_failure_on_update_is_thrown_not_swallowed(): void
    {
        $this->seed(7, userId: 3);
        $this->db->failOn('UPDATE');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not update note 7');

        $this->repository->save($this->note(7, userId: 3, title: 'Nope'));
    }

    // ------------------------------------------------------------------ delete

    public function test_delete_removes_the_row_and_reports_true(): void
    {
        $this->seed(7, userId: 3);

        $this->assertTrue($this->repository->delete(7));
        $this->assertNull($this->repository->find(7));
    }

    /**
     * Deleting something already gone achieved what the caller wanted, so it is
     * `false` rather than an exception — the contract is explicit about this.
     */
    public function test_delete_reports_false_for_an_absent_row(): void
    {
        $this->assertFalse($this->repository->delete(999));
    }

    public function test_delete_leaves_other_rows_alone(): void
    {
        $this->seed(1, userId: 3);
        $this->seed(2, userId: 3);

        $this->repository->delete(1);

        $this->assertCount(1, $this->db->rows(self::TABLE));
        $this->assertNotNull($this->repository->find(2));
    }

    // ------------------------------------------------------------------ plumbing

    public function test_the_table_name_is_prefixed(): void
    {
        $this->assertSame(self::TABLE, $this->repository->getTableName());
    }

    /**
     * No $wpdb global and none injected is a wiring fault, and it must be
     * reported as one rather than surfacing as a null-dereference later.
     */
    public function test_a_missing_database_handle_is_reported(): void
    {
        $repository = new WpNoteRepository(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No $wpdb instance is available');

        $repository->find(1);
    }
}
