<?php

declare(strict_types=1);

namespace Core\Dashboard\Application\DTOs\Responses;

/**
 * Shape one row of the dashboard's overdue panel is handed to the delivery
 * layer in.
 */
final readonly class DashboardOverdueActivityResponseDTO
{
    /**
     * @param int         $id
     * @param string      $type
     * @param string      $subject
     * @param string|null $due_date
     * @param int         $days_overdue
     * @param int|null    $user_id
     * @param string|null $user_name
     * @param string|null $trackable_type Morph alias of the tracked record.
     * @param int         $trackable_id
     * @param string|null $trackable_name
     */
    public function __construct(
        public int $id,
        public string $type,
        public string $subject,
        public ?string $due_date,
        public int $days_overdue,
        public ?int $user_id,
        public ?string $user_name,
        public ?string $trackable_type,
        public int $trackable_id,
        public ?string $trackable_name,
    ) {}
}
