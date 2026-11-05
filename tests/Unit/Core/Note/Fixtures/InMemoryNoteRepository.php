<?php
declare(strict_types=1);

namespace Tests\Unit\Core\Note\Fixtures;

use Core\Note\Domain\NoteEntity;
use Core\Note\Domain\NoteRepositoryContract;
use Core\Shared\PaginatedResult;

/**
 * A note repository that lives in an array.
 *
 * ## Why this is a double and not a mock
 *
 * A mock would assert *how* the service talks to storage — that `save()` was
 * called once with a note whose title is "x" — and every one of those assertions
 * is a test that breaks when the service is refactored without changing what it
 * does. What these tests need to know is what the caller gets back and what ended
 * up stored, which is the contract, so this implements the contract and the
 * assertions read the resulting state.
 *
 * ## Why it enforces the swap, and not just the happy path
 *
 * Two behaviours here are deliberately stricter than a naive fake:
 *
 *  - {@see save()} refuses a note that is not owned by the id already stored
 *    under that id. A fake that silently overwrote would let a service bug that
 *    edits the wrong user's note pass its tests.
 *  - {@see findOwnedBy()} returns null for "not yours" *and* "absent". That is
 *    the contract, and a fake that returned the entity and let the service filter
 *    would hide a service that forgot to check ownership — which is the single
 *    most security-relevant thing this module does.
 */
final class InMemoryNoteRepository implements NoteRepositoryContract
{
    /**
     * @var array<int, NoteEntity>
     */
    private array $rows = [];

    private int $nextId = 1;

    /**
     * How many times each method was called, so a test can assert that a use case
     * did not touch storage when it should not have.
     *
     * @var array<string, int>
     */
    public array $calls = [
        'find' => 0,
        'findOwnedBy' => 0,
        'paginateForUser' => 0,
        'save' => 0,
        'delete' => 0,
    ];

    /**
     * Set to a throwable to make the next {@see save()} fail, which is how the
     * service's infrastructure-failure path is exercised.
     */
    public ?\Throwable $failSaveWith = null;

    /**
     * Seed a stored row directly, bypassing the id counter, so a test can fix the
     * ids it asserts on.
     */
    public function seed(NoteEntity $note): NoteEntity
    {
        if ($note->id === null) {
            throw new \LogicException('Seeded notes must carry an id; use create() for an unsaved one.');
        }

        $this->rows[$note->id] = $note;
        $this->nextId = max($this->nextId, $note->id + 1);

        return $note;
    }

    public function all(): array
    {
        return $this->rows;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function find(int $id): ?NoteEntity
    {
        $this->calls['find']++;

        return $this->rows[$id] ?? null;
    }

    public function findOwnedBy(int $id, int $userId): ?NoteEntity
    {
        $this->calls['findOwnedBy']++;

        $note = $this->rows[$id] ?? null;

        // Null for "someone else's", exactly as the contract requires. The
        // service must not be able to tell "not yours" from "does not exist", or
        // a 403 would confirm the existence of a note the caller may not see.
        return $note !== null && $note->isOwnedBy($userId) ? $note : null;
    }

    public function paginateForUser(int $userId, int $perPage, int $page): PaginatedResult
    {
        $this->calls['paginateForUser']++;

        $mine = array_values(array_filter(
            $this->rows,
            static fn (NoteEntity $note): bool => $note->isOwnedBy($userId)
        ));

        // Newest first, which is the order the contract promises and the order a
        // test asserting on "the first page" depends on.
        usort($mine, static fn (NoteEntity $a, NoteEntity $b): int => ($b->id ?? 0) <=> ($a->id ?? 0));

        $total = count($mine);
        $offset = ($page - 1) * $perPage;

        return PaginatedResult::fromSlice(
            array_slice($mine, $offset, $perPage),
            $total,
            $perPage,
            $page
        );
    }

    public function save(NoteEntity $note): NoteEntity
    {
        $this->calls['save']++;

        if ($this->failSaveWith !== null) {
            $failure = $this->failSaveWith;
            $this->failSaveWith = null;

            throw $failure;
        }

        if ($note->id === null) {
            // The insert path: storage assigns the id, which is the whole reason
            // `save()` returns an entity rather than a boolean.
            $stored = $note->withId($this->nextId)->value();
            /** @var NoteEntity $stored */
            $this->rows[$this->nextId] = $stored;
            $this->nextId++;

            return $stored;
        }

        if (isset($this->rows[$note->id]) && !$this->rows[$note->id]->isOwnedBy($note->userId)) {
            throw new \RuntimeException(
                'Refusing to overwrite a note owned by another user; a service that reached this '
                . 'line has edited a record it does not own.'
            );
        }

        $this->rows[$note->id] = $note;

        return $note;
    }

    public function delete(int $id): bool
    {
        $this->calls['delete']++;

        if (!isset($this->rows[$id])) {
            return false;
        }

        unset($this->rows[$id]);

        return true;
    }
}
