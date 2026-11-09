<?php

declare(strict_types=1);

namespace Core\Activity\Application\DTOs\Responses;

final readonly class ActivityResponseDTO
{
    public function __construct(
        public ?int $id,
        public ?int $user_id,
        public string $trackable_type,
        public int $trackable_id,
        public string $type,
        public string $subject,
        public ?string $description,
        public ?string $due_date,
        public ?string $completed_at,
        public ?string $created_at = null,
        public ?string $updated_at = null,
        public ?array $user = null,
        public ?array $trackable = null
    ) {}
}