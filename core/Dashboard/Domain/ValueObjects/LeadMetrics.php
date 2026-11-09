<?php

declare(strict_types=1);

namespace Core\Dashboard\Domain\ValueObjects;

/**
 * Figures for the leads the dashboard reports on: how many were captured, how
 * many of them converted, and what share that is.
 */
final readonly class LeadMetrics
{
    /**
     * @param int   $total
     * @param int   $converted
     * @param float $conversionRate Percentage of the captured leads that converted.
     */
    public function __construct(
        public int $total,
        public int $converted,
        public float $conversionRate,
    ) {}
}
