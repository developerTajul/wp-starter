<?php

declare(strict_types=1);

namespace Core\Dashboard\Infrastructure\Repositories;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Pipeline;
use Core\Dashboard\Domain\Contracts\DashboardBoardContract;
use Core\Dashboard\Domain\DataObjects\PipelineBoardSummary;
use Core\Dashboard\Domain\DataObjects\PipelineLeakageSummary;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent-backed aggregation for the dashboard's pipeline overview.
 *
 * Reads the stages table and totals the deals sitting in each. Reports across
 * the pipeline and deal modules, so it is kept out of both of their
 * repositories.
 *
 * @implements DashboardBoardContract
 */
final class DashboardBoardRepository implements DashboardBoardContract
{
    /**
     * The stage names that close a deal, lower-cased. Declared here as well as
     * on the metrics adapter because each adapter owns its own query and the
     * two must agree; both trace to DealRules::WON_STAGE_NAME / LOST_STAGE_NAME.
     *
     * @var array<int, string>
     */
    private const CLOSED_STAGE_NAMES_LOWERCASE = [
        'closed won',
        'closed lost',
    ];

    /**
     * Returns one summary row per stage, ordered by pipeline and then by the
     * order the stages are worked through.
     *
     * The totals are aggregated in the database rather than by loading every
     * deal: a board summary counts, it does not list.
     *
     * @return array<int, PipelineBoardSummary> Empty when no stage exists.
     */
    public function getStageSummaries(): array
    {
        $stages = DealStage::query()
            ->with('pipeline:id,name')
            ->withCount('deals')
            // The amount column sums to a decimal, so the figure is rounded to
            // the two decimals it carries rather than reported as a float with
            // whatever noise the driver returns.
            ->withSum('deals as total_value', 'amount')
            ->orderBy('pipeline_id')
            ->orderBy('order_position')
            ->get(['id', 'pipeline_id', 'name', 'order_position', 'probability']);

        return $stages
            ->map(fn(DealStage $stage) => new PipelineBoardSummary(
                id: (int) $stage->id,
                pipelineId: (int) $stage->pipeline_id,
                pipelineName: $stage->pipeline?->name ?? '',
                name: (string) $stage->name,
                orderPosition: (int) $stage->order_position,
                probability: (int) $stage->probability,
                dealsCount: (int) $stage->deals_count,
                totalValue: round((float) $stage->total_value, 2),
            ))
            ->all();
    }

    /**
     * Returns one funnel row per stage, carrying how many deals stalled there.
     *
     * A deal's history is not stored: the row knows only the stage it sits in
     * now. So the funnel is measured from the population still in play, and a
     * stage's drop-off is the share of the deals that reached it and did not
     * move past it. Closed deals are left out - a won or lost deal left the
     * funnel deliberately, which is an outcome rather than a leak.
     *
     * Deals that reached a stage are the deals sitting in it plus every deal
     * sitting in a LATER stage of the same pipeline; the deals that stalled are
     * the ones still there. A deal in an earlier stage has not reached this one
     * yet, so it counts towards neither figure.
     *
     * @return array<int, PipelineLeakageSummary> Empty when no stage exists.
     */
    public function getStageLeakage(): array
    {
        $stages = DealStage::query()
            ->with('pipeline:id,name')
            ->withCount('deals')
            ->withSum('deals as total_value', 'amount')
            ->orderBy('pipeline_id')
            ->orderBy('order_position')
            ->get(['id', 'pipeline_id', 'name', 'order_position']);

        // Deals still in play, grouped by pipeline and stage. Closed stages are
        // excluded because those deals have left the funnel rather than leaked
        // out of it; the same lower-cased name match the metrics adapter uses.
        $openByStage = Deal::query()
            ->join('deal_stages', 'deals.deal_stage_id', '=', 'deal_stages.id')
            ->whereNotIn(DB::raw('LOWER(deal_stages.name)'), self::CLOSED_STAGE_NAMES_LOWERCASE)
            ->groupBy('deals.pipeline_id', 'deals.deal_stage_id')
            ->pluck(DB::raw('COUNT(*)'), 'deals.deal_stage_id');

        // Ordered stage ids per pipeline, so "a later stage" is a position
        // comparison rather than a numeric id comparison: a pipeline names its
        // own stages and their ids need not run in the order they are worked.
        $stagesByPipeline = $stages->groupBy('pipeline_id');

        return $stages
            ->map(function (DealStage $stage) use ($openByStage, $stagesByPipeline): PipelineLeakageSummary {
                $pipelineStages = $stagesByPipeline->get($stage->pipeline_id, collect());

                // Every stage at or after this one in the same pipeline.
                $atOrBeyond = $pipelineStages->filter(
                    fn(DealStage $candidate) => (int) $candidate->order_position >= (int) $stage->order_position
                );

                $reached = 0;
                foreach ($atOrBeyond as $candidate) {
                    $reached += (int) ($openByStage[$candidate->id] ?? 0);
                }

                $stalled = (int) ($openByStage[$stage->id] ?? 0);

                return new PipelineLeakageSummary(
                    id: (int) $stage->id,
                    pipelineId: (int) $stage->pipeline_id,
                    pipelineName: $stage->pipeline?->name ?? '',
                    name: (string) $stage->name,
                    orderPosition: (int) $stage->order_position,
                    dealsCount: (int) $stage->deals_count,
                    totalValue: round((float) $stage->total_value, 2),
                    dropOffRate: $this->toRate($stalled, $reached),
                );
            })
            ->all();
    }

    /**
     * A part as a percentage of a whole, to the two decimals the figures are
     * reported in. A whole of zero has no percentage, so it reads as zero
     * rather than as a division by zero.
     *
     * @param int $part
     * @param int $whole
     *
     * @return float
     */
    private function toRate(int $part, int $whole): float
    {
        if ($whole === 0) {
            return 0.0;
        }

        return round(($part / $whole) * 100, 2);
    }

    /**
     * Returns the pipeline the workspace treats as primary.
     *
     * The pipelines table marks a default row. Nothing enforces that only one
     * row carries the flag at the schema level, so the lowest id wins when more
     * than one does — the same tiebreak the Deal module uses, so both modules
     * name the same pipeline when the data is loose.
     *
     * @return int|null Null when no pipeline is marked default.
     */
    public function getDefaultPipelineId(): ?int
    {
        $pipelineId = Pipeline::query()
            ->where('is_default', true)
            ->orderBy('id')
            ->value('id');

        if ($pipelineId === null) {
            return null;
        }

        return (int) $pipelineId;
    }
}
