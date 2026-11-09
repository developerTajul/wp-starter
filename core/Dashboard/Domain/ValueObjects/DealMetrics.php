<?php

declare(strict_types=1);

namespace Core\Dashboard\Domain\ValueObjects;

/**
 * Figures for the deals the dashboard reports on: how many sit in each state,
 * what the won and open ones are worth, and the share of the closed ones that
 * were won.
 */
final readonly class DealMetrics
{
    /**
     * @param int   $total
     * @param int   $won
     * @param int   $lost
     * @param int   $open
     * @param float $wonRevenue
     * @param float $openPipelineValue
     * @param float $winRate Percentage of the closed deals that were won.
     */
    public function __construct(
        public int $total,
        public int $won,
        public int $lost,
        public int $open,
        public float $wonRevenue,
        public float $openPipelineValue,
        public float $winRate,
    ) {}
}
