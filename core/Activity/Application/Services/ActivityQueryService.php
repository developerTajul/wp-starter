<?php

declare(strict_types=1);

namespace Core\Activity\Application\Services;

use Core\Activity\Application\Contracts\ActivityQueryContract;
use Core\Activity\Application\Contracts\ActivityQueryServiceContract;
use Core\Activity\Application\DTOs\Requests\ActivityFilterDTO;
use Core\Activity\Application\DTOs\Responses\ActivityProjectionDTO;
use Core\Activity\Application\Mappers\ActivityResponseMapper;
use Core\Activity\Domain\Exceptions\InvalidActivityException;
use Core\Activity\Domain\ValueObjects\ActivityFilter;
use Core\Activity\Domain\ValueObjects\TrackableType;
use Core\Shared\Application\DTOs\Result;
use Psr\Log\LoggerInterface;

/**
 * Handles activity read use cases.
 *
 * @implements \Core\Activity\Application\Contracts\ActivityQueryServiceContract
 */
final class ActivityQueryService implements ActivityQueryServiceContract
{
    private const ERROR_INVALID = 'ACTIVITY_INVALID';

    private const ERROR_NOT_FOUND = 'ACTIVITY_NOT_FOUND';

    private const ERROR_FETCH_FAILED = 'ACTIVITY_FETCH_FAILED';

    private const ERROR_INDEX_FAILED = 'ACTIVITY_INDEX_FAILED';

    private const DEFAULT_PER_PAGE = 15;

    public function __construct(
        private readonly ActivityQueryContract $activityQuery,
        private readonly ActivityResponseMapper $activityResponseMapper,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Returns the activities matching the given filter.
     *
     * @param ActivityFilterDTO $filterDTO
     *
     * @return Result Contains the PaginatedResult of ActivityResponseDTO on success.
     */
    public function getActivities(ActivityFilterDTO $filterDTO): Result
    {
        try {
            $activityFilter = new ActivityFilter(
                status: $filterDTO->status,
                type: $filterDTO->type,
                userId: $filterDTO->userId,
                period: $filterDTO->filter,
                perPage: $filterDTO->perPage
            );
            $paginatedActivities = $this->activityQuery->getFilteredActivities($activityFilter);

            $mappedPaginatedResult = $paginatedActivities->map(
                fn(ActivityProjectionDTO $projection) => $this->activityResponseMapper->toResponseDTO($projection)
            );

            return Result::success(
                data: $mappedPaginatedResult,
                message: 'Activities retrieved successfully.'
            );
        } catch (InvalidActivityException $exception) {
            // A rejected filter is bad input, not a system failure.
            $this->logger->warning('Activity list rejected an invalid filter.', [
                'message' => $exception->getMessage(),
            ]);

            return Result::failure($exception->getMessage(), self::ERROR_INVALID);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to retrieve activities.', ['exception' => $exception]);
            return Result::failure('Failed to retrieve activities.', self::ERROR_INDEX_FAILED);
        }
    }

    /**
     * Returns the paginated activity timeline of one tracked record.
     *
     * The alias is resolved against the module's own allowlist before it
     * reaches the filter, so a caller cannot use this to read activities
     * attached to an arbitrary morph type.
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
        int $perPage = self::DEFAULT_PER_PAGE
    ): Result {
        try {
            $alias = TrackableType::fromString($trackableType)->toString();

            $activityFilter = new ActivityFilter(
                trackableType: $alias,
                trackableId: $trackableId,
                perPage: $perPage,
            );
        } catch (InvalidActivityException $exception) {
            // A rejected alias or page size is bad input, not a system failure.
            $this->logger->warning('Activity timeline rejected its criteria.', [
                'trackable_type' => $trackableType,
                'trackable_id' => $trackableId,
                'message' => $exception->getMessage(),
            ]);

            return Result::failure($exception->getMessage(), self::ERROR_INVALID);
        }

        try {
            $paginatedActivities = $this->activityQuery->getFilteredActivities($activityFilter);

            // Mapped through the existing response mapper, so both read paths
            // produce the same wire shape.
            $mappedPaginatedResult = $paginatedActivities->map(
                fn(ActivityProjectionDTO $projection) => $this->activityResponseMapper->toResponseDTO($projection)
            );

            return Result::success(
                data: $mappedPaginatedResult,
                message: 'Activities retrieved successfully.',
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to retrieve the activity timeline.', [
                'trackable_type' => $trackableType,
                'trackable_id' => $trackableId,
                'exception' => $exception,
            ]);

            return Result::failure('Failed to retrieve activities.', self::ERROR_INDEX_FAILED);
        }
    }

    /**
     * Returns a single activity by its ID.
     *
     * @param int $id
     *
     * @return Result Contains the ActivityResponseDTO on success.
     */
    public function getActivityById(int $id): Result
    {
        try {
            $activityProjection = $this->activityQuery->findById($id);

            if (! $activityProjection) {
                return Result::failure('Activity not found.', self::ERROR_NOT_FOUND);
            }

            $activityResponseDTO = $this->activityResponseMapper->toResponseDTO($activityProjection);

            return Result::success(
                data: $activityResponseDTO,
                message: 'Activity retrieved successfully.'
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to retrieve activity.', ['id' => $id, 'exception' => $exception]);
            return Result::failure('Failed to retrieve activity.', self::ERROR_FETCH_FAILED);
        }
    }
}
