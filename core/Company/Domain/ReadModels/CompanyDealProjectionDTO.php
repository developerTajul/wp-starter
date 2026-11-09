<?php

declare(strict_types=1);

namespace Core\Company\Domain\ReadModels;

/**
 * A deal row as the company detail read returns it: the deal's own columns,
 * its stage when one is attached, and its activities.
 *
 * The amount stays a string (P2): the column is decimal and a float would
 * round a money value on the way out.
 */
final readonly class CompanyDealProjectionDTO
{
    /**
     * @param int                               $id
     * @param string                            $title
     * @param string                            $amount
     * @param string                            $currency
     * @param string|null                       $expectedCloseDate
     * @param string|null                       $actualCloseDate
     * @param CompanyDealStageProjectionDTO|null $stage
     * @param list<CompanyActivityProjectionDTO> $activities
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $amount,
        public string $currency,
        public ?string $expectedCloseDate,
        public ?string $actualCloseDate,
        public ?CompanyDealStageProjectionDTO $stage,
        public array $activities = [],
    ) {}
}
