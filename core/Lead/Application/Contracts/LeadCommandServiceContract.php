<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Lead\Application\DTOs\Requests\LeadCreateDTO;
use Core\Lead\Application\DTOs\Requests\LeadUpdateDTO;
use Core\Lead\Domain\Enums\LeadStatus;
use Core\Shared\Application\DTOs\Result;

/**
 * Write-side lead use cases.
 */
interface LeadCommandServiceContract
{
    /**
     * Captures an inbound lead by dispatching it to the background queue.
     *
     * @param LeadCreateDTO $dto
     */
    public function captureLead(LeadCreateDTO $dto): void;

    /**
     * Creates a lead directly via the repository.
     *
     * @param LeadCreateDTO $leadCreateDTO
     *
     * @return Result Contains the LeadResponseDTO on success.
     */
    public function createLead(LeadCreateDTO $leadCreateDTO): Result;

    /**
     * Moves a lead to a new status through the domain rules.
     *
     * @param int        $leadId
     * @param LeadStatus $status
     *
     * @return Result Contains the LeadResponseDTO on success.
     */
    public function changeStatus(int $leadId, LeadStatus $status): Result;

    /**
     * Saves an edit to a lead's own fields.
     *
     * @param LeadUpdateDTO $leadUpdateDTO
     *
     * @return Result Contains the LeadResponseDTO on success.
     */
    public function updateLead(LeadUpdateDTO $leadUpdateDTO): Result;

    /**
     * Removes a lead.
     *
     * @param int $leadId
     *
     * @return Result Success carries no data.
     */
    public function deleteLead(int $leadId): Result;
}
