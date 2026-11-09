<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Shared\Application\DTOs\Result;

/**
 * Write-side use case for converting a qualified lead into a customer.
 */
interface LeadConversionServiceContract
{
    /**
     * Moves the lead to converted and announces it to the rest of the system.
     *
     * @param int $leadId
     *
     * @return Result Contains the LeadConversionResponseDTO on success.
     */
    public function convert(int $leadId): Result;
}