<?php

declare(strict_types=1);

namespace Core\Activity\Application\Contracts;

use Core\Activity\Application\DTOs\Requests\ActivityCreateDTO;
use Core\Activity\Application\DTOs\Requests\ActivityUpdateDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * Handles activity write use cases.
 */
interface ActivityCommandServiceContract
{
    /**
     * Creates an activity against the record it tracks.
     *
     * @param ActivityCreateDTO $activityCreateDTO
     *
     * @return Result Contains the ActivityResponseDTO on success.
     */
    public function create(ActivityCreateDTO $activityCreateDTO): Result;

    /**
     * Updates an existing activity.
     *
     * @param ActivityUpdateDTO $activityUpdateDTO
     *
     * @return Result Contains the ActivityResponseDTO on success.
     */
    public function update(ActivityUpdateDTO $activityUpdateDTO): Result;

    /**
     * Deletes an existing activity.
     *
     * @param int $id
     *
     * @return Result
     */
    public function deleteActivity(int $id): Result;
}
