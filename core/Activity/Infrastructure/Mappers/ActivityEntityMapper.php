<?php

declare(strict_types=1);

namespace Core\Activity\Infrastructure\Mappers;

use Core\Activity\Application\DTOs\Responses\ActivityProjectionDTO;
use Core\Activity\Domain\Entities\ActivityEntity;
use Core\Activity\Domain\Enums\ActivityType;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Maps model arrays to ActivityEntity and to the activity read model.
 */
final class ActivityEntityMapper
{
    /**
     * The only relation fields exposed by the read model. A relation's raw
     * array also carries columns the response has no business returning
     * (a user's email or password hash, a lead's phone number).
     *
     * @var array<int, string>
     */
    private const RELATION_FIELDS = ['id', 'name'];

    /**
     * Rebuilds a datetime from a stored value. Datetimes are persisted as UTC
     * wall-clock strings, so they are read back as UTC too: parsing them in the
     * application timezone would shift the instant on every round trip.
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
     * @return ActivityEntity
     */
    public function toEntity(array $data): ActivityEntity
    {
        return ActivityEntity::reconstitute(
            id: (int) $data['id'],
            trackableType: (string) $data['trackable_type'],
            trackableId: (int) $data['trackable_id'],
            type: ActivityType::from((string) $data['type']),
            subject: (string) $data['subject'],
            userId: isset($data['user_id']) ? (int) $data['user_id'] : null,
            description: $data['description'] ?? null,
            dueDate: $this->toDateTime($data['due_date'] ?? null),
            completedAt: $this->toDateTime($data['completed_at'] ?? null)
        );
    }

    /**
     * Builds the read model, pairing the entity with its loaded relations
     * and timestamps. Model::toArray() only exposes the relation keys when
     * they were eager-loaded.
     *
     * @param array $data
     *
     * @return ActivityProjectionDTO
     */
    public function toProjection(array $data): ActivityProjectionDTO
    {
        return new ActivityProjectionDTO(
            entity: $this->toEntity($data),
            user: $this->toRelationSummary($data['user'] ?? null),
            trackable: $this->toRelationSummary($data['trackable'] ?? null),
            createdAt: $this->toDateTime($data['created_at'] ?? null),
            updatedAt: $this->toDateTime($data['updated_at'] ?? null),
        );
    }

    /**
     * Narrows a loaded relation to the fields the response may expose, so
     * sensitive columns never leave this boundary.
     *
     * @param array|null $relation
     *
     * @return array<string, mixed>|null
     */
    private function toRelationSummary(?array $relation): ?array
    {
        if ($relation === null) {
            return null;
        }

        $summary = [];

        foreach (self::RELATION_FIELDS as $field) {
            if (array_key_exists($field, $relation)) {
                $summary[$field] = $relation[$field];
            }
        }

        return $summary;
    }
}
