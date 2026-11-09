<?php

declare(strict_types=1);

namespace Core\Dashboard\Application\Contracts;

use Core\Dashboard\Application\DTOs\Requests\DashboardFilterDTO;

/**
 * PORT: maps delivery-layer input to dashboard DTOs.
 */
interface DashboardMapperContract
{
    /**
     * Builds a DashboardFilterDTO carrying the dashboard's size limits.
     *
     * @return DashboardFilterDTO
     */
    public function mapToFilterDTO(): DashboardFilterDTO;
}
