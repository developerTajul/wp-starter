<?php

declare(strict_types=1);

namespace Core\Activity\Infrastructure\Repositories;

use App\Models\Activity;
use Core\Activity\Application\Contracts\ActivityQueryContract;
use Core\Activity\Application\DTOs\Responses\ActivityProjectionDTO;
use Core\Activity\Domain\Enums\ActivityPeriod;
use Core\Activity\Domain\Enums\ActivityStatus;
use Core\Activity\Domain\ValueObjects\ActivityFilter;
use Core\Activity\Infrastructure\Mappers\ActivityEntityMapper;
use Core\Shared\Domain\DataObjects\PaginatedResult;

/**
 * Eloquent-backed reads for the activity list, timeline and detail. Returns
 * read models only; it holds no write path.
 *
 * @implements \Core\Activity\Application\Contracts\ActivityQueryContract
 */
final class ActivityReadRepository implements ActivityQueryContract
{
    /**
     * @param ActivityEntityMapper $mapper
     */
    public function __construct(
        private readonly ActivityEntityMapper $mapper,
    ) {}

    /**
     * @param ActivityFilter $filter
     *
     * @return PaginatedResult
     */
    public function getFilteredActivities(ActivityFilter $filter): PaginatedResult
    {
        $query = Activity::with(['user', 'trackable']);

        if ($filter->status === ActivityStatus::PENDING) {
            $query->whereNull('completed_at');
        } elseif ($filter->status === ActivityStatus::COMPLETED) {
            $query->whereNotNull('completed_at');
        }

        if ($filter->type !== null) {
            $query->where('type', $filter->type->value);
        }

        if ($filter->userId !== null) {
            $query->where('user_id', $filter->userId);
        }

        // The pair is validated in ActivityFilter, so both halves are set by
        // the time they reach here. Scopes the list to one tracked record,
        // which is how the lead activity timeline is read. The column holds
        // morph aliases throughout: rows written before the morph map existed
        // were normalized to the alias by the migration, so one equality match
        // covers every row and uses the composite index on the pair.
        if ($filter->trackableType !== null && $filter->trackableId !== null) {
            $query->where('trackable_type', $filter->trackableType)
                  ->where('trackable_id', $filter->trackableId);
        }

        if ($filter->period === ActivityPeriod::TODAY) {
            // due_date is stored as a UTC wall clock, so "today" is the UTC day
            // and the comparison stays a half-open range rather than whereDate,
            // which would apply the connection's timezone to the column.
            $dayStart = now()->startOfDay()->utc();

            $query->where('due_date', '>=', $dayStart)
                  ->where('due_date', '<', $dayStart->copy()->addDay());
        } elseif ($filter->period === ActivityPeriod::OVERDUE) {
            // SQL form of ActivityEntity::isOverdue(): pending and past due.
            $query->whereNull('completed_at')
                  ->where('due_date', '<', now());
        }

        // Ordering is chosen by whether the filter names a tracked record. A
        // record's timeline is read newest-first, so it sorts on created_at:
        // due_date is nullable, and a sort on it would leave every undated
        // activity in an unstable position rather than in the order it happened.
        // The unfiltered list keeps its due-date agenda order.
        if ($filter->trackableType !== null && $filter->trackableId !== null) {
            $query->orderBy('created_at', 'desc');
        } else {
            $query->orderBy('due_date', 'asc');
        }

        // The id tie-breaks rows sharing a sort key. Without it the order of
        // equal keys is undefined, so a paginated offset can repeat a row on
        // one page and skip another. Two rows created in the same second are
        // an ordinary case, so the timeline needs it as much as the agenda.
        $eloquentPaginator = $query
            ->orderBy('id', 'desc')
            ->paginate($filter->perPage);

        $entities = $eloquentPaginator
            ->getCollection()
            ->map(fn(Activity $item) => $this->mapper->toProjection($item->toArray()))
            ->all();

        return new PaginatedResult(
            items: $entities,
            total: $eloquentPaginator->total(),
            currentPage: $eloquentPaginator->currentPage(),
            perPage: $eloquentPaginator->perPage(),
            lastPage: $eloquentPaginator->lastPage(),
        );
    }

    /**
     * @param int $id
     *
     * @return ActivityProjectionDTO|null
     */
    public function findById(int $id): ?ActivityProjectionDTO
    {
        $activityModel = Activity::with(['user', 'trackable'])->find($id);

        if (! $activityModel) {
            return null;
        }

        return $this->mapper->toProjection($activityModel->toArray());
    }
}
