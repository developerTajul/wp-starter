<?php

declare(strict_types=1);

namespace Core\Dashboard\Application\Services;

use Core\Dashboard\Application\Contracts\DashboardQueryServiceContract;
use Core\Dashboard\Application\DTOs\Requests\DashboardFilterDTO;
use Core\Dashboard\Application\Mappers\DashboardResponseMapper;
use Core\Dashboard\Domain\Contracts\DashboardBoardContract;
use Core\Dashboard\Domain\Contracts\DashboardFeedContract;
use Core\Dashboard\Domain\Contracts\DashboardMetricsContract;
use Core\Shared\Application\DTOs\Result;
use Psr\Log\LoggerInterface;

/**
 * Handles dashboard read use cases. Never changes state.
 *
 * @implements DashboardQueryServiceContract
 */
final class DashboardQueryService implements DashboardQueryServiceContract
{
    private const ERROR_OVERVIEW_FAILED = 'DASHBOARD_OVERVIEW_FAILED';

    private const ERROR_METRICS_FAILED = 'DASHBOARD_METRICS_FAILED';

    private const ERROR_BOARD_FAILED = 'DASHBOARD_BOARD_FAILED';

    private const ERROR_FEED_FAILED = 'DASHBOARD_FEED_FAILED';

    /**
     * @param DashboardMetricsContract $metrics
     * @param DashboardBoardContract   $board
     * @param DashboardFeedContract    $feed
     * @param DashboardResponseMapper  $responseMapper
     * @param LoggerInterface          $logger
     */
    public function __construct(
        private readonly DashboardMetricsContract $metrics,
        private readonly DashboardBoardContract $board,
        private readonly DashboardFeedContract $feed,
        private readonly DashboardResponseMapper $responseMapper,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Returns everything the dashboard page shows in one payload.
     *
     * The three reads are one use case, not three: the page is a single view,
     * and the API endpoint that mirrors it returns the same shape. A caller
     * that wants one panel on its own asks for that panel.
     *
     * @param DashboardFilterDTO $dashboardFilterDTO
     *
     * @return Result Contains the DashboardOverviewResponseDTO on success.
     */
    public function getOverview(DashboardFilterDTO $dashboardFilterDTO): Result
    {
        try {
            $metrics = $this->metrics->getMetrics();

            return Result::success(
                data: $this->responseMapper->toOverviewResponseDTO(
                    metrics: $metrics,
                    stages: $this->board->getStageSummaries(),
                    upcomingActivities: $this->feed->getUpcomingActivities($dashboardFilterDTO->feedLimit),
                    boardLimit: $dashboardFilterDTO->boardLimit,
                    defaultPipelineId: $this->board->getDefaultPipelineId(),
                    overdueActivities: $this->feed->getOverdueActivities($dashboardFilterDTO->overdueLimit),
                    stageLeakage: $this->board->getStageLeakage(),
                ),
                message: 'Dashboard retrieved successfully.',
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to retrieve the dashboard overview.', ['exception' => $exception]);

            return Result::failure('Failed to retrieve the dashboard.', self::ERROR_OVERVIEW_FAILED);
        }
    }

    /**
     * Returns the headline figures on their own.
     *
     * @return Result Contains the DashboardMetricsResponseDTO on success.
     */
    public function getMetrics(): Result
    {
        try {
            return Result::success(
                data: $this->responseMapper->toMetricsResponseDTO($this->metrics->getMetrics()),
                message: 'Dashboard metrics retrieved successfully.',
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to retrieve the dashboard metrics.', ['exception' => $exception]);

            return Result::failure('Failed to retrieve the dashboard metrics.', self::ERROR_METRICS_FAILED);
        }
    }

    /**
     * Returns the pipeline overview on its own.
     *
     * @return Result Contains the list of DashboardStageSummaryResponseDTO on success.
     */
    public function getStageSummaries(): Result
    {
        try {
            return Result::success(
                data: $this->responseMapper->toOverviewResponseDTO(
                    metrics: $this->metrics->getMetrics(),
                    stages: $this->board->getStageSummaries(),
                    upcomingActivities: [],
                )->stages,
                message: 'Dashboard pipeline summary retrieved successfully.',
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to retrieve the dashboard pipeline summary.', ['exception' => $exception]);

            return Result::failure('Failed to retrieve the dashboard pipeline summary.', self::ERROR_BOARD_FAILED);
        }
    }

    /**
     * Returns the activity feed on its own.
     *
     * @param DashboardFilterDTO $dashboardFilterDTO
     *
     * @return Result Contains the list of DashboardActivityResponseDTO on success.
     */
    public function getUpcomingActivities(DashboardFilterDTO $dashboardFilterDTO): Result
    {
        try {
            return Result::success(
                data: $this->responseMapper->toOverviewResponseDTO(
                    metrics: $this->metrics->getMetrics(),
                    stages: [],
                    upcomingActivities: $this->feed->getUpcomingActivities($dashboardFilterDTO->feedLimit),
                )->upcomingActivities,
                message: 'Dashboard activity feed retrieved successfully.',
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to retrieve the dashboard activity feed.', ['exception' => $exception]);

            return Result::failure('Failed to retrieve the dashboard activity feed.', self::ERROR_FEED_FAILED);
        }
    }
}
