<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Responses;

use Core\Lead\Domain\ReadModels\LeadDealProjectionDTO;

/**
 * Shape the lead detail page's deals panel is handed in.
 */
final readonly class LeadDealResponseDTO
{
    /**
     * @param array<int, LeadDealProjectionDTO> $deals The deals the lead produced, newest first.
     */
    public function __construct(
        public array $deals,
    ) {}
}
