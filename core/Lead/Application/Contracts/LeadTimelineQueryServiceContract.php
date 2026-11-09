<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Shared\Application\DTOs\Result;

/**
 * Read-side timeline use cases for a lead.
 *
 * Declared as its own narrow query port rather than widening
 * LeadQueryServiceContract: the timeline is a reporting concern with its own
 * reader, and keeping it separate leaves the module's main query surface alone.
 */
interface LeadTimelineQueryServiceContract
{
    /**
     * Returns a lead's activity timeline, newest first.
     *
     * @param int $leadId The lead whose timeline is being read.
     * @param int $limit  How many entries to return at most.
     *
     * @return Result Contains a LeadTimelineResponseDTO on success, or a
     *                failure when no lead carries the id.
     */
    public function getTimelineForLead(int $leadId, int $limit = 20): Result;
}
