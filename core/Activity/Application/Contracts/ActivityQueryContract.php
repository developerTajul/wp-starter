<?php

declare(strict_types=1);

namespace Core\Activity\Application\Contracts;

use Core\Activity\Application\DTOs\Responses\ActivityProjectionDTO;
use Core\Activity\Domain\ValueObjects\ActivityFilter;
use Core\Shared\Domain\DataObjects\PaginatedResult;

/**
 * Read side of the activity persistence port. Returns Application read models,
 * so it lives in the Application layer.
 */
interface ActivityQueryContract
{
    /**
     * Returns the activities matching the given filter criteria.
     *
     * @param ActivityFilter $filter
     *
     * @return PaginatedResult Items are ActivityProjectionDTO instances.
     */
    public function getFilteredActivities(ActivityFilter $filter): PaginatedResult;

    /**
     * Finds an activity by its ID, with its relations loaded.
     *
     * @param int $id
     *
     * @return ActivityProjectionDTO|null
     */
    public function findById(int $id): ?ActivityProjectionDTO;
}
