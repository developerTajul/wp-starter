<?php
declare(strict_types=1);

namespace Core\Note\Domain;

use Core\Shared\PaginatedResult;

/**
 * What the application layer needs from note storage, and nothing about how.
 *
 * ## What a port is for
 *
 * This interface is the seam that makes the note module testable without a
 * database and portable without a rewrite. `NoteService` depends on *this*, so a
 * service test supplies an in-memory implementation and runs in microseconds
 * with no surrounding application at all — the same property that lets `core/` be
 * verified without a stub layer.
 *
 * It is also the reason `core/` never names a table, a column, or a query
 * builder. Every method below is phrased in entities and ids, which is the
 * vocabulary the business uses. The implementation that knows about a prefixed
 * table name, a database handle, and schema-diffing lives in `app/`, where the
 * framework lives.
 *
 * ## Why the signatures return what they do
 *
 * None of these throw. A record that is absent is an ordinary outcome, and a
 * `find()` that threw would make every caller wrap every lookup in a try/catch
 * to handle a case that is not exceptional. `null` says "there is no such note"
 * in the one place the type can express it.
 *
 * `save()` returns the entity rather than the id or a bool, because storage is
 * allowed to change what it stores: an auto-increment id is assigned on insert,
 * and a trigger or a column default may set a timestamp. Returning the entity
 * that was actually written is what keeps the caller's copy and the stored row
 * from diverging the moment one of those exists.
 *
 * ## What is deliberately not here
 *
 * No `update()`, no `delete()` by id, no `findByTitle()`, no raw query escape
 * hatch. Each method here is one the application layer genuinely calls; an
 * interface that guessed at the rest would be a wider contract for every
 * implementation to satisfy, and the guess is what makes a second driver
 * expensive to write. Adding a method when a use case needs it is cheap; removing
 * one that three implementations already wrote is not.
 */
interface NoteRepositoryContract
{
    /**
     * The note with this id, or null when there is none.
     *
     * Null rather than a thrown not-found: "absent" is a state the caller has to
     * handle either way, and a throw makes it slower to handle and easy to forget.
     * `NoteService` is what turns this null into a `Result::notFound()`, so the
     * decision about how absence is *reported* stays in the application layer
     * where the transport is known.
     */
    public function find(int $id): ?NoteEntity;

    /**
     * The note with this id, but only if it belongs to this user.
     *
     * ## Why this is a repository method and not a check in the service
     *
     * The obvious alternative — `find($id)` then `isOwnedBy($userId)` — reads the
     * row first and decides afterwards, which is a check that happens *after* the
     * data has been read. It works, and it is one careless refactor away from
     * being skipped. Pushing the predicate into the query means an unauthorized
     * read never loads the row at all.
     *
     * It also lets the implementation be right about a detail the service would
     * get wrong: a soft-deleted note, or one in another site on a multisite
     * install, may not be readable by a user who would otherwise match on owner
     * id. That is a storage concern, and the port is where it belongs.
     *
     * Returns null for "not yours" as well as "does not exist", deliberately. The
     * caller cannot distinguish the two, which is what stops a 403 from
     * confirming that a note with that id exists — an enumeration leak that a
     * `forbidden()` here would open.
     */
    public function findOwnedBy(int $id, int $userId): ?NoteEntity;

    /**
     * A page of one user's notes, newest first.
     *
     * ## Why ownership is a parameter rather than optional
     *
     * A `paginate()` without an owner would list every note in the table, and the
     * one place it would be called from is an endpoint that must not do that.
     * Making it required means a caller cannot forget it, and a genuinely
     * site-wide listing — an admin screen — is a different method with a
     * different name, so nobody reaches for this one by accident.
     *
     * `self::CONTRACT_MAX_PER_PAGE`-style clamping is the implementation's job,
     * not the caller's: `PaginatedResult` already floors and caps the value, so
     * the two cannot disagree about what the page was.
     *
     * @return PaginatedResult<NoteEntity>
     */
    public function paginateForUser(int $userId, int $perPage, int $page): PaginatedResult;

    /**
     * Store the note, inserting or updating depending on whether it has an id.
     *
     * ## Why one method and not insert() and update()
     *
     * The caller has an entity and wants it stored. It does not want to know
     * which SQL that becomes, and — importantly — it should not have to decide
     * correctly, because the decision is already recorded: {@see NoteEntity::exists()}
     * is true exactly when the note has an id, and an id is assigned exactly once,
     * by this method's return value. Splitting the method would let a caller
     * INSERT a note that already exists, and the duplicate is silent.
     *
     * The returned entity carries the assigned id on insert. An implementation
     * that cannot determine it must fail loudly rather than return the entity
     * unchanged, because an entity with no id is one that `exists()` says is not
     * stored, and every later save would insert another copy.
     */
    public function save(NoteEntity $note): NoteEntity;

    /**
     * Remove the note permanently.
     *
     * `true` when a row was removed and `false` when there was nothing to remove.
     * Deliberately not an exception for the second case: a delete of something
     * already gone has achieved what the caller wanted, and the boolean is what
     * lets `NoteService` report that as a success rather than as a failure the
     * user cannot act on.
     *
     * Takes an id rather than the entity because a caller deleting a note often
     * has only the id — a form post, a REST path parameter — and building an
     * entity just to delete it would mean fabricating fields the deletion does
     * not use.
     */
    public function delete(int $id): bool;
}
