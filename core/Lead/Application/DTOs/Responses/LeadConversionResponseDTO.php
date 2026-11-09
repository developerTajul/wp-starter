<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Responses;

/**
 * Shape the converted lead is handed to the delivery layer in.
 */
final readonly class LeadConversionResponseDTO
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $company_name,
        public string $status,
        public string $status_label,
        public string $source,
        public string $converted_at,
    ) {}
}
