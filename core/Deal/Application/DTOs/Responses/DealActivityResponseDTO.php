<?php

declare(strict_types=1);

namespace Core\Deal\Application\DTOs\Responses;

/**
 * Carries one activity logged against a deal.
 */
final readonly class DealActivityResponseDTO
{
    /**
     * @param int         $id
     * @param string      $type
     * @param string      $subject
     * @param string|null $description
     * @param string|null $due_date
     * @param string|null $completed_at
     * @param string|null $user_name
     */
    public function __construct(
        public int $id,
        public string $type,
        public string $subject,
        public ?string $description,
        public ?string $due_date,
        public ?string $completed_at,
        public ?string $user_name,
    ) {}
}
