<?php
declare(strict_types=1);

namespace Tests\Unit\Core\Note;

use Core\Note\Application\NoteService;
use Core\Note\Domain\NoteAuditEntry;
use Core\Note\Domain\NoteEntity;
use Core\Shared\PaginatedResult;
use Core\Shared\Result;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Unit\Core\Note\Fixtures\InMemoryNoteRepository;
use Tests\Unit\Core\Note\Fixtures\RecordingNoteAudit;
use Tests\Unit\Core\Note\Fixtures\RecordingTransactionManager;

/**
 * The note use cases, with no database and no framework loaded.
 *
 * ## What this file is really testing
 *
 * Two properties, more than any individual use case:
 *
 *  1. **Nothing returns a boolean and nothing throws for a user-caused failure.**
 *     Every failure path below asserts on the `Result` it comes back as. That is
 *     the architectural rule this whole layer exists to obey, and a rule with no
 *     test is a comment.
 *  2. **A nested transaction joins rather than nests.** `create()`,
 *     `update()` and `delete()` all write an audit entry inside their
 *     transaction, and the manager's recorded depth is what proves the audit did
 *     not open an independent one — a bug that would let an audit record survive
 *     the rollback of the change it describes.
 *
 * The fakes are in `Fixtures/` and implement the real contracts, so these tests
 * fail if the contracts change shape — which is the point of testing against the
 * port rather than against a mock of the call pattern.
 */
final class NoteServiceTest extends TestCase
{
    private InMemoryNoteRepository $repository;

    private RecordingTransactionManager $transactions;

    private RecordingNoteAudit $audit;

    private NoteService $service;

    protected function setUp(): void
    {
        $this->repository = new InMemoryNoteRepository();
        $this->transactions = new RecordingTransactionManager();

        // The sink is handed a probe for the *manager's* depth rather than the
        // manager itself, so each entry carries the depth it was written at. That
        // is what makes "the audit write joined the outer transaction" an
        // assertion about the write instead of an inference from the call order.
        $this->audit = new RecordingNoteAudit(fn (): int => $this->transactions->depth);

        $this->service = new NoteService($this->repository, $this->transactions, $this->audit);
    }

    private function seedNote(int $id, int $userId, string $title = 'Existing', string $body = 'Body'): NoteEntity
    {
        return $this->repository->seed(NoteEntity::reconstitute(
            $id,
            $title,
            $body,
            $userId,
            new \DateTimeImmutable('2026-01-01 00:00:00')
        ));
    }

    // -------------------------------------------------------------------- create

    public function test_create_returns_a_result_carrying_the_stored_note(): void
    {
        $result = $this->service->create('Title', 'Body', 7);

        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->isOk());

        /** @var NoteEntity $note */
        $note = $result->value();

