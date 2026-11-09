<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Lead\Application\DTOs\Requests\LeadCreateDTO;
use Core\Lead\Application\DTOs\Requests\LeadFilterDTO;
use Core\Lead\Application\DTOs\Requests\LeadNoteCreateDTO;
use Core\Lead\Application\DTOs\Requests\LeadOwnerAssignDTO;
use Core\Lead\Application\DTOs\Requests\LeadUpdateDTO;

/**
 * Defines how validated input maps to Lead DTOs.
 */
interface LeadMapperContract
{
    /**
     * Builds a LeadCreateDTO from validated input.
     *
     * @param array $validatedData The validated input data from the delivery layer.
     *
     * @return LeadCreateDTO
     */
    public function mapToCreateDTO(array $validatedData): LeadCreateDTO;

    /**
     * Builds a LeadUpdateDTO from validated input and the id being edited.
     *
     * A blank field on the edit form means the user emptied it, which no
     * absent value can express: the DTO carries a clear flag beside each
     * nullable column, because null alone cannot say both "unchanged" and
     * "emptied".
     *
     * @param int   $id            The lead being edited.
     * @param array $validatedData The validated input data from the delivery layer.
     *
     * @return LeadUpdateDTO
     */
    public function mapToUpdateDTO(int $id, array $validatedData): LeadUpdateDTO;

    /**
     * Builds a LeadOwnerAssignDTO for handing a lead to a user, or releasing it.
     *
     * The acting user is passed in by delivery, which is the only layer that
     * resolves it (P14); Core never reads session state for itself.
     *
     * @param int   $leadId        The lead changing hands.
     * @param array $validatedData The validated input carrying the new owner, if any.
     * @param int   $requestedBy   The user performing the change.
     *
     * @return LeadOwnerAssignDTO
     */
    public function mapToOwnerAssignDTO(int $leadId, array $validatedData, int $requestedBy): LeadOwnerAssignDTO;

    /**
     * Builds a LeadNoteCreateDTO from validated input and the lead being noted.
     *
     * @param int   $leadId        The lead the note is written against.
     * @param array $validatedData The validated input carrying the note body.
     *
     * @return LeadNoteCreateDTO
     */
    public function mapToNoteCreateDTO(int $leadId, array $validatedData): LeadNoteCreateDTO;

    /**
     * Builds a LeadFilterDTO from query string input, rejecting any value it
     * cannot convert. Used by the API, where bad input is a client error.
     *
     * @param array $queryParams
     *
     * @return LeadFilterDTO
     *
     * @throws \InvalidArgumentException When a supplied value is not usable.
     */
    public function mapToFilterDTO(array $queryParams): LeadFilterDTO;

    /**
     * Builds a LeadFilterDTO from query string input, dropping and reporting
     * any value it cannot convert, so a stale bookmark still renders a list.
     *
     * The page size is cut to the module's own bounds here (R12.2), so no
     * caller has to know them.
     *
     * @param array $queryParams
     *
     * @return array{0: LeadFilterDTO, 1: array<int, string>} The filter and the dropped field names.
     */
    public function mapToFilterDTOLenient(array $queryParams): array;

    /**
     * Builds the status options for the list filter select.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function toStatusOptions(): array;
}
