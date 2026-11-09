<?php

declare(strict_types=1);

namespace Core\Company\Application\DTOs\Responses;

/**
 * Shape a company's linked contact is handed to the delivery layer in.
 */
final readonly class CompanyContactResponseDTO
{
    /**
     * @param int|null    $id
     * @param string      $name
     * @param string|null $email
     * @param string|null $phone
     * @param string|null $job_title
     * @param bool        $is_primary
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $job_title,
        public bool $is_primary,
    ) {}
}
