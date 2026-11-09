<?php

declare(strict_types=1);

namespace Core\Activity\Application\Mappers;

use Core\Activity\Application\DTOs\Responses\ActivityProjectionDTO;
use Core\Activity\Application\DTOs\Responses\ActivityResponseDTO;
use DateTimeInterface;

/**
 * Maps an activity and its loaded relations to ActivityResponseDTO.
 */
final class ActivityResponseMapper
{
    /**
     * Builds the response DTO from the activity projection.
     *
     * @param ActivityProjectionDTO $projection
     *
     * @return ActivityResponseDTO
     */
    public function toResponseDTO(ActivityProjectionDTO $projection): ActivityResponseDTO
    {
        $entity = $projection->entity;

        return new ActivityResponseDTO(
            id: $entity->getId(),
            user_id: $entity->getUserId(),
            trackable_type: $entity->getTrackableType()->toString(),
            trackable_id: $entity->getTrackableId(),
            type: $entity->getType()->value,
            subject: $entity->getSubject(),
            description: $entity->getDescription(),
            // Every datetime leaves on the same ISO-8601 wire format, so a
            // client parses all four the same way. ATOM rather than Carbon's
            // toIso8601String(), because these are native DateTimeImmutable.
            due_date: $entity->getDueDate()?->format(DateTimeInterface::ATOM),
            completed_at: $entity->getCompletedAt()?->format(DateTimeInterface::ATOM),
            created_at: $projection->createdAt?->format(DateTimeInterface::ATOM),
            updated_at: $projection->updatedAt?->format(DateTimeInterface::ATOM),
            user: $projection->user,
            trackable: $projection->trackable
        );
    }
}
