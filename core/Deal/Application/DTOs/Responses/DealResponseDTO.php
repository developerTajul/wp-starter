<?php

declare(strict_types=1);

namespace Core\Deal\Application\DTOs\Responses;

/**
 * Carries a deal back to the delivery layer.
 */
final readonly class DealResponseDTO
{
    /**
     * @param int|null    $id
     * @param string      $title
     * @param int|null    $company_id
     * @param int|null    $contact_id
     * @param string      $amount
     * @param string      $currency
     * @param int         $pipeline_id
     * @param int         $deal_stage_id
     * @param string|null $expected_close_date
     * @param string|null $actual_close_date
     * @param string|null $created_at
     * @param string|null $updated_at
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
    ) {}
}
