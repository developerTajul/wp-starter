<?php

declare(strict_types=1);

namespace Core\Deal\Application\DTOs\Requests;

/**
 * Carries validated delivery-layer input INTO the Service. Immutable.
 */
final readonly class StoreDealDTO
{
    /**
     * @param string      $title
     * @param int         $pipelineId
     * @param int         $dealStageId
     * @param int|null    $companyId
     * @param int|null    $contactId
     * @param string      $amount
     * @param string      $currency
     * @param string|null $expectedCloseDate
     * @param string|null $actualCloseDate
     */
    public function __construct(
        public string $title,
        public int $pipelineId,
        public int $dealStageId,
        public ?int $companyId = null,
        public ?int $contactId = null,
        public string $amount = '0.00',
        public string $currency = 'BDT',
        public ?string $expectedCloseDate = null,
        public ?string $actualCloseDate = null,
    ) {}
}
