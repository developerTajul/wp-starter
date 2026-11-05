<?php
declare(strict_types=1);

namespace Core\Note\Application;

use Core\Note\Domain\NoteAuditContract;
use Core\Note\Domain\NoteAuditEntry;
use Core\Note\Domain\NoteEntity;
use Core\Note\Domain\NoteRepositoryContract;
use Core\Shared\Contracts\TransactionManagerContract;
use Core\Shared\PaginatedResult;
use Core\Shared\Result;
use Throwable;

/**
 * The note use cases: everything that can be done *to* a note, and what may stop it.
 *
 * ## The rule this class exists to obey
 *
 * Every public method returns a `Result` or a `PaginatedResult`. None returns a
 * boolean, and none throws for an outcome the user could have caused — a missing
 * note, a title too long, a note belonging to someone else. Those are ordinary
 * results of asking, and they are returned as values carrying their reason.
 *
 * That is a deliberate constraint, not a style: a `bool` return is what forces a
 * caller to invent the reason, and an invented reason is how the same failure
 * ends up as a 404 on one route and a 403 on another. The service states what
 * happened once, in one type, and every surface that renders it — REST, admin
 * form, console — reads the same answer.
 *
 * Throwing is still correct here for the things that genuinely are programming
 * errors: a transaction manager that cannot open a connection, a repository that
 * violates its contract. Those are not outcomes a user caused and there is no
 * message to show them. `transaction()` re-throws what the callback threw, and
 * that is deliberate — see {@see create()}.
 *
 * ## Authorization lives here, not in a middleware
 *
 * Whether *this* user may touch *this* note is a question about the note and the
 * user, so it is answered where both are in hand. The middleware in `app/` asks
 * "is this person allowed to use notes at all", which is a different and wider
 * question; that one cannot know which note the request will reach, because at
 * that point the route parameter has not been resolved into an entity.
 *
 * Both checks are needed and neither substitutes for the other. A capability
 * check that passes says nothing about ownership.
 *
 * ## Why the dependencies are interfaces
 *
 * `NoteService` depends on two ports and knows no table, no database handle, and
 * no framework class. A test constructs it with an in-memory repository and a
 * transaction manager that counts its calls, and runs with nothing else loaded —
 * which is what makes the isolation rule verifiable rather than asserted.
 */
final class NoteService
{
    /**
     * The page size used when a caller does not choose one.
     *
     * Small, because the caller that omits it is usually a view rendering a list,
     * and a large default is how a first page load turns into a slow query. A
     * client that wants more says so.
     */
    public const DEFAULT_PER_PAGE = 15;

    public function __construct(
        private readonly NoteRepositoryContract $repository,
        private readonly TransactionManagerContract $transactions,
        private readonly NoteAuditContract $audit,
    ) {
    }

    /**
     * Create a note for a user, and record that it happened.
     *
     * ## The nested transaction
     *
     * Note what is *not* here: no `begin()`, and no transaction wrapped around
     * {@see recordAudit()} separately. The audit write happens inside the same
     * unit of work as the insert, because a note that exists with no record of
     * who created it is exactly the state the transaction is there to prevent.
     *
     * `recordAudit()` opens a *nested* transaction of its own — deliberately, to
     * exercise the depth rule the contract describes. On this path that nested
     * call joins the outer one rather than opening a second: it must not be able
     * to commit the insert below it, or a rollback of the outer unit would find
     * the note already durable.
     *
     * ## Why the entity validation happens before the transaction
     *
     * {@see NoteEntity::create()} can fail on input the user supplied, and there
     * is nothing to roll back — opening a transaction to discover that a title is
     * too long would take a write lock to answer a question about a string.
     * Failures that need no storage are answered before storage is touched.
     *
     * ## Why `Throwable` is caught and returned rather than allowed to escape
     *
     * The repository and the transaction manager are infrastructure, and an
     * infrastructure failure is not something the user caused — but it is also
     * not something this layer can do anything about, and there is no message in
     * it for the user. Letting it escape as a 500 with a stack trace is the
     * conventional answer.
     *
     * It is caught here so the *message* names the operation, and re-thrown as a
     * `RuntimeException` with the original as its cause. The distinction matters:
     * an unhandled store failure surfacing as "SQLSTATE[HY000]: General error:
     * 2006 MySQL server has gone away" through a REST response is a slow
     * information leak about the schema, and it is the same leak whatever
     * endpoint it came from.
     *
     * @return Result The created note on success; a validation failure if the
     *                input cannot make one.
     */
    public function create(string $title, string $content, int $userId): Result
    {
        // Domain validation first, with no storage involved. `create()` is the
        // entity's own rule set, and asking it rather than restating the limits
        // here is what keeps the two from drifting.
        $built = NoteEntity::create($title, $content, $userId);

        if ($built->isFailure()) {
            return $built;
        }

        /** @var NoteEntity $note */
        $note = $built->value();

        try {
            /** @var NoteEntity $saved */
            $saved = $this->transactions->transaction(function () use ($note): NoteEntity {
                $stored = $this->repository->save($note);

                // Inside the transaction, deliberately: a note without its audit
                // row is the half-written state the transaction exists to rule
                // out, and putting this after the transaction would make the two
                // independently durable.
                $this->recordAudit($stored, NoteAuditEntry::CREATED);

                return $stored;
            });
        } catch (Throwable $e) {
            throw new \RuntimeException(
                'The note could not be created; nothing was stored.',
                0,
                $e
            );
        }

        return Result::ok($saved);
    }

    /**
     * Change a note's title and content, if this user is allowed to.
     *
     * ## Why ownership is checked by the query and not after it
     *
     * {@see NoteRepositoryContract::findOwnedBy()} answers "is there a note with
     * this id that belongs to this user" in one read. The alternative — load by
     * id, then compare owner ids here — works, but it reads a row the caller is
     * not allowed to see, and the comparison is one deleted `if` away from being
     * skipped.
     *
     * It also means an unauthorized edit and a note that does not exist produce
     * the *same* answer. That is not a loss of information; it is the point. A
     * distinguishing 403 would confirm that a note with that id exists, which is
     * an enumeration leak in a request that was already refused.
     *
     * ## Why the update goes through the entity
     *
     * The new title is applied by {@see NoteEntity::withTitle()}, so a
     * 256-character title is refused by the same rule that refuses it at creation.
     * A service that wrote the string through unchecked would be a second,
     * looser copy of a rule the entity already enforces.
     *
     * @return Result The updated note, `notFound()` when there is no note of this
     *                user's with that id, or a validation failure.
     */
    public function update(int $id, string $title, string $content, int $userId): Result
    {
        $note = $this->repository->findOwnedBy($id, $userId);

        if ($note === null) {
            return Result::notFound('No note with that id belongs to you.');
        }

        $titled = $note->withTitle($title);

        if ($titled->isFailure()) {
            return $titled;
        }

        /** @var NoteEntity $renamed */
        $renamed = $titled->value();

        $bod = $renamed->withContent($content);

        if ($bod->isFailure()) {
            return $bod;
        }

        /** @var NoteEntity $edited */
        $edited = $bod->value();

        // An edit that changed nothing is not written. `withTitle()` and
        // `withContent()` return the same instance when the value is unchanged,
        // so identity is a reliable test for "there is nothing to store" — and
        // skipping it means a no-op save does not bump `updated_at` and make the
        // note look recently touched.
        if ($edited === $note) {
            return Result::ok($note);
        }

        try {
            /** @var NoteEntity $saved */
            $saved = $this->transactions->transaction(function () use ($edited): NoteEntity {
                $stored = $this->repository->save($edited);
                $this->recordAudit($stored, NoteAuditEntry::UPDATED);

                return $stored;
            });
        } catch (Throwable $e) {
            throw new \RuntimeException('The note could not be updated; nothing was changed.', 0, $e);
        }

        return Result::ok($saved);
    }

    /**
     * One note, if this user may read it.
     *
     * @return Result The note, or `notFound()`.
     */
    public function find(int $id, int $userId): Result
    {
        $note = $this->repository->findOwnedBy($id, $userId);

        if ($note === null) {
            return Result::notFound('No note with that id belongs to you.');
        }

        return Result::ok($note);
    }

    /**
     * A page of this user's notes.
     *
     * ## Why this returns a `PaginatedResult` and not a `Result` around one
     *
     * Listing cannot fail in a way the user caused: there is no input to reject
     * beyond a page size, and that is clamped rather than refused. Wrapping a
     * page that always exists in a `Result` would force every caller to unwrap
     * something that is never a failure, which is ceremony that trains callers to
     * skip the check that matters elsewhere.
     *
     * The absence of notes is an empty page, not a failure. "You have no notes"
     * and "I could not find your notes" must not be the same response.
     *
     * @return PaginatedResult<NoteEntity>
     */
    public function listForUser(
        int $userId,
        int $perPage = self::DEFAULT_PER_PAGE,
        int $page = 1
    ): PaginatedResult {
        // Clamped here as well as in PaginatedResult, because a repository may
        // build its own LIMIT from these arguments before the value object exists
        // — and a negative OFFSET is rejected outright by MySQL and silently read
        // as zero by SQLite, so the two backends would disagree about the query.
        $perPage = max(1, min($perPage, PaginatedResult::MAX_PER_PAGE));
        $page = max(1, $page);

        return $this->repository->paginateForUser($userId, $perPage, $page);
    }

    /**
     * Delete one of this user's notes.
     *
     * ## Why a delete that found nothing is a success
     *
     * The caller asked for a note to be gone, and it is gone. Reporting a 404 for
     * a note that was already deleted turns a retried request — a double-submit,
     * a client retrying after a timeout — into an error the user cannot act on,
     * and the retry achieved exactly what was wanted.
     *
     * The existence check is still made, because a delete must be *authorized*,
     * and `findOwnedBy()` is what establishes that this user may remove this
     * note. What it does not do is turn a no-op into a failure.
     *
     * @return Result `ok()` once the note is gone; `notFound()` when it never
     *                existed or belongs to someone else.
     */
    public function delete(int $id, int $userId): Result
    {
        $note = $this->repository->findOwnedBy($id, $userId);

        if ($note === null) {
            return Result::notFound('No note with that id belongs to you.');
        }

        try {
            $this->transactions->transaction(function () use ($note): bool {
                $this->recordAudit($note, NoteAuditEntry::DELETED);

                return $this->repository->delete($note->id ?? 0);
            });
        } catch (Throwable $e) {
            throw new \RuntimeException('The note could not be deleted; nothing was changed.', 0, $e);
        }

        // No value: the note that was deleted no longer exists, and returning the
        // entity would hand the caller an object whose `id` still points at a row
        // that is gone.
        return Result::ok();
    }

    /**
     * Record that something happened to a note.
     *
     * ## Why this opens a transaction of its own
     *
     * This is the nested call the transaction contract describes, and it is here
     * so the depth rule is exercised by real code rather than only by a test. On
     * every path that reaches it there is already an outer transaction open, so
     * the manager must join rather than nest.
     *
     * The nesting is not decoration. An audit write that opened its *own*
     * independent transaction could commit while the surrounding insert was still
     * uncommitted — leaving a durable record of an event that then rolled back.
     * That is worse than no audit trail at all, because it is a trail that
     * actively lies.
     *
     * ## Why it takes the entity rather than an id
     *
     * The audit entry is written before the row is guaranteed to exist on the
     * insert path, so there is no id to pass yet. Taking the entity is also what
     * lets the entry carry the note's owner, which is the field an audit trail is
     * actually queried by.
     *
     * Public rather than private because `app/` has a handler that records
     * non-crud events — an export, a share — and those have to land in the same
     * transaction as the operation they describe.
     *
     * ## What the sink is, and why it is a dependency
     *
     * The entry goes to {@see NoteAuditContract}, which is the port this method
     * was always shaped around. Until the sink existed this body evaluated a
     * boolean and discarded it — a method that opened a transaction, stored
     * nothing, and passed three tests that asserted only on the transaction
     * depth. Those tests were true and proved nothing: the guarantee they claimed
     * to verify ("the audit write is inside the same unit of work as the insert")
     * was unverifiable while there was no write to observe.
     *
     * The entry is built here rather than by the caller so the timestamp and the
     * owner are captured at the moment of the event, not at whatever moment a
     * caller got around to constructing one.
     */
    public function recordAudit(NoteEntity $note, string $event): void
    {
        $entry = NoteAuditEntry::record($note, $event);

        $this->transactions->transaction(function () use ($entry): bool {
            // Inside the transaction, and joining the caller's rather than
            // opening a second — see the class docblock. A write that could commit
            // independently of the change it describes is the failure this whole
            // method is arranged to prevent.
            $this->audit->record($entry);

            return true;
        });
    }
}
