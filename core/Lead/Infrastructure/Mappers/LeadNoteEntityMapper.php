<?php

declare(strict_types=1);

namespace Core\Lead\Infrastructure\Mappers;

use Core\Lead\Domain\Entities\LeadNoteEntity;
use Core\Lead\Domain\ReadModels\LeadNoteProjectionDTO;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Maps model arrays to LeadNoteEntity and to the note read model.
 */
final class LeadNoteEntityMapper
{
    /**
     * Rebuilds a datetime from a stored UTC wall-clock string. Parsing the
     * value as UTC keeps the instant stable across round trips.
     *
     * @param mixed $value
     *
     * @return DateTimeImmutable|null
     */
    private function toDateTime(mixed $value): ?DateTimeImmutable
    {
        if (empty($value)) {
            return null;
        }

        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }

    /**
     * Builds a pure domain entity from a model array.
     *
     * @param array $data
     *
     * @return LeadNoteEntity
     */
    public function toEntity(array $data): LeadNoteEntity
    {
        return LeadNoteEntity::reconstitute(
            id: (int) $data['id'],
            leadId: (int) $data['lead_id'],
            body: (string) $data['body'],
            isPinned: (bool) ($data['is_pinned'] ?? false),
            createdAt: isset($data['created_at']) ? (string) $data['created_at'] : null,
            updatedAt: isset($data['updated_at']) ? (string) $data['updated_at'] : null,
        );
    }

    /**
     * Builds the read model the note widget draws.
     *
     * @param array $data
     *
     * @return LeadNoteProjectionDTO
     */
    public function toProjection(array $data): LeadNoteProjectionDTO
    {
        return new LeadNoteProjectionDTO(
            id: (int) $data['id'],
            leadId: (int) $data['lead_id'],
            body: (string) $data['body'],
            isPinned: (bool) ($data['is_pinned'] ?? false),
            createdAt: $this->toDateTime($data['created_at'] ?? null),
            updatedAt: $this->toDateTime($data['updated_at'] ?? null),
        );
    }
}