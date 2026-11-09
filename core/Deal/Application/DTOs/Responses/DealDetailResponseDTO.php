<?php

declare(strict_types=1);

namespace Core\Deal\Application\DTOs\Responses;

/**
 * Carries one deal with everything attached to it back to the delivery layer.
 */
final readonly class DealDetailResponseDTO
{
    /**
     * @param int|null                      $id
     * @param string                        $title
     * @param int|null                      $company_id
     * @param int|null                      $contact_id
     * @param string                        $amount
     * @param string                        $currency
     * @param int                           $pipeline_id
     * @param int                           $deal_stage_id
     * @param string|null                   $expected_close_date
     * @param string|null                   $actual_close_date
     * @param string|null                   $created_at
     * @param string|null                   $updated_at
     * @param DealCompanyResponseDTO|null   $company
     * @param DealContactResponseDTO|null   $contact
     * @param DealPipelineResponseDTO|null  $pipeline
     * @param DealStageResponseDTO|null     $stage
     * @param array<int, DealActivityResponseDTO> $activities
     */
    public function __construct(
        public ?int $id,
        public string $title,
        public ?int $company_id,
        public ?int $contact_id,
        public string $amount,
        public string $currency,
        public int $pipeline_id,
        public int $deal_stage_id,
        public ?string $expected_close_date,
        public ?string $actual_close_date,
        public ?string $created_at = null,
        public ?string $updated_at = null,
        public ?DealCompanyResponseDTO $company = null,
        public ?DealContactResponseDTO $contact = null,
        public ?DealPipelineResponseDTO $pipeline = null,
        public ?DealStageResponseDTO $stage = null,
        public array $activities = [],
    ) {}
}
