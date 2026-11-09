<?php

declare(strict_types=1);

namespace Core\Dashboard\Domain\DataObjects;

/**
 * Read model for one stage of the dashboard's funnel leakage panel: how many
 * deals reached the stage, how many moved on, and how many stalled there.
 */
final readonly class PipelineLeakageSummary
{
    /**
     * @param int   $id
     * @param int   $pipelineId
     * @param string $pipelineName
     * @param string $name
     * @param int   $orderPosition
     * @param int   $dealsCount  How many deals currently sit in the stage.
     * @param float $totalValue
     * @param float $dropOffRate Deals that did not move on from here, as a percentage.
     */
    public function __construct(
        public int $id,
        public int $pipelineId,
        public string $pipelineName,
        public string $name,
        public int $orderPosition,
        public int $dealsCount,
        public float $totalValue,
        public float $dropOffRate,
    ) {}
}
