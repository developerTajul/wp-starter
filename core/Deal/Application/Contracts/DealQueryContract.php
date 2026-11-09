<?php

declare(strict_types=1);

namespace Core\Deal\Application\Contracts;

use Core\Deal\Domain\ReadModels\DealProjectionDTO;
use Core\Deal\Domain\ReadModels\StoredDealData;
use Core\Deal\Domain\ValueObjects\DealFilter;
use Core\Shared\Domain\DataObjects\PaginatedResult;

/**
 * Read side of the deal persistence port.
 */
interface DealQueryContract
{
    /**
     * Returns the deals matching the given filter criteria.
     *
     * @param DealFilter $filter
     *
     * @return PaginatedResult Items are DealProjectionDTO instances.
     */
    public function getFilteredDeals(DealFilter $filter): PaginatedResult;

    /**
     * Returns one deal together with the records it points at and the
     * activities logged against it.
     *
     * @param int $id
     *
     * @return StoredDealData|null Null when no deal carries the id.
     */
    public function findDetailById(int $id): ?StoredDealData;
}
