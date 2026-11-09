<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Entities;

use Core\Lead\Domain\Exceptions\InvalidLeadException;

/**
 * A quick note attached to a lead, and the rules it enforces on itself.
 *
 * Immutable: a state change returns a new instance and leaves the original
 * untouched, so a rolled-back write cannot leave a mutated entity behind.
 */
readonly class LeadNoteEntity
{
    /**
     * Longest note the UI is expected to hold. Guards against an empty note
     * and a runaway paste; the column itself is TEXT, so this is a rule about
     * what a note IS, not a mirror of the column.
     */
    private const MAX_BODY_LENGTH = 2000;

    /**
     * @param int         $id        Identity of a stored note.
     * @param int         $leadId    The lead the note hangs off.
     * @param string      $body      The note text.
     * @param bool        $isPinned  Whether the note sorts above the rest.
     * @param string|null $createdAt Stored UTC wall-clock string.
     * @param string|null $updatedAt Stored UTC wall-clock string.
     */
    private function __construct(
        private ?int $id,
        private int $leadId,
        private string $body,
        private bool $isPinned,
        private ?string $createdAt = null,
        private ?string $updatedAt = null,
    ) {}

    /**
     * Creates a new note for a lead.
     *
     * A new note starts unpinned: pinning is a deliberate act on a note that
     * already exists, not something chosen while writing it.
     *
     * @param int    $leadId
     * @param string $body
     *
     * @return self
     *
     * @throws InvalidLeadException When the body is empty or too long.
     */
    public static function create(int $leadId, string $body): self
    {
        return new self(
            id: null,
            leadId: $leadId,
            body: self::assertUsableBody($body),
            isPinned: false,
        );
    }

    /**
     * Rebuilds a note from storage.
     *
     * @param int         $id
     * @param int         $leadId
     * @param string      $body
     * @param bool        $isPinned
     * @param string|null $createdAt
     * @param string|null $updatedAt
     *
     * @return self
     *
     * @throws InvalidLeadException When the stored body is empty or too long.
     */
    public static function reconstitute(
        int $id,
        int $leadId,
        string $body,
        bool $isPinned,
        ?string $createdAt = null,
        ?string $updatedAt = null,
    ): self {
        return new self(
            id: $id,
            leadId: $leadId,
            body: self::assertUsableBody($body),
            isPinned: $isPinned,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    /**
     * Returns a pinned copy of this note.
     *
     * Pinning a note that is already pinned is not an error - the caller asked
     * for a state the note is already in, so it is returned unchanged.
     *
     * @return self
     */
    public function pinned(): self
    {
        if ($this->isPinned) {
            return $this;
        }

        return $this->withPinned(true);
    }

    /**
     * Returns an unpinned copy of this note.
     *
     * @return self
     */
    public function unpinned(): self
    {
        if (! $this->isPinned) {
            return $this;
        }

        return $this->withPinned(false);
    }

    /**
     * Returns a copy carrying the given pin state.
     *
     * @param bool $isPinned
     *
     * @return self
     */
    private function withPinned(bool $isPinned): self
    {
        return new self(
            id: $this->id,
            leadId: $this->leadId,
            body: $this->body,
            isPinned: $isPinned,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
        );
    }

    /**
     * Returns the note's identity, or null when it has not been stored yet.
     *
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Returns the id of the lead this note belongs to.
     *
     * @return int
     */
    public function getLeadId(): int
    {
        return $this->leadId;
    }

    /**
     * Returns the note text.
     *
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Reports whether the note sorts above the unpinned ones.
     *
     * @return bool
     */
    public function isPinned(): bool
    {
        return $this->isPinned;
    }

    /**
     * Returns when the note was stored, or null before it is.
     *
     * @return string|null
     */
    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * Returns when the note was last written, or null before it is.
     *
     * @return string|null
     */
    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * Rejects a body that carries no text or exceeds the note length.
     *
     * @param string $body
     *
     * @return string The trimmed body.
     *
     * @throws InvalidLeadException When the body is empty or too long.
     */
    private static function assertUsableBody(string $body): string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            throw new InvalidLeadException(
                message: 'A note must carry some text.',
                translationKey: 'leads.errors.note_empty',
            );
        }

        if (mb_strlen($trimmed) > self::MAX_BODY_LENGTH) {
            throw new InvalidLeadException(
                message: sprintf('A note may be at most %d characters.', self::MAX_BODY_LENGTH),
                translationKey: 'leads.errors.note_too_long',
                replace: ['max' => (string) self::MAX_BODY_LENGTH],
            );
        }

        return $trimmed;
    }
}
