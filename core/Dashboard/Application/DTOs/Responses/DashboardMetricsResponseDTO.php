<?php

declare(strict_types=1);

namespace Core\Dashboard\Application\DTOs\Responses;

/**
 * Shape the dashboard's headline figures are handed to the delivery layer in:
 * the deal, lead and activity panels the page and the API both print.
 */
final readonly class DashboardMetricsResponseDTO
{
    /**
     * @param int    $totalDeals
     * @param int    $wonDeals
     * @param int    $lostDeals
     * @param int    $openDeals
     * @param float  $winRate
     * @param float  $wonRevenue
     * @param float  $openPipelineValue
     * @param string $currency
     * @param int    $totalLeads
     * @param int    $convertedLeads
     * @param float  $conversionRate
     * @param int    $pendingActivities
     * @param int    $completedActivities
     */
    public function __construct(
        public int $totalDeals,
        public int $wonDeals,
        public int $lostDeals,
        public int $openDeals,
        public float $winRate,
        public float $wonRevenue,
        public float $openPipelineValue,
        public string $currency,
        public int $totalLeads,
        public int $convertedLeads,
        public float $conversionRate,
        public int $pendingActivities,
        public int $completedActivities,
    ) {}
}
