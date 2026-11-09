<?php

declare(strict_types=1);

namespace Core\Dashboard\Application\DTOs\Requests;

/**
 * Carries the dashboard's size limits into the Service. Built by
 * DashboardMapper from the module's rules, so a delivery layer never states
 * how many rows a panel shows.
 */
final readonly class DashboardFilterDTO
{
    /**
     * @param int $feedLimit
     * @param int $boardLimit
     * @param int $overdueLimit
     */
    public function __construct(
        public int $feedLimit,
        public int $boardLimit,
        public int $overdueLimit,
    ) {}
}
