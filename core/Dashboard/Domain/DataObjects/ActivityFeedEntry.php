<?php

declare(strict_types=1);

namespace Core\Dashboard\Domain\DataObjects;

/**
 * Read model for one row of the dashboard's activity feed: what the activity
 * is, when it is due, and the record it was logged against. The record is
 * already flattened to the name and morph alias the feed prints, so nothing
 * above Infrastructure has to resolve a polymorphic relation.
 */
final readonly class ActivityFeedEntry
{
    /**
     * @param int         $id
     * @param string      $type
     * @param string      $subject
     * @param string|null $dueDate
     * @param string|null $completedAt
     * @param int|null    $userId
     * @param string|null $userName
     * @param string|null $trackableType Morph alias of the tracked record, e.g. "lead".
     * @param int         $trackableId
     * @param string|null $trackableName
     */
    public function __construct(
        public int $id,
        public string $type,
        public string $subject,
        public ?string $dueDate,
        public ?string $completedAt,
        public ?int $userId,
        public ?string $userName,
        public ?string $trackableType,
        public int $trackableId,
        public ?string $trackableName,
    ) {}
}
