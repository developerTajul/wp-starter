<?php

declare(strict_types=1);

namespace Core\Company\Application\DTOs\Responses;

final readonly class CompanyResponseDTO
{
    /**
     * @param int         $id
     * @param string      $name
     * @param string|null $website
     * @param string|null $industry
     * @param string|null $phone
     * @param string|null $address
     * @param string|null $created_at
     * @param string|null $updated_at
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $website,
        public ?string $industry,
        public ?string $phone,
        public ?string $address,
        public ?string $created_at = null,
        public ?string $updated_at = null,
    ) {}
}
