<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Shared\Application\DTOs\Result;

/**
 * Read surface of the lead note use cases.
 */
interface LeadNoteQueryServiceContract
{
    /**
     * Returns a lead's notes for the details widget.
     *
     * @param int $leadId
     *
     * @return Result Contains a list of LeadNoteResponseDTO on success.
     */
    public function getNotesForLead(int $leadId): Result;
}
