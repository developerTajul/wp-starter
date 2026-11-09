<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Lead\Domain\ReadModels\LeadDealProjectionDTO;

/**
 * Read side of the lead's deals port: the deals a lead produced.
 *
 * The deals table belongs to the Deal module, and the link between the two is
 * held by lead_conversions. This port is declared here, by the module that asks
 * the reporting question, rather than by adding the question to the Deal
 * module's own read port (P7). The matching adapter sits in this module's
 * Infrastructure and reads both tables directly.
 */
interface LeadDealContract
{
    /**
     * Returns the deals the lead converted into, newest first.
     *
     * @param int $leadId The lead whose deals are being read.
     *
     * @return array<int, LeadDealProjectionDTO> Empty when the lead has converted into nothing.
     */
    public function getDealsForLead(int $leadId): array;
}