<?php

declare(strict_types=1);

namespace Core\Company\Application\DTOs\Responses;

/**
 * Shape an activity logged against a company deal is handed to the delivery
 * layer in.
 */
final readonly class CompanyActivityResponseDTO
{
    /**
     * @param int|null    $id
     * @param string      $type
     * @param string      $subject
     * @param string|null $description
     * @param string|null $due_date
     * @param string|null $completed_at
     * @param string|null $user_name
     */
    public function __construct(
        public ?int $id,
        public string $type,
        public string $subject,
        public ?string $description,
        public ?string $due_date,
        public ?string $completed_at,
        public ?string $user_name,
    ) {}
}
