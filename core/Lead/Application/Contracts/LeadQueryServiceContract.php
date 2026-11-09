<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Lead\Application\DTOs\Requests\LeadFilterDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * Read-side lead use cases.
 */
interface LeadQueryServiceContract
{
    /**
     * Returns the paginated lead list matching the given criteria.
     *
     * @param LeadFilterDTO $leadFilterDTO
     *
     * @return Result Contains the PaginatedResult of LeadResponseDTO on success.
     */
    public function getLeads(LeadFilterDTO $leadFilterDTO): Result;

    /**
     * Returns a single lead by its ID.
     *
     * @param int $id
     *
     * @return Result Contains the LeadResponseDTO on success.
     */
    public function getLeadById(int $id): Result;
}
