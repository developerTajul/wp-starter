<?php

declare(strict_types=1);

namespace Core\Activity\Domain\Events;

/**
 * Event fired when a new activity is successfully created.
 */
final readonly class ActivityCreatedEvent
{
    public function __construct(
        public int $activityId,
        public string $trackableType,
        public int $trackableId
    ) {}
}
