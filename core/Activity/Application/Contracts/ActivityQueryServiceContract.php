<?php

declare(strict_types=1);

namespace Core\Activity\Application\Contracts;

use Core\Activity\Application\DTOs\Requests\ActivityFilterDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * Handles activity read use cases.
 */
interface ActivityQueryServiceContract
{
    /**
     * Returns the activities matching the given filter.
     *
     * @param ActivityFilterDTO $filterDTO
     *
     * @return Result Contains the PaginatedResult of ActivityResponseDTO on success.
     */
    public function getActivities(ActivityFilterDTO $filterDTO): Result;

    /**
     * Returns the paginated activity timeline of one tracked record.
     *
     * @param string $trackableType Morph alias of the tracked record, e.g. "lead".
     * @param int    $trackableId   ID of the tracked record.
     * @param int    $perPage       Page size within the supported bounds.
     *
     * @return Result Contains the PaginatedResult of ActivityResponseDTO on success.
     */
    public function getActivitiesForTrackable(
        string $trackableType,
        int $trackableId,
        int $perPage = 15
    ): Result;

    /**
     * Returns a single activity by its ID.
     *
     * @param int $id
     *
     * @return Result Contains the ActivityResponseDTO on success.
     */
    public function getActivityById(int $id): Result;
}
