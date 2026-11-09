<?php

declare(strict_types=1);

namespace Core\Lead\Application\Mappers;

use Core\Lead\Application\DTOs\Responses\LeadNoteResponseDTO;
use Core\Lead\Domain\Entities\LeadNoteEntity;
use Core\Lead\Domain\ReadModels\LeadNoteProjectionDTO;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Maps a note entity or read model to LeadNoteResponseDTO.
 */
final class LeadNoteResponseMapper
{
    /**
     * Builds the response from the read model.
     *
     * @param LeadNoteProjectionDTO $projection
     *
     * @return LeadNoteResponseDTO
     */
    public function toResponseDTO(LeadNoteProjectionDTO $projection): LeadNoteResponseDTO
    {
        return new LeadNoteResponseDTO(
            id: $projection->id,
            leadId: $projection->leadId,
            body: $projection->body,
            isPinned: $projection->isPinned,
            createdAt: $projection->createdAt?->format(DateTimeInterface::ATOM),
            updatedAt: $projection->updatedAt?->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * Builds the response from the entity a write returned.
     *
     * @param LeadNoteEntity $note
     *
     * @return LeadNoteResponseDTO
     */
    public function toResponseDTOFromEntity(LeadNoteEntity $note): LeadNoteResponseDTO
    {
        return new LeadNoteResponseDTO(
            id: $note->getId(),
            leadId: $note->getLeadId(),
            body: $note->getBody(),
            isPinned: $note->isPinned(),
            createdAt: $this->toAtom($note->getCreatedAt()),
            updatedAt: $this->toAtom($note->getUpdatedAt()),
        );
    }

    /**
     * Formats a stored UTC wall-clock string as ISO-8601.
     *
     * @param string|null $stored
     *
     * @return string|null
     */
    private function toAtom(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        return (new DateTimeImmutable($stored, new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);
    }
}