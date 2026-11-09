<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Requests;

/**
 * Input for handing a lead to a user, or releasing it from its current owner.
 */
final readonly class LeadOwnerAssignDTO
{
    /**
     * @param int      $leadId          The lead changing hands.
     * @param int|null $assignedTo      The user taking ownership; null releases it.
     * @param int      $requestedBy     The user performing the change. Resolved by
     *                                  delivery, never read from session here.
     */
    public function __construct(
        public int $leadId,
        public ?int $assignedTo,
        public int $requestedBy,
    ) {}
}
