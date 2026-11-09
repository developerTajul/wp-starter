<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Responses;

/**
 * Shape the stored conversion link is handed to the delivery layer in.
 */
final readonly class LeadConversionLinkResponseDTO
{
    /**
     * @param int|null    $id          Null before the link is stored.
     * @param int         $lead_id
     * @param int         $contact_id
     * @param int|null    $company_id
     * @param int|null    $deal_id
     * @param string      $converted_at Stored UTC wall-clock string.
     */
    public function __construct(
        public ?int $id,
        public int $lead_id,
        public int $contact_id,
        public ?int $company_id,
        public ?int $deal_id,
        public string $converted_at,
    ) {}
}