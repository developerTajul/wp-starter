<?php

declare(strict_types=1);

namespace Core\Company\Application\DTOs\Responses;

/**
 * Shape a company's 360-degree profile is handed to the delivery layer in:
 * the company's own fields plus its contacts, deals and deal activities.
 */
final readonly class CompanyDetailResponseDTO
{
    /**
     * @param int|null                               $id
     * @param string                                 $name
     * @param string|null                            $website
     * @param string|null                            $industry
     * @param string|null                            $phone
     * @param string|null                            $address
     * @param string|null                            $created_at
     * @param string|null                            $updated_at
     * @param array<int, CompanyContactResponseDTO>  $contacts
     * @param array<int, CompanyDealResponseDTO>     $deals
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public ?string $website,
        public ?string $industry,
        public ?string $phone,
        public ?string $address,
        public ?string $created_at = null,
        public ?string $updated_at = null,
        public array $contacts = [],
        public array $deals = [],
    ) {}
}
