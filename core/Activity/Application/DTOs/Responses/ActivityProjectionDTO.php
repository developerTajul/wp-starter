<?php

declare(strict_types=1);

namespace Core\Activity\Application\DTOs\Responses;

use Core\Activity\Domain\Entities\ActivityEntity;
use DateTimeImmutable;

/**
 * Read model for the activity list and detail views. Pairs the activity
 * entity with its resolved relations and timestamps, so the entity itself
 * stays free of projection state.
 */
final readonly class ActivityProjectionDTO
{
    public function __construct(
        public ActivityEntity $entity,
        public ?array $user = null,
        public ?array $trackable = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}
}
