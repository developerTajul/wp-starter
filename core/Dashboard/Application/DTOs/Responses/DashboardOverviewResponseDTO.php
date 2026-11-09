<?php

declare(strict_types=1);

namespace Core\Dashboard\Application\DTOs\Responses;

/**
 * Shape the whole dashboard is handed to the delivery layer in: the headline
 * figures, the pipeline overview and the activity feed. One payload serves the
 * Blade page and the JSON endpoint.
 */
final readonly class DashboardOverviewResponseDTO
{
    /**
     * @param DashboardMetricsResponseDTO        $metrics
     * @param array<int, DashboardStageSummaryResponseDTO> $stages
     * @param array<int, DashboardActivityResponseDTO>     $upcomingActivities
     * @param int      $boardLimit         How many stages of one pipeline the board shows.
     * @param int|null $defaultPipelineId  The pipeline the board opens on.
     * @param array<int, DashboardOverdueActivityResponseDTO> $overdueActivities
     * @param int      $overdueTotal       How many overdue rows exist, before the cap.
     * @param array<int, DashboardPipelineLeakageResponseDTO> $stageLeakage
     */
    public function __construct(
        public DashboardMetricsResponseDTO $metrics,
        public array $stages,
        public array $upcomingActivities,
        public int $boardLimit,
        public ?int $defaultPipelineId,
        public array $overdueActivities = [],
        public int $overdueTotal = 0,
        public array $stageLeakage = [],
    ) {}
}
