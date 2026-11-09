<?php

declare(strict_types=1);

namespace Core\Dashboard\Domain\DataObjects;

/**
 * Read model for one row of the dashboard's overdue panel: an activity whose
 * follow-up date has already passed and that nobody has completed.
 */
final readonly class OverdueActivityEntry
{
    /**
     * @param int         $id
     * @param string      $type
     * @param string      $subject
     * @param string|null $dueDate
     * @param int         $daysOverdue How many whole days past the due date it is.
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
        public int $daysOverdue,
        public ?int $userId,
        public ?string $userName,
        public ?string $trackableType,
        public int $trackableId,
        public ?string $trackableName,
    ) {}
}
