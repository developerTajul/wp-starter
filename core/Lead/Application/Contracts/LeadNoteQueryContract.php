<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Lead\Domain\ReadModels\LeadNoteProjectionDTO;

/**
 * Read side of the note persistence port. Returns read models for the widget.
 */
interface LeadNoteQueryContract
{
    /**
     * Returns a lead's notes, pinned first and newest first within each group.
     *
     * @param int $leadId
     *
     * @return list<LeadNoteProjectionDTO> Empty when the lead has no notes.
     */
    public function getNotesForLead(int $leadId): array;
}