        $this->assertInstanceOf(NoteEntity::class, $note);
        $this->assertSame('Title', $note->title);
        $this->assertSame(7, $note->userId);
    }

    /**
     * Storage assigns the id, and the caller must receive it. A service that
     * returned the entity it was handed would give back a note whose `exists()`
     * says it was never stored.
     */
    public function test_create_returns_the_note_with_the_id_storage_assigned(): void
    {
        /** @var NoteEntity $note */
        $note = $this->service->create('Title', 'Body', 7)->value();

        $this->assertNotNull($note->id);
        $this->assertTrue($note->exists());
        $this->assertSame($note->id, $this->repository->find((int) $note->id)?->id);
    }

    /**
     * The rule this layer exists to enforce: a failure the user caused comes back
     * as a value, not as a throw and not as a bare `false`.
     */
    public function test_create_returns_a_validation_failure_rather_than_throwing(): void
    {
        $result = $this->service->create('', 'Body', 7);

        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->isFailure());
        $this->assertSame(422, $result->status());
        $this->assertArrayHasKey('title', $result->errors());
    }

    /**
     * Invalid input is rejected before storage is touched. Opening a transaction
     * to discover that a title is too long would take a write lock to answer a
     * question about a string.
     */
    public function test_create_does_not_touch_storage_when_the_input_is_invalid(): void
    {
        $this->service->create('', 'Body', 7);

        $this->assertSame(0, $this->repository->calls['save']);
        $this->assertSame(0, $this->transactions->opened);
        $this->assertSame(0, $this->transactions->entered);
    }

    public function test_create_stores_exactly_one_note(): void
    {
        $this->service->create('Title', 'Body', 7);

        $this->assertSame(1, $this->repository->count());
        $this->assertSame(1, $this->repository->calls['save']);
    }

    public function test_create_wraps_the_work_in_a_transaction(): void
    {
        $this->service->create('Title', 'Body', 7);

        $this->assertSame(1, $this->transactions->opened);
        $this->assertSame(1, $this->transactions->committed);
        $this->assertSame(0, $this->transactions->rollbacks);
        $this->assertSame(0, $this->transactions->depth, 'The transaction must be closed on the way out.');
    }

    // ------------------------------------------------------------ nested audit

    /**
     * The depth rule, asserted directly.
     *
     * `recordAudit()` opens a transaction of its own, and it must join the one
     * `create()` already holds. Depth 2 means it joined; depth 1 would mean the
     * audit write happened somewhere else entirely, and only one transaction was
     * ever opened — which is the bug where an audit entry commits independently of
     * the change it describes.
     */
    public function test_the_audit_write_joins_the_outer_transaction_rather_than_nesting(): void
    {
        $this->service->create('Title', 'Body', 7);

        $this->assertSame(2, $this->transactions->maxDepth, 'The audit write must nest one level.');
        $this->assertSame(1, $this->transactions->opened, 'Only one real transaction may be opened.');
        $this->assertSame(1, $this->transactions->committed, 'The inner call must not commit.');
        $this->assertSame([1, 2], $this->transactions->entries, 'The audit must run inside the outer frame.');
    }

    // ------------------------------------------------------- the audit entry

    /**
     * **The assertion the depth tests could not make.**
     *
     * The tests around this one assert on the transaction *shape*: one real
     * transaction opened, two frames entered, one commit. Every one of those
     * passed against a `recordAudit()` whose body evaluated a boolean and
     * discarded it — which is what the method actually did, and why the guarantee
     * it documented ("the audit write happens inside the same unit of work as the
     * insert") was unverifiable in the very tests claiming to prove it.
     *
     * A depth of 2 and a commit count of 1 describe a *transaction*. They say
     * nothing about whether anything was stored inside it. This asserts on the
     * stored entry, which is the thing the guarantee is about.
     */
    public function test_create_writes_an_audit_entry_for_the_note_it_stored(): void
    {
        $this->service->create('Title', 'Body', 7);

        $this->assertSame(1, $this->audit->count(), 'The audit sink must actually be written to.');
        $this->assertSame([NoteAuditEntry::CREATED], $this->audit->events());

        $entry = $this->audit->entries[0];

        $this->assertSame(7, $entry->userId, 'The entry must carry the owner an audit trail is queried by.');
        $this->assertSame(
            $this->repository->find(1)?->id,
            $entry->noteId,
            'The entry must name the note storage actually assigned an id to.'
        );
    }

    /**
     * The entry is written inside the outer frame, not after it commits.
     *
     * The depth test above proves the *call* nested. This proves the *write*
     * happened there — the same distinction as the previous test, applied to the
     * property the class docblock promises. A service that built the entry inside
     * the transaction and then recorded it after would satisfy every other
     * assertion in this file.
     */
    public function test_the_entry_is_written_at_the_outer_transactions_depth(): void
    {
        $this->service->create('Title', 'Body', 7);

        $this->assertSame(
            [2],
            $this->audit->depths,
            'The write must land at depth 2 — inside the outer frame, inside the nested one.'
        );
    }

    public function test_update_records_an_updated_entry(): void
    {
        $this->seedNote(1, userId: 7);

        $this->service->update(1, 'New', 'New body', 7);

        $this->assertSame([NoteAuditEntry::UPDATED], $this->audit->events());
        $this->assertSame([2], $this->audit->depths);
    }

    /**
     * The delete path audits *before* removing the row, so the entry names a
     * note that still exists at the moment it is written — and carries an id,
     * which a post-delete write could not.
     */
    public function test_delete_records_a_deleted_entry_naming_the_note(): void
    {
        $this->seedNote(1, userId: 7);

        $this->service->delete(1, 7);

        $this->assertSame([NoteAuditEntry::DELETED], $this->audit->events());
        $this->assertSame([2], $this->audit->depths);
        $this->assertSame(1, $this->audit->entries[0]->noteId);
    }

    /**
     * A note that failed validation is not an audited event.
     *
     * The trail records what *happened*; a rejected input is not something that
     * happened to a note. A service that audited before validating would fill the
     * trail with events for notes that were never created.
     */
    public function test_a_rejected_create_records_nothing(): void
    {
        $result = $this->service->create('', 'Body', 7);

        $this->assertTrue($result->isFailure());
        $this->assertSame(0, $this->audit->count());
    }

    /**
     * An edit that changed nothing writes nothing — including no audit entry.
     *
     * Otherwise a user who opens a form and submits it unchanged generates a
     * trail full of "updated" entries for updates that never reached storage.
     */
    public function test_an_unchanged_update_records_no_audit_entry(): void
    {
        $this->seedNote(1, userId: 7, title: 'Same', body: 'Body');

        $this->service->update(1, 'Same', 'Body', 7);

        $this->assertSame(0, $this->audit->count());
    }

    /**
     * **A failed audit write must take the note down with it.**
     *
     * This is the guarantee in the other direction, and it is the reason the write
     * lives inside the transaction rather than after it. If the audit cannot be
     * stored, the change it describes must not be stored either — a note that
     * exists with no record of who created it is the half-written state the
     * transaction exists to rule out.
     *
     * The service catches the throwable and re-raises a `RuntimeException` naming
     * the operation, so the sink's own exception does not reach the caller as a
     * storage detail. What matters here is that it fails at all.
     */
    public function test_a_failing_audit_sink_fails_the_create(): void
    {
        $this->audit->failWith = new RuntimeException('The audit table is unreachable.');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not be created');

        $this->service->create('Title', 'Body', 7);
    }

    public function test_update_nests_the_audit_write_inside_its_transaction(): void
    {
        $this->seedNote(1, userId: 7);

        $this->service->update(1, 'New', 'New body', 7);

        $this->assertSame(2, $this->transactions->maxDepth);
        $this->assertSame(1, $this->transactions->opened);
        $this->assertSame([1, 2], $this->transactions->entries);
    }

    public function test_the_audit_runs_after_the_row_is_stored_but_inside_the_transaction(): void
    {
        $this->service->create('Title', 'Body', 7);

        // Both happened, and the depth trace says the second happened within the
        // first's frame rather than after it committed.
        $this->assertSame(1, $this->repository->calls['save']);
        $this->assertSame(2, $this->transactions->entered);
        $this->assertSame(1, $this->transactions->committed);
    }

    // ------------------------------------------------------- infrastructure failure

    /**
     * A storage failure is not a user-caused failure, so it is *not* returned as
     * a `Result` — it is re-thrown, with a message that names the operation.
     *
     * The original is attached as the cause rather than flattened into the
     * message, so the driver's detail is still reachable in a log while the
     * message a caller might surface says nothing about the schema.
     */
    public function test_a_storage_failure_is_rethrown_with_the_operation_named(): void
    {
        $this->repository->failSaveWith = new RuntimeException('SQLSTATE[HY000]: server has gone away');

        try {
            $this->service->create('Title', 'Body', 7);
            $this->fail('A storage failure must not be returned as a Result.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('could not be created', $e->getMessage());
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage(), 'Schema detail must not leak.');
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
            $this->assertStringContainsString('server has gone away', (string) $e->getPrevious()?->getMessage());
        }
    }

    public function test_a_failed_write_is_rolled_back_and_nothing_is_stored(): void
    {
        $this->repository->failSaveWith = new RuntimeException('Connection lost.');

        try {
            $this->service->create('Title', 'Body', 7);
        } catch (RuntimeException) {
        }

        $this->assertSame(1, $this->transactions->rollbacks);
        $this->assertSame(0, $this->transactions->committed);
        $this->assertSame(0, $this->repository->count());
    }

    public function test_a_transaction_that_cannot_open_is_reported_as_a_failure(): void
    {
        $this->transactions->failWith = new RuntimeException('Could not connect.');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not be created');

        $this->service->create('Title', 'Body', 7);
    }

    // -------------------------------------------------------------------- update

    public function test_update_returns_the_changed_note(): void
    {
        $this->seedNote(1, userId: 7, title: 'Before', body: 'Old body');

        $result = $this->service->update(1, 'After', 'New body', 7);

        $this->assertTrue($result->isOk());

        /** @var NoteEntity $note */
        $note = $result->value();

        $this->assertSame('After', $note->title);
        $this->assertSame('New body', $note->content);
    }

    public function test_update_persists_the_change(): void
    {
        $this->seedNote(1, userId: 7, title: 'Before');

        $this->service->update(1, 'After', 'New body', 7);

        $this->assertSame('After', $this->repository->find(1)?->title);
        $this->assertSame('New body', $this->repository->find(1)?->content);
    }

    /**
     * The single most security-relevant assertion in this file.
     *
     * A note belonging to another user must be indistinguishable from one that
     * does not exist. A 403 here would confirm that a note with that id exists,
     * which is an enumeration leak in a request that was already refused — and a
     * service that returned the note and let the caller check ownership would pass
     * every other test in this file.
     */
    public function test_update_refuses_a_note_belonging_to_another_user(): void
    {
        $this->seedNote(1, userId: 7, title: 'Theirs');

        $result = $this->service->update(1, 'Hijacked', 'Hijacked', 8);

        $this->assertTrue($result->isFailure());
        $this->assertSame(404, $result->status(), 'Not 403: a 403 would confirm the note exists.');
        $this->assertSame('not_found', $result->errorCode());
        $this->assertSame('Theirs', $this->repository->find(1)?->title, 'Nothing may have been written.');
    }

    public function test_update_reports_the_same_failure_for_absent_as_for_not_owned(): void
    {
        $this->seedNote(1, userId: 7);

        $absent = $this->service->update(999, 'x', 'y', 7);
        $theirs = $this->service->update(1, 'x', 'y', 8);

        $this->assertSame($absent->status(), $theirs->status());
        $this->assertSame($absent->errorCode(), $theirs->errorCode());
    }

    public function test_update_returns_a_validation_failure_for_a_bad_title(): void
    {
        $this->seedNote(1, userId: 7);

        $result = $this->service->update(1, '', 'Body', 7);

        $this->assertTrue($result->isFailure());
        $this->assertSame(422, $result->status());
        $this->assertArrayHasKey('title', $result->errors());
    }

    public function test_update_does_not_write_when_validation_fails(): void
    {
        $this->seedNote(1, userId: 7, title: 'Unchanged');

        $this->service->update(1, '', 'Body', 7);

        $this->assertSame(0, $this->repository->calls['save']);
        $this->assertSame('Unchanged', $this->repository->find(1)?->title);
    }

    /**
     * A no-op edit must not reach storage. `withTitle()` returns the same instance
     * for an unchanged value, which is what makes this a reliable identity test —
     * and skipping the write means `updated_at` is not bumped to make a note look
     * recently edited when nothing changed.
     */
    public function test_update_skips_the_write_when_nothing_changed(): void
    {
        $this->seedNote(1, userId: 7, title: 'Same', body: 'Same body');

        $result = $this->service->update(1, 'Same', 'Same body', 7);

        $this->assertTrue($result->isOk());
        $this->assertSame(0, $this->repository->calls['save']);
        $this->assertSame(0, $this->transactions->opened);
    }

    public function test_update_only_touches_the_callers_own_note(): void
    {
        $this->seedNote(1, userId: 7, title: 'Mine');
        $this->seedNote(2, userId: 8, title: 'Theirs');

        $this->service->update(1, 'Renamed', 'Body', 7);

        $this->assertSame('Renamed', $this->repository->find(1)?->title);
        $this->assertSame('Theirs', $this->repository->find(2)?->title);
    }

    // ---------------------------------------------------------------------- find

    public function test_find_returns_the_callers_note(): void
    {
        $this->seedNote(1, userId: 7, title: 'Mine');

        $result = $this->service->find(1, 7);

        $this->assertTrue($result->isOk());
        $this->assertInstanceOf(NoteEntity::class, $result->value());
    }

    public function test_find_returns_not_found_for_another_users_note(): void
    {
        $this->seedNote(1, userId: 7);

        $result = $this->service->find(1, 8);

        $this->assertTrue($result->isFailure());
        $this->assertSame(404, $result->status());
    }

    public function test_find_returns_not_found_for_an_absent_note(): void
    {
        $this->assertSame(404, $this->service->find(999, 7)->status());
    }

    // --------------------------------------------------------------------- list

    /**
     * A listing cannot fail in a way the user caused, so it is not wrapped in a
     * `Result`. Wrapping a page that always exists would train callers to skip the
     * check that matters elsewhere.
     */
    public function test_list_returns_a_paginated_result_not_a_result(): void
    {
        $this->seedNote(1, userId: 7);

        $this->assertInstanceOf(PaginatedResult::class, $this->service->listForUser(7));
    }

    public function test_list_returns_only_the_callers_notes(): void
    {
        $this->seedNote(1, userId: 7);
        $this->seedNote(2, userId: 8);
        $this->seedNote(3, userId: 7);

        $page = $this->service->listForUser(7);

        $this->assertSame(2, $page->total());
        $this->assertCount(2, $page->items());
    }

    public function test_an_empty_list_is_an_empty_page_not_a_failure(): void
    {
        $page = $this->service->listForUser(7);

        $this->assertTrue($page->isEmpty());
        $this->assertSame(0, $page->total());
        $this->assertSame(1, $page->lastPage());
    }

    public function test_list_paginates(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->seedNote($i, userId: 7);
        }

        $first = $this->service->listForUser(7, perPage: 2, page: 1);
        $third = $this->service->listForUser(7, perPage: 2, page: 3);

        $this->assertSame(5, $first->total());
        $this->assertSame(3, $first->lastPage());
        $this->assertCount(2, $first->items());
        $this->assertCount(1, $third->items());
        $this->assertFalse($third->hasMorePages());
    }

    /**
     * The page size is clamped before it reaches storage, because a repository may
     * build its LIMIT from these arguments before any value object exists — and a
     * negative OFFSET is rejected outright by MySQL and silently read as zero by
     * SQLite, so the two backends would disagree about the same request.
     */
    public function test_list_clamps_a_non_positive_page_size(): void
    {
        $this->seedNote(1, userId: 7);

        $this->assertSame(1, $this->service->listForUser(7, perPage: 0)->perPage());
        $this->assertSame(1, $this->service->listForUser(7, perPage: -10)->perPage());
    }

    public function test_list_clamps_a_page_below_one(): void
    {
        $this->seedNote(1, userId: 7);

        $this->assertSame(1, $this->service->listForUser(7, page: -4)->currentPage());
    }

    public function test_list_caps_an_enormous_page_size(): void
    {
        $this->seedNote(1, userId: 7);

        $this->assertSame(
            PaginatedResult::MAX_PER_PAGE,
            $this->service->listForUser(7, perPage: 10000000)->perPage()
        );
    }

    public function test_list_uses_the_default_page_size(): void
    {
        $this->assertSame(NoteService::DEFAULT_PER_PAGE, $this->service->listForUser(7)->perPage());
    }

    // ------------------------------------------------------------------- delete

    public function test_delete_removes_the_callers_note(): void
    {
        $this->seedNote(1, userId: 7);

        $result = $this->service->delete(1, 7);

        $this->assertTrue($result->isOk());
        $this->assertNull($this->repository->find(1));
        $this->assertSame(0, $this->repository->count());
    }

    /**
     * A delete returns no value: the note it removed no longer exists, and handing
     * the caller an entity whose id points at a deleted row invites a later write
     * against it.
     */
    public function test_delete_returns_a_result_carrying_no_value(): void
    {
        $this->seedNote(1, userId: 7);

        $this->assertNull($this->service->delete(1, 7)->value());
    }

    public function test_delete_refuses_another_users_note(): void
    {
        $this->seedNote(1, userId: 7);

        $result = $this->service->delete(1, 8);

        $this->assertTrue($result->isFailure());
        $this->assertSame(404, $result->status());
        $this->assertNotNull($this->repository->find(1), 'The note must survive.');
    }

    public function test_delete_reports_not_found_for_an_absent_note(): void
    {
        $this->assertSame(404, $this->service->delete(999, 7)->status());
    }

    public function test_delete_nests_the_audit_write(): void
    {
        $this->seedNote(1, userId: 7);

        $this->service->delete(1, 7);

        $this->assertSame(2, $this->transactions->maxDepth);
        $this->assertSame(1, $this->transactions->opened);
    }

    /**
     * A retried delete — a double-submit, a client retrying after a timeout — is
     * reported as a failure only because the note is gone, and the second attempt
     * achieves what the caller wanted. This asserts the *first* attempt is a plain
     * success and leaves nothing behind for the second to trip over.
     */
    public function test_delete_is_idempotent_at_the_storage_layer(): void
    {
        $this->seedNote(1, userId: 7);

        $this->assertTrue($this->service->delete(1, 7)->isOk());

        // The second call cannot find the note to authorize against, so it is a
        // 404 — the row is already gone, which is the outcome requested.
        $this->assertSame(404, $this->service->delete(1, 7)->status());
    }

    // --------------------------------------------------------- the architectural rule

    /**
     * The blanket version of the rule, asserted over every public method rather
     * than case by case: on a user-caused failure nothing here throws, and nothing
     * returns a boolean.
     *
     * A boolean return is what forces a caller to invent the reason for a failure,
     * and an invented reason is how the same rejection ends up as a 400 on one
     * surface and a 422 on another.
     */
    public function test_no_public_method_returns_a_boolean_or_throws_for_user_caused_failure(): void
    {
        $outcomes = [
            'create, empty title' => $this->service->create('', 'Body', 7),
            'create, no owner' => $this->service->create('Title', 'Body', 0),
            'update, absent' => $this->service->update(999, 'T', 'B', 7),
            'update, bad title' => $this->service->update(1, '', 'B', 7),
            'find, absent' => $this->service->find(999, 7),
            'delete, absent' => $this->service->delete(999, 7),
        ];

        foreach ($outcomes as $label => $outcome) {
            $this->assertIsNotBool($outcome, "{$label} must return a Result, not a boolean.");
            $this->assertInstanceOf(Result::class, $outcome, "{$label} must return a Result.");
            $this->assertTrue($outcome->isFailure(), "{$label} is expected to fail.");
            $this->assertNotSame('', $outcome->message(), "{$label} must explain itself.");
            $this->assertGreaterThanOrEqual(400, $outcome->status(), "{$label} needs a client-error status.");
        }
    }

    /**
     * The successful paths return a `Result` too — including the one that returns
     * nothing. A delete that returned `true` would be the single boolean in an
     * otherwise uniform interface, and every caller would have to remember which.
     */
    public function test_successful_outcomes_are_results_as_well(): void
    {
        $this->seedNote(1, userId: 7);

        $this->assertInstanceOf(Result::class, $this->service->create('T', 'B', 7));
        $this->assertInstanceOf(Result::class, $this->service->find(1, 7));
        $this->assertInstanceOf(Result::class, $this->service->delete(1, 7));
    }
}
