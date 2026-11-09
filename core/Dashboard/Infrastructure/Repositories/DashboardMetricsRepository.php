<?php

declare(strict_types=1);

namespace Core\Dashboard\Infrastructure\Repositories;

use App\Models\Activity;
use App\Models\Deal;
use App\Models\Lead;
use Core\Dashboard\Domain\Contracts\DashboardMetricsContract;
use Core\Dashboard\Domain\DataObjects\DashboardMetrics;
use Core\Dashboard\Domain\ValueObjects\ActivityMetrics;
use Core\Dashboard\Domain\ValueObjects\DealMetrics;
use Core\Dashboard\Domain\ValueObjects\LeadMetrics;
use Core\Deal\Domain\DealRules;
use Core\Lead\Domain\Enums\LeadStatus;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent-backed aggregation for the dashboard's headline figures.
 *
 * Reports on the deals, leads and activities tables, so it is deliberately kept
 * out of every module's repository: those own their own writes, and this one
 * only counts.
 *
 * @implements DashboardMetricsContract
 */
final class DashboardMetricsRepository implements DashboardMetricsContract
{
    /**
     * The stage names that close a deal, lower-cased once so the open-value
     * query compares against what the counting queries compare against.
     *
     * @var array<int, string>
     */
    private const CLOSED_STAGE_NAMES_LOWERCASE = [
        'closed won',
        'closed lost',
    ];

    /**
     * Returns the deals, leads and activities figures the dashboard reports.
     *
     * @return DashboardMetrics
     */
    public function getMetrics(): DashboardMetrics
    {
        return new DashboardMetrics(
            deals: $this->getDealMetrics(),
            leads: $this->getLeadMetrics(),
            activities: $this->getActivityMetrics(),
        );
    }

    /**
     * Counts the deals by the state their stage puts them in, and totals what
     * the won and open ones are worth.
     *
     * @return DealMetrics
     */
    private function getDealMetrics(): DealMetrics
    {
        // A deal is won, lost or open according to the name of the stage it
        // sits in. The ids cannot be used: stage 5 is "Closed Won" in the
        // default pipeline and "Closing" in every other one, which is the same
        // reason DealEntity::closesTheDeal() compares names (see DealRules).
        $total = Deal::query()->count();
        $won = $this->countDealsInStage(DealRules::WON_STAGE_NAME);
        $lost = $this->countDealsInStage(DealRules::LOST_STAGE_NAME);
        $open = $total - $won - $lost;

        $wonRevenue = (float) Deal::query()
            ->whereHas('stage', fn($query) => $query->whereRaw('LOWER(name) = ?', [mb_strtolower(DealRules::WON_STAGE_NAME)]))
            ->sum('amount');

        $openPipelineValue = (float) Deal::query()
            ->whereHas('stage', fn($query) => $query->whereNotIn(
                DB::raw('LOWER(name)'),
                self::CLOSED_STAGE_NAMES_LOWERCASE,
            ))
            ->sum('amount');

        return new DealMetrics(
            total: $total,
            won: $won,
            lost: $lost,
            open: $open,
            wonRevenue: round($wonRevenue, 2),
            openPipelineValue: round($openPipelineValue, 2),
            winRate: $this->toRate($won, $won + $lost),
        );
    }

    /**
     * Counts the leads, and what share of them converted.
     *
     * @return LeadMetrics
     */
    private function getLeadMetrics(): LeadMetrics
    {
        $total = Lead::query()->count();
        $converted = Lead::query()
            ->where('status', LeadStatus::CONVERTED->value)
            ->count();

        return new LeadMetrics(
            total: $total,
            converted: $converted,
            conversionRate: $this->toRate($converted, $total),
        );
    }

    /**
     * Counts the activities still outstanding and the ones already done.
     *
     * @return ActivityMetrics
     */
    private function getActivityMetrics(): ActivityMetrics
    {
        return new ActivityMetrics(
            pending: Activity::query()->whereNull('completed_at')->count(),
            completed: Activity::query()->whereNotNull('completed_at')->count(),
        );
    }

    /**
     * How many deals sit in a stage carrying the given name.
     *
     * Matched case-insensitively, because the stages table's unique index does
     * not see case either, so "closed won" and "Closed Won" are one name.
     *
     * @param string $stageName
     *
     * @return int
     */
    private function countDealsInStage(string $stageName): int
    {
        return Deal::query()
            ->whereHas('stage', fn($query) => $query->whereRaw('LOWER(name) = ?', [mb_strtolower($stageName)]))
            ->count();
    }

    /**
     * A part as a percentage of a whole, rounded to the two decimals the
     * metrics are reported in. A whole of zero has no percentage, so it reads
     * as zero rather than as a division by zero.
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
}
