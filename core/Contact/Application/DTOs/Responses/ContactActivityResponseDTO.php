<?php

declare(strict_types=1);

namespace Core\Contact\Application\DTOs\Responses;

/**
 * Carries one activity logged against a contact.
 */
final readonly class ContactActivityResponseDTO
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
        public ?string $description = null,
        public ?string $due_date = null,
        public ?string $completed_at = null,
        public ?string $user_name = null,
    ) {}
}
