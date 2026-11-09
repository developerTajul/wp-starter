<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Shared\Application\DTOs\Result;

/**
 * Read-side deals use cases for a lead.
 *
 * Declared as its own narrow query port rather than widening
 * LeadQueryServiceContract: the deals panel is a reporting concern with its own
 * reader, and keeping it separate leaves the module's main query surface alone.
 */
interface LeadDealQueryServiceContract
{
    /**
     * Returns the deals a lead converted into, newest first.
     *
     * @param int $leadId The lead whose deals are being read.
     *
     * @return Result Contains a LeadDealResponseDTO on success, or a failure
     *                when no lead carries the id.
     */
    public function getDealsForLead(int $leadId): Result;
}
