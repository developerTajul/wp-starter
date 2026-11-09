<?php

declare(strict_types=1);

namespace Core\Pipeline\Domain\DataObjects;

/**
 * Read model for one deal as the Kanban board shows it. The company and contact
 * are already flattened to the two columns the board prints, so nothing above
 * Infrastructure has to know how Eloquent nests them.
 */
final readonly class KanbanDealData
{
    /**
     * @param int         $id
     * @param string      $title
     * @param int|null    $companyId
     * @param int|null    $contactId
     * @param string      $amount
     * @param string      $currency
     * @param int         $pipelineId
     * @param int         $dealStageId
     * @param string|null $expectedCloseDate
     * @param string|null $actualCloseDate
     * @param string|null $createdAt
     * @param string|null $updatedAt
     * @param string|null $companyName
     * @param string|null $contactName
     */
    public function __construct(
        public int $id,
        public string $title,
        public ?int $companyId,
        public ?int $contactId,
        public string $amount,
        public string $currency,
        public int $pipelineId,
        public int $dealStageId,
        public ?string $expectedCloseDate,
        public ?string $actualCloseDate,
        public ?string $createdAt,
        public ?string $updatedAt,
        public ?string $companyName,
        public ?string $contactName,
    ) {}
}
