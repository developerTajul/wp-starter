<?php

declare(strict_types=1);

namespace Core\Company\Application\DTOs\Responses;

/**
 * Shape a company's deal, with its stage and activities, is handed to the
 * delivery layer in.
 */
final readonly class CompanyDealResponseDTO
{
    /**
     * @param int|null                            $id
     * @param string                              $title
     * @param string                              $amount
     * @param string                              $currency
     * @param string|null                         $expected_close_date
     * @param string|null                         $actual_close_date
     * @param CompanyDealStageResponseDTO|null    $stage
     * @param array<int, CompanyActivityResponseDTO> $activities
     */
    public function __construct(
        public ?int $id,
        public string $title,
        public string $amount,
        public string $currency,
        public ?string $expected_close_date,
        public ?string $actual_close_date,
        public ?CompanyDealStageResponseDTO $stage,
        public array $activities = [],
    ) {}
}
