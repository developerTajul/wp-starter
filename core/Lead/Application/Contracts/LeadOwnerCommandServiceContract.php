<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Lead\Application\DTOs\Requests\LeadOwnerAssignDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * Write-side use case for a lead's owner.
 *
 * Kept apart from LeadCommandServiceContract because it needs the user
 * directory to resolve the new owner's name, and widening the main write
 * service with that dependency would make every lead write carry it.
 */
interface LeadOwnerCommandServiceContract
{
    /**
     * Hands a lead to a user, or releases it when no user is given.
     *
     * @param LeadOwnerAssignDTO $dto
     *
     * @return Result Contains the LeadOwnerResponseDTO on success.
     */
    public function assignOwner(LeadOwnerAssignDTO $dto): Result;
}
