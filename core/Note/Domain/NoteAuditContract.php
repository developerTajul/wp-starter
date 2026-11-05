<?php
declare(strict_types=1);

namespace Core\Note\Domain;

/**
 * Where the record of what happened to a note goes.
 *
 * ## Why the domain declares this rather than the application
 *
 * An audit entry is a statement about the business: this note was created by
 * this user at this moment. The *shape* of that statement therefore belongs in
 * the domain, next to {@see NoteEntity}, and not in the application layer that
 * happens to be the only caller today. A second caller — a console command
 * importing notes, a scheduled job expiring them — records the same kind of
 * event, and it should describe it with the same vocabulary rather than invent
 * a second one.
 *
 * It is a port in the same sense as {@see NoteRepositoryContract}: `core/` names
 * the act and `app/` decides that the record lands in a MySQL table, a log file,
 * or somewhere else entirely. Nothing here mentions a table, a column, or a
 * database handle.
 *
 * ## Why this is a separate contract from the repository
 *
 * Folding `record()` onto {@see NoteRepositoryContract} would be fewer moving
 * parts and it would be wrong, because the two have genuinely different
 * lifetimes and audiences. A repository is the note's storage: it reads and
 * writes the row a user is looking at, and a test that swaps it swaps *what the
 * feature does*. An audit sink is append-only history, written on the same path
 * but never read back by a use case, and an implementation is free to be a log
 * line or an external service. A project that wants to keep notes in MySQL and
 * audits in a log file can then say so, which one contract could not express.
 *
 * ## Why the entry is a value and not a set of arguments
 *
 * {@see NoteAuditEntry} carries the note, the event, and the moment. Passing
 * three loose arguments would let a call site transpose two of them — both are
 * strings on the wire — and an audit trail with an event name where the user id
 * belongs is worse than no trail, because it looks authoritative. A value object
 * makes the mistake a type error.
 *
 * ## What implementations must not do
 *
 * Throw for an ordinary outcome, but do throw for a real failure. An audit write
 * that silently swallows a database error produces a trail with holes in it that
 * nothing reports — the one failure mode an audit trail cannot survive, since
 * the absence of a record is indistinguishable from the absence of an event. A
 * write that genuinely cannot be stored must raise, so the surrounding
 * transaction rolls back and the operation it describes does not happen either.
 */
interface NoteAuditContract
{
    /**
     * Append one entry to the trail.
     *
     * Called from inside the transaction that performs the change, which is the
     * property the whole method exists for: an entry that committed while the
     * change it describes rolled back would be a trail that actively lies. An
     * implementation therefore does no transaction management of its own — it
     * joins whatever unit of work is already open.
     *
     * Returns nothing. A caller that wanted to know whether the write happened
     * would be a caller deciding what to do about a failure, and there is nothing
     * useful to do at that point except let it propagate.
     */
    public function record(NoteAuditEntry $entry): void;

    /**
     * Every entry recorded against one note, oldest first.
     *
     * ## Why a read lives on a write port
     *
     * It is the only way to test the write. A `record()` with no way to read back
     * is unverifiable: the transaction-depth tests would pass against a method
     * whose body was `return;`, which is precisely the state this contract was
     * introduced to fix.
     *
     * The alternative — leaving reads to a separate query class — means the
     * writing side is tested by asserting on a *mock* of this interface, which
     * proves the call was made and nothing about whether the entry was stored.
     * One method here makes the fake and the real implementation answer the same
     * question the same way.
     *
     * It is deliberately narrow: one note, ordered, no pagination, no filtering.
     * An audit trail's real query surface — "what did this user do last week" —
     * is a reporting concern, and guessing at it here would put a query builder
     * in the domain to serve a screen that does not exist.
     *
     * @return list<NoteAuditEntry>
     */
    public function forNote(int $noteId): array;
}
