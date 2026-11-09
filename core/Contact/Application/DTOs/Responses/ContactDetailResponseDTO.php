<?php

declare(strict_types=1);

namespace Core\Contact\Application\DTOs\Responses;

/**
 * Carries a contact's full profile: its own fields plus its companies and
 * activities.
 */
final readonly class ContactDetailResponseDTO
{
    /**
     * @param int|null                            $id
     * @param string                              $name
     * @param string                              $email
     * @param string|null                         $phone
     * @param string|null                         $created_at
     * @param string|null                         $updated_at
     * @param array<int, CompanyLinkResponseDTO>  $companies
     * @param array<int, ContactActivityResponseDTO> $activities
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public string $email,
        public ?string $phone,
        public ?string $created_at = null,
        public ?string $updated_at = null,
        public array $companies = [],
        public array $activities = [],
    ) {}
}
