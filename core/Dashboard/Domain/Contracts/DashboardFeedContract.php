<?php

declare(strict_types=1);

namespace Core\Dashboard\Domain\Contracts;

use Core\Dashboard\Domain\DataObjects\ActivityFeedEntry;
use Core\Dashboard\Domain\DataObjects\OverdueActivityEntry;

/**
 * Read side of the dashboard's activity feed port: the activities still due,
 * soonest first, with the record each was logged against already resolved.
 */
interface DashboardFeedContract
{
    /**
     * Returns the outstanding activities the dashboard lists.
     *
     * @param int $limit How many rows to return at most.
     *
     * @return array<int, ActivityFeedEntry> Empty when nothing is outstanding.
     */
    public function getUpcomingActivities(int $limit): array;

    /**
     * Returns the outstanding activities whose due date has already passed,
     * most overdue first.
     *
     * Read here rather than on a port of its own: it is the same table, the
     * same reporting concern and the same resolved record as the feed above,
     * filtered to the rows that are late.
     *
     * @param int $limit How many rows to return at most.
     *
     * @return array<int, OverdueActivityEntry> Empty when nothing is overdue.
     */
    public function getOverdueActivities(int $limit): array;
}
