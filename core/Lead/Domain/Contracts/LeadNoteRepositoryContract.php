<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Contracts;

use Core\Lead\Domain\Entities\LeadNoteEntity;

/**
 * Write side of the note persistence port. Writes only: the one read it
 * declares is the row lock taken immediately before a pin change.
 */
interface LeadNoteRepositoryContract
{
    /**
     * Stores a new note and returns it as a Domain Entity.
     *
     * @param LeadNoteEntity $note
     *
     * @return LeadNoteEntity The stored note, carrying its assigned id.
     */
    public function create(LeadNoteEntity $note): LeadNoteEntity;

    /**
     * Reads a note for update, holding a row lock until the transaction ends.
     *
     * @param int $id
     *
     * @return LeadNoteEntity|null
     */
    public function findByIdForUpdate(int $id): ?LeadNoteEntity;

    /**
     * Persists the note's pin state and returns the stored row.
     *
     * @param LeadNoteEntity $note
     *
     * @return LeadNoteEntity
     */
    public function savePinState(LeadNoteEntity $note): LeadNoteEntity;
}
