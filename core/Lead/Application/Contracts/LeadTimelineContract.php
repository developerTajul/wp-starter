<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Lead\Domain\ReadModels\LeadTimelineProjectionDTO;

/**
 * Read side of the lead's activity timeline port: the activities logged
 * against a lead, newest first.
 *
 * The activities table belongs to the Activity module. This port is declared
 * here, by the module that asks the reporting question, rather than by adding
 * the question to the Activity module's own read port (P7). The matching
 * adapter sits in this module's Infrastructure and reads the table directly.
 */
interface LeadTimelineContract
{
    /**
     * Returns a lead's activities, most recently logged first.
     *
     * @param int $leadId The lead whose timeline is being read.
     * @param int $limit  How many rows to return at most.
     *
     * @return array<int, LeadTimelineProjectionDTO> Empty when the lead has no activities.
     */
    public function getTimelineForLead(int $leadId, int $limit): array;

    /**
     * Returns how many activities the lead carries in total.
     *
     * The widget shows a bounded timeline, so the count is what tells the
     * reader whether rows were left off the end of it.
     *
     * @param int $leadId The lead whose activities are being counted.
     *
     * @return int
     */
    public function countTimelineForLead(int $leadId): int;
}