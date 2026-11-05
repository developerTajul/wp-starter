<?php
declare(strict_types=1);

namespace Core\Note\Domain;

/**
 * One thing that happened to one note, and who made it happen.
 *
 * ## Why this is not the audit table row
 *
 * The same reason {@see NoteEntity} is not the notes row. A table will grow
 * columns this does not have — a request id, an IP, a serialized payload of the
 * before and after states — and none of them change what an audit entry *is*.
 * The repository maps one to the other, and a column added tomorrow is invisible
 * here until something reasons about it.
 *
 * ## Why the event is a string and not an enum
 *
 * Tempting, and wrong for this layer. An enum in `core/` is a closed list, and
 * closing it means every project that wants to record an event this module never
 * anticipated — "exported", "shared", "merged" — edits the domain to do it. The
 * domain would then accumulate event names it does not itself produce.
 *
 * The events this module *does* produce are named as constants below, which is
 * what a caller needs: an autocompletable, greppable set of the spellings that
 * are known to be correct, without forbidding the ones that are merely new.
 *
 * ## Why it carries the note rather than just its id
 *
 * On the insert path there is no id yet — the entry is written before the row is
 * guaranteed to exist — so a constructor taking an `int` could not represent the
 * most important event in the trail. Taking the entity also lets the entry carry
 * the note's owner, which is the field an audit trail is actually queried by.
 *
 * It is read-only and it copies what it needs at construction. Holding the entity
 * itself would mean an entry that changes when the note does, which is the one
 * thing an audit record must never do.
 */
final readonly class NoteAuditEntry
{
    /**
     * A note was created.
     */
    public const CREATED = 'created';

    /**
     * A note's title or content changed.
     */
    public const UPDATED = 'updated';

    /**
     * A note was removed.
     */
    public const DELETED = 'deleted';

    /**
     * The events this module records, for a caller that wants to enumerate them.
     *
     * Not a restriction — see the class docblock. A project records its own
     * events by passing its own string.
     *
     * @return list<string>
     */
    public static function knownEvents(): array
    {
        return [self::CREATED, self::UPDATED, self::DELETED];
    }

    /**
     * @param int|null $noteId The note's id, or null when the note has not been
     *                         stored yet — which is the case on the create path,
     *                         where the entry is written before the row exists.
     * @param int      $userId The note's owner at the time of the event. Copied
     *                         rather than read back from the entity later, so a
     *                         reassignment does not rewrite history.
     * @param string   $event  What happened. One of {@see knownEvents()} for
     *                         events this module raises; any string otherwise.
     */
    private function __construct(
        public ?int $noteId,
        public int $userId,
        public string $event,
        public \DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * Record what just happened to a note.
     *
     * The single constructor, because there is only ever one way to make an
     * entry: from the entity the event is about. `$occurredAt` is a parameter
     * with a default rather than a `new DateTimeImmutable('now')` inside, so a
     * test can place an entry at a known moment — and so the same call site
     * cannot produce two entries a microsecond apart that a reader then has to
     * order by something other than their timestamps.
     *
     * An empty event is refused. It is the one input that produces a
     * structurally valid entry with no content, and it would be indistinguishable
     * from a bug in the reader.
     */
    public static function record(
        NoteEntity $note,
        string $event,
        ?\DateTimeImmutable $occurredAt = null
    ): self {
        if (trim($event) === '') {
            throw new \InvalidArgumentException(
                'An audit entry needs an event name. An empty one is a row that reads as an event '
                . 'but says nothing, which is indistinguishable from a broken reader.'
            );
        }

        return new self(
            $note->id,
            $note->userId,
            $event,
            $occurredAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    /**
     * Rebuild an entry read back out of storage.
     *
     * The mirror of {@see NoteEntity::reconstitute()}, and separate for the same
     * reason: a row that came out of the database was validated on the way in,
     * and re-running the caller-facing rules over it would reject historical
     * entries that predate a rule added since.
     */
    public static function reconstitute(
        ?int $noteId,
        int $userId,
        string $event,
        \DateTimeImmutable $occurredAt
    ): self {
        return new self($noteId, $userId, $event, $occurredAt);
    }
}
