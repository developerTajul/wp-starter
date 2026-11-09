<?php

declare(strict_types=1);

namespace Core\Lead\Domain\ReadModels;

/**
 * Read model for one row of a lead's activity timeline: what happened, when,
 * and who logged it. The user is already flattened to the name the timeline
 * prints, so nothing above Infrastructure resolves a relation to draw a row.
 */
final readonly class LeadTimelineProjectionDTO
{
    /**
     * @param int         $id          The activity's identity.
     * @param string      $type        Activity type, e.g. "call", "meeting".
     * @param string      $subject     One-line summary of the activity.
     * @param string|null $description Longer notes, when the activity carries any.
     * @param string|null $dueDate     ISO-8601 UTC, or null when it has no due date.
     * @param string|null $completedAt ISO-8601 UTC, or null while it is outstanding.
     * @param string|null $createdAt   ISO-8601 UTC, when the activity was logged.
     * @param int|null    $userId      Who logged it, or null once that user is gone.
     * @param string|null $userName    That user's name, or null when it is absent.
     */
    public function __construct(
        public int $id,
        public string $type,
        public string $subject,
        public ?string $description,
        public ?string $dueDate,
        public ?string $completedAt,
        public ?string $createdAt,
        public ?int $userId,
        public ?string $userName,
    ) {}
}