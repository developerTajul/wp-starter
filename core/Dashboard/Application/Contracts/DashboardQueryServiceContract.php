<?php

declare(strict_types=1);

namespace Core\Dashboard\Application\Contracts;

use Core\Dashboard\Application\DTOs\Requests\DashboardFilterDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * Handles dashboard read use cases.
 */
interface DashboardQueryServiceContract
{
    /**
     * Returns everything the dashboard page shows in one payload.
     *
     * @param DashboardFilterDTO $dashboardFilterDTO
     *
     * @return Result Contains the DashboardOverviewResponseDTO on success.
     */
    public function getOverview(DashboardFilterDTO $dashboardFilterDTO): Result;

    /**
     * Returns the headline figures on their own.
     *
     * @return Result Contains the DashboardMetricsResponseDTO on success.
     */
    public function getMetrics(): Result;

    /**
     * Returns the pipeline overview on its own.
     *
     * @return Result Contains the list of DashboardStageSummaryResponseDTO on success.
     */
    public function getStageSummaries(): Result;

    /**
     * Returns the activity feed on its own.
     *
     * @param DashboardFilterDTO $dashboardFilterDTO
     *
     * @return Result Contains the list of DashboardActivityResponseDTO on success.
     */
    public function getUpcomingActivities(DashboardFilterDTO $dashboardFilterDTO): Result;
}
