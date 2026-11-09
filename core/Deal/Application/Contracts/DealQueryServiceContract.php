<?php

declare(strict_types=1);

namespace Core\Deal\Application\Contracts;

use Core\Deal\Application\DTOs\Requests\DealFilterDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * Handles deal read use cases.
 */
interface DealQueryServiceContract
{
    /**
     * Returns the paginated deal list matching the given criteria.
     *
     * @param DealFilterDTO $dealFilterDTO
     *
     * @return Result Contains the PaginatedResult of DealResponseDTO on success.
     */
    public function getPaginatedDeals(DealFilterDTO $dealFilterDTO): Result;

    /**
     * Returns one deal with everything attached to it.
     *
     * @param int $id
     *
     * @return Result Contains the DealDetailResponseDTO on success.
     */
    public function getDealDetails(int $id): Result;
}
