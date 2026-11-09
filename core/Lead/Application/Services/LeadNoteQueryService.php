<?php

declare(strict_types=1);

namespace Core\Lead\Application\Services;

use Core\Lead\Application\Contracts\LeadNoteQueryContract;
use Core\Lead\Application\Contracts\LeadNoteQueryServiceContract;
use Core\Lead\Application\Mappers\LeadNoteResponseMapper;
use Core\Lead\Domain\ReadModels\LeadNoteProjectionDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * Handles lead note read use cases.
 *
 * @implements LeadNoteQueryServiceContract
 */
final class LeadNoteQueryService implements LeadNoteQueryServiceContract
{
    /**
     * @param LeadNoteQueryContract   $noteQuery
     * @param LeadNoteResponseMapper  $responseMapper
     */
    public function __construct(
        private readonly LeadNoteQueryContract $noteQuery,
        private readonly LeadNoteResponseMapper $responseMapper,
    ) {}

    /**
     * Returns a lead's notes, pinned first and newest first within each group.
     *
     * @param int $leadId
     *
     * @return Result Contains a list of LeadNoteResponseDTO.
     */
    public function getNotesForLead(int $leadId): Result
    {
        $notes = array_map(
            fn(LeadNoteProjectionDTO $projection) => $this->responseMapper->toResponseDTO($projection),
            $this->noteQuery->getNotesForLead($leadId),
        );

        return Result::success(data: $notes);
    }
}