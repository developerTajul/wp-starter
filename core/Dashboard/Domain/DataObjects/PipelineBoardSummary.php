<?php

declare(strict_types=1);

namespace Core\Dashboard\Domain\DataObjects;

/**
 * Read model for one stage of the dashboard's pipeline overview: the deals
 * sitting in it, and what they are worth together.
 */
final readonly class PipelineBoardSummary
{
    /**
     * @param int   $id
     * @param int   $pipelineId
     * @param string $pipelineName
     * @param string $name
     * @param int   $orderPosition
     * @param int   $probability
     * @param int   $dealsCount
     * @param float $totalValue
     */
    public function __construct(
        public int $id,
        public int $pipelineId,
        public string $pipelineName,
        public string $name,
        public int $orderPosition,
        public int $probability,
        public int $dealsCount,
        public float $totalValue,
    ) {}
}
