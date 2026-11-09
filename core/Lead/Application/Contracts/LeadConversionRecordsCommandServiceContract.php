<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Lead\Application\DTOs\Requests\LeadConversionDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * Write-side use case for building the records a converted lead becomes.
 *
 * Distinct from LeadConversionServiceContract, which moves the lead's own
 * status: this one runs after that conversion and builds what it produced.
 */
interface LeadConversionRecordsCommandServiceContract
{
    /**
     * Creates the company, contact and deal a converted lead turned into, and
     * links them to the lead.
     *
     * @param LeadConversionDTO $leadConversionDTO
     *
     * @return Result Contains the LeadConversionLinkResponseDTO on success.
     */
    public function convertLead(LeadConversionDTO $leadConversionDTO): Result;
}
