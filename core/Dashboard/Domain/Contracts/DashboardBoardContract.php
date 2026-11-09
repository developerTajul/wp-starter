<?php

declare(strict_types=1);

namespace Core\Dashboard\Domain\Contracts;

use Core\Dashboard\Domain\DataObjects\PipelineBoardSummary;
use Core\Dashboard\Domain\DataObjects\PipelineLeakageSummary;

/**
 * Read side of the dashboard's pipeline overview port: one summary row per
 * stage, across every pipeline, which no single module repository owns.
 */
interface DashboardBoardContract
{
    /**
     * Returns one summary row per stage, ordered by pipeline and then by the
     * order the stages are worked through.
     *
     * @return array<int, PipelineBoardSummary> Empty when no stage exists.
     */
    public function getStageSummaries(): array;

    /**
     * Returns one funnel row per stage, carrying how many deals stalled there
     * rather than moving on.
     *
     * The deals table stores only where a deal sits now, so the funnel is read
     * from the population still in play: the rate is the share of every deal
     * that reached this stage and went no further. Closed deals are excluded,
     * because a won or lost deal has left the funnel rather than leaked out of
     * a stage.
     *
     * @return array<int, PipelineLeakageSummary> Empty when no stage exists.
     */
    public function getStageLeakage(): array;

    /**
     * Returns the pipeline the workspace treats as primary, so the board can
     * open on it rather than on whichever pipeline happens to sort first.
     *
     * Read here rather than through the Deal module's own contract: this module
     * reports on the pipelines table itself and does not widen another module's
     * port with its own question (P7).
     *
     * @return int|null Null when no pipeline is marked default.
     */
    public function getDefaultPipelineId(): ?int;
}
