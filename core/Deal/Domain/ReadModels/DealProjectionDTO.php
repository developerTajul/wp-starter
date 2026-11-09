<?php

declare(strict_types=1);

namespace Core\Deal\Domain\ReadModels;

/**
 * A deal as the list read sees it: the deal's own columns plus the labels the
 * table prints, resolved in the read query. Carries no behaviour and no
 * DealEntity, so a list never pays for a full domain object per row.
 */
final readonly class DealProjectionDTO
{
    /**
     * @param int         $id
     * @param string      $title
     * @param int|null    $companyId
     * @param string|null $companyName
     * @param int|null    $contactId
     * @param string|null $contactName
     * @param string      $amount
     * @param string      $currency
     * @param int         $pipelineId
     * @param string|null $pipelineName
     * @param int         $dealStageId
     * @param string|null $stageName
     * @param int|null    $stageProbability
     * @param string|null $expectedCloseDate
     * @param string|null $actualCloseDate
     * @param string|null $createdAt
     * @param string|null $updatedAt
     */
    public function __construct(
        public int $id,
        public string $title,
        public ?int $companyId,
        public ?string $companyName,
        public ?int $contactId,
        public ?string $contactName,
        public string $amount,
        public string $currency,
        public int $pipelineId,
        public ?string $pipelineName,
        public int $dealStageId,
        public ?string $stageName,
        public ?int $stageProbability,
        public ?string $expectedCloseDate,
        public ?string $actualCloseDate,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}
}
