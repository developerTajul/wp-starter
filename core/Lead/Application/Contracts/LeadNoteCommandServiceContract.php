<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Lead\Application\DTOs\Requests\LeadNoteCreateDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * Write surface of the lead note use cases.
 */
interface LeadNoteCommandServiceContract
{
    /**
     * Writes a quick note against a lead.
     *
     * @param LeadNoteCreateDTO $dto
     *
     * @return Result Contains the LeadNoteResponseDTO on success.
     */
    public function addNote(LeadNoteCreateDTO $dto): Result;

    /**
     * Pins or unpins a note.
     *
     * @param int  $noteId
     * @param bool $isPinned
     *
     * @return Result Contains the LeadNoteResponseDTO on success.
     */
    public function setPinned(int $noteId, bool $isPinned): Result;
}
