<?php

declare(strict_types=1);

namespace Core\Dashboard\Application\Mappers;

use Core\Dashboard\Application\DTOs\Responses\DashboardActivityResponseDTO;
use Core\Dashboard\Application\DTOs\Responses\DashboardMetricsResponseDTO;
use Core\Dashboard\Application\DTOs\Responses\DashboardOverdueActivityResponseDTO;
use Core\Dashboard\Application\DTOs\Responses\DashboardOverviewResponseDTO;
use Core\Dashboard\Application\DTOs\Responses\DashboardPipelineLeakageResponseDTO;
use Core\Dashboard\Application\DTOs\Responses\DashboardStageSummaryResponseDTO;
use Core\Dashboard\Domain\DataObjects\ActivityFeedEntry;
use Core\Dashboard\Domain\DataObjects\DashboardMetrics;
use Core\Dashboard\Domain\DataObjects\OverdueActivityEntry;
use Core\Dashboard\Domain\DataObjects\PipelineBoardSummary;
use Core\Dashboard\Domain\DataObjects\PipelineLeakageSummary;
use Core\Dashboard\Domain\DashboardRules;
use Core\Dashboard\Domain\ValueObjects\ActivityMetrics;
use Core\Dashboard\Domain\ValueObjects\DealMetrics;
use Core\Dashboard\Domain\ValueObjects\LeadMetrics;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Maps the dashboard read models to their response DTOs.
 */
final class DashboardResponseMapper
{
    /**
     * The currency the dashboard's money figures are reported in. Deals carry
     * their own currency, but the headline totals add deals from every pipeline
     * together, and a sum of mixed currencies is only meaningful under one.
     * Narrowing it is what `deals.currency` already does with its default.
     */
    private const REPORTING_CURRENCY = 'BDT';

    /**
     * Builds the whole dashboard payload from its three read models.
     *
     * `$boardLimit` and `$defaultPipelineId` are carried through rather than
     * decided here: the board is grouped by pipeline in the view, and the view
     * needs both to cap a pipeline's stages and to choose which tab opens.
     *
     * @param DashboardMetrics          $metrics
     * @param array<int, PipelineBoardSummary> $stages
     * @param array<int, ActivityFeedEntry>    $upcomingActivities
     * @param int                       $boardLimit
     * @param int|null                  $defaultPipelineId
     *
     * @return DashboardOverviewResponseDTO
     */
    public function toOverviewResponseDTO(
        DashboardMetrics $metrics,
        array $stages,
        array $upcomingActivities,
        int $boardLimit = DashboardRules::BOARD_LIMIT,
        ?int $defaultPipelineId = null,
        array $overdueActivities = [],
        array $stageLeakage = [],
    ): DashboardOverviewResponseDTO {
        return new DashboardOverviewResponseDTO(
            metrics: $this->toMetricsResponseDTO($metrics),
            stages: array_map($this->toStageSummaryDTO(...), $stages),
            upcomingActivities: array_map($this->toActivityDTO(...), $upcomingActivities),
            boardLimit: $boardLimit,
            defaultPipelineId: $defaultPipelineId,
            overdueActivities: array_map($this->toOverdueActivityDTO(...), $overdueActivities),
            overdueTotal: count($overdueActivities),
            stageLeakage: array_map($this->toStageLeakageDTO(...), $stageLeakage),
        );
    }

    /**
     * Builds the headline figures. The three panels are written out field by
     * field rather than nested, because the Blade page reads them as flat
     * scalars while the JSON endpoint nests them back into groups.
     *
     * @param DashboardMetrics $metrics
     *
     * @return DashboardMetricsResponseDTO
     */
    public function toMetricsResponseDTO(DashboardMetrics $metrics): DashboardMetricsResponseDTO
    {
        $deals = $metrics->deals;
        $leads = $metrics->leads;
        $activities = $metrics->activities;

        return new DashboardMetricsResponseDTO(
            totalDeals: $deals->total,
            wonDeals: $deals->won,
            lostDeals: $deals->lost,
            openDeals: $deals->open,
            winRate: $deals->winRate,
            wonRevenue: $deals->wonRevenue,
            openPipelineValue: $deals->openPipelineValue,
            currency: self::REPORTING_CURRENCY,
            totalLeads: $leads->total,
            convertedLeads: $leads->converted,
            conversionRate: $leads->conversionRate,
            pendingActivities: $activities->pending,
            completedActivities: $activities->completed,
        );
    }

    /**
     * @param PipelineBoardSummary $stage
     *
     * @return DashboardStageSummaryResponseDTO
     */
    private function toStageSummaryDTO(PipelineBoardSummary $stage): DashboardStageSummaryResponseDTO
    {
        return new DashboardStageSummaryResponseDTO(
            id: $stage->id,
            pipelineId: $stage->pipelineId,
            pipelineName: $stage->pipelineName,
            name: $stage->name,
            orderPosition: $stage->orderPosition,
            probability: $stage->probability,
            dealsCount: $stage->dealsCount,
            totalValue: $stage->totalValue,
        );
    }

    /**
     * @param ActivityFeedEntry $entry
     *
     * @return DashboardActivityResponseDTO
     */
    private function toActivityDTO(ActivityFeedEntry $entry): DashboardActivityResponseDTO
    {
        return new DashboardActivityResponseDTO(
            id: $entry->id,
            type: $entry->type,
            subject: $entry->subject,
            due_date: $this->toAtom($entry->dueDate),
            completed_at: $this->toAtom($entry->completedAt),
            user_id: $entry->userId,
            user_name: $entry->userName,
            trackable_type: $entry->trackableType,
            trackable_id: $entry->trackableId,
            trackable_name: $entry->trackableName,
        );
    }

    /**
     * @param OverdueActivityEntry $entry
     *
     * @return DashboardOverdueActivityResponseDTO
     */
    private function toOverdueActivityDTO(OverdueActivityEntry $entry): DashboardOverdueActivityResponseDTO
    {
        return new DashboardOverdueActivityResponseDTO(
            id: $entry->id,
            type: $entry->type,
            subject: $entry->subject,
            due_date: $this->toAtom($entry->dueDate),
            days_overdue: $entry->daysOverdue,
            user_id: $entry->userId,
            user_name: $entry->userName,
            trackable_type: $entry->trackableType,
            trackable_id: $entry->trackableId,
            trackable_name: $entry->trackableName,
        );
    }

    /**
     * @param PipelineLeakageSummary $stage
     *
     * @return DashboardPipelineLeakageResponseDTO
     */
    private function toStageLeakageDTO(PipelineLeakageSummary $stage): DashboardPipelineLeakageResponseDTO
    {
        return new DashboardPipelineLeakageResponseDTO(
            id: $stage->id,
            pipelineId: $stage->pipelineId,
            pipelineName: $stage->pipelineName,
            name: $stage->name,
            orderPosition: $stage->orderPosition,
            dealsCount: $stage->dealsCount,
            totalValue: $stage->totalValue,
            dropOffRate: $stage->dropOffRate,
        );
    }

    /**
     * Formats a stored UTC timestamp as ISO-8601. Parsing it in the application
     * timezone would shift the instant on every round trip.
     *
     * @param string|null $value
     *
     * @return string|null
     */
    private function toAtom(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);
    }
}
