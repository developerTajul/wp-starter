<?php
declare(strict_types=1);

namespace Tests\Unit\Core\Note\Fixtures;

use Core\Note\Domain\NoteAuditContract;
use Core\Note\Domain\NoteAuditEntry;

/**
 * An audit sink that keeps what it was given, in memory.
 *
 * ## What it has to get right
 *
 * The review finding this fixture exists to answer: `NoteService::recordAudit()`
 * used to evaluate a boolean and store nothing, and the tests around it asserted
 * on the *transaction depth* only. Those assertions were true and proved nothing
 * — a method whose body was `return;` passed them, because there was no write to
 * observe.
 *
 * So this fake does not merely accept a call; it **records the entry**, and the
 * tests assert on the entries rather than on the call count. A count would pass
 * against a service that constructed an entry and dropped it, which is the same
 * shape of bug one layer down.
 *
 * ## Why the transaction depth is captured per entry
 *
 * The service's contract is not "an entry is written" but "an entry is written
 * *inside* the transaction that performs the change". Those are different claims,
 * and only the second one is the guarantee the audit trail exists for — an entry
 * that committed while the change rolled back is a trail that lies.
 *
 * A sink cannot see the manager, so it is handed a depth probe instead: a
 * callable the test wires to the same manager the service holds. Each entry
 * records the depth it was written at, which is what makes "inside the outer
 * frame" assertable rather than assumed.
 */
final class RecordingNoteAudit implements NoteAuditContract
{
    /**
     * Every entry recorded, in order.
     *
     * @var list<NoteAuditEntry>
     */
    public array $entries = [];

    /**
     * The transaction depth each entry was written at, in the same order.
     *
     * @var list<int>
     */
    public array $depths = [];

    /**
     * Set to a throwable to make {@see record()} fail, which is how a sink that
     * cannot reach its storage is simulated.
     */
    public ?\Throwable $failWith = null;

    /**
     * @param callable(): int|null $depth A probe for the transaction depth at the
     *                                    moment of the write. Null means "not
     *                                    observing", and the depth reads as 0 —
     *                                    which is what a test that only cares
     *                                    whether the write happened wants.
     */
    public function __construct(private $depth = null)
    {
    }

    public function record(NoteAuditEntry $entry): void
    {
        if ($this->failWith !== null) {
            $failure = $this->failWith;
            $this->failWith = null;

            throw $failure;
        }

        $this->entries[] = $entry;
        $this->depths[] = $this->depth === null ? 0 : ($this->depth)();
    }

    /**
     * @return list<NoteAuditEntry>
     */
    public function forNote(int $noteId): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (NoteAuditEntry $entry): bool => $entry->noteId === $noteId
        ));
    }

    /**
     * The event names recorded, in order.
     *
     * @return list<string>
     */
    public function events(): array
    {
        return array_map(
            static fn (NoteAuditEntry $entry): string => $entry->event,
            $this->entries
        );
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
