<?php

declare(strict_types=1);

namespace Core\Activity\Domain\Events;

/**
 * Event fired when an activity is successfully deleted.
 */
final readonly class ActivityDeletedEvent
{
    public function __construct(
        public int $activityId,
        public string $trackableType,
        public int $trackableId
    ) {}
}
