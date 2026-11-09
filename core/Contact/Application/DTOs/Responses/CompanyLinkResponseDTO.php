<?php

declare(strict_types=1);

namespace Core\Contact\Application\DTOs\Responses;

/**
 * Carries one company a contact is attached to, with the role held there.
 */
final readonly class CompanyLinkResponseDTO
{
    /**
     * @param int|null    $id
     * @param string      $name
     * @param string|null $phone
     * @param string|null $website
     * @param string|null $industry
     * @param string|null $job_title
     * @param bool        $is_primary
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public ?string $phone = null,
        public ?string $website = null,
        public ?string $industry = null,
        public ?string $job_title = null,
        public bool $is_primary = false,
    ) {}
}
