<?php

declare(strict_types=1);

namespace Core\Deal\Application\DTOs\Responses;

/**
 * Carries the deal form's picker rows back to the delivery layer.
 */
final readonly class DealFormOptionsResponseDTO
{
    /**
     * @param array<int, DealOptionResponseDTO> $pipelines
     * @param array<int, DealOptionResponseDTO> $stages
     * @param array<int, DealOptionResponseDTO> $companies
     * @param array<int, DealOptionResponseDTO> $contacts
     */
    public function __construct(
        public array $pipelines = [],
        public array $stages = [],
        public array $companies = [],
        public array $contacts = [],
    ) {}
}
