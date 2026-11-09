<?php

declare(strict_types=1);

namespace Core\Company\Domain\ReadModels;

/**
 * The pipeline stage a company's deal sits in, as the detail read returns it.
 */
final readonly class CompanyDealStageProjectionDTO
{
    /**
     * @param int    $id
     * @param string $name
     * @param int    $orderPosition
     * @param int    $probability
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $orderPosition,
        public int $probability,
    ) {}
}
