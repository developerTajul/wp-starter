<?php

declare(strict_types=1);

namespace Core\Dashboard\Domain\Contracts;

use Core\Dashboard\Domain\DataObjects\DashboardMetrics;

/**
 * Read side of the dashboard's metrics port. The figures span the deals, leads
 * and activities modules, so no single module repository can answer them.
 */
interface DashboardMetricsContract
{
    /**
     * Returns the deals, leads and activities figures the dashboard reports.
     *
     * @return DashboardMetrics
     */
    public function getMetrics(): DashboardMetrics;
}
