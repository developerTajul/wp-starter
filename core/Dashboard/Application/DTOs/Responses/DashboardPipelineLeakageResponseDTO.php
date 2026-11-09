<?php

declare(strict_types=1);

namespace Core\Dashboard\Application\DTOs\Responses;

/**
 * Shape one stage of the dashboard's funnel leakage panel is handed to the
 * delivery layer in.
 */
final readonly class DashboardPipelineLeakageResponseDTO
{
    /**
     * @param int    $id
     * @param int    $pipelineId
     * @param string $pipelineName
     * @param string $name
     * @param int    $orderPosition
     * @param int    $dealsCount
     * @param float  $totalValue
     * @param float  $dropOffRate
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
