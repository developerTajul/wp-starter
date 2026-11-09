<?php

declare(strict_types=1);

namespace Core\Dashboard\Domain\DataObjects;

use Core\Dashboard\Domain\ValueObjects\ActivityMetrics;
use Core\Dashboard\Domain\ValueObjects\DealMetrics;
use Core\Dashboard\Domain\ValueObjects\LeadMetrics;

/**
 * Read model for the whole executive overview: one figure set per module the
 * dashboard reports on.
 */
final readonly class DashboardMetrics
{
    /**
     * @param DealMetrics     $deals
     * @param LeadMetrics     $leads
     * @param ActivityMetrics $activities
     */
    public function __construct(
        public DealMetrics $deals,
        public LeadMetrics $leads,
        public ActivityMetrics $activities,
    ) {}
}
