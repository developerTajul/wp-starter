<?php

declare(strict_types=1);

namespace Core\Dashboard\Application\Mappers;

use Core\Dashboard\Application\Contracts\DashboardMapperContract;
use Core\Dashboard\Application\DTOs\Requests\DashboardFilterDTO;
use Core\Dashboard\Domain\DashboardRules;

/**
 * Builds the dashboard's request DTOs.
 *
 * @implements DashboardMapperContract
 */
final class DashboardMapper implements DashboardMapperContract
{
    /**
     * Builds the filter DTO. The sizes come from the module's rules rather than
     * from the request, so a client cannot ask the feed for more rows than the
     * panel is built to show.
     *
     * @return DashboardFilterDTO
     */
    public function mapToFilterDTO(): DashboardFilterDTO
    {
        return new DashboardFilterDTO(
            feedLimit: DashboardRules::FEED_LIMIT,
            boardLimit: DashboardRules::BOARD_LIMIT,
            overdueLimit: DashboardRules::OVERDUE_LIMIT,
        );
    }
}
