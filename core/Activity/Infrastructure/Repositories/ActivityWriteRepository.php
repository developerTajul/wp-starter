<?php

declare(strict_types=1);

namespace Core\Activity\Infrastructure\Repositories;

use App\Models\Activity;
use Core\Activity\Domain\Contracts\ActivityRepositoryContract;
use Core\Activity\Domain\Entities\ActivityEntity;
use Core\Activity\Infrastructure\Mappers\ActivityEntityMapper;
use Core\Shared\Infrastructure\Repositories\BaseRepository;
use RuntimeException;

/**
 * Eloquent-backed writes for activities. Every method takes or returns the
 * domain entity, never a read model.
 *
 * @implements \Core\Activity\Domain\Contracts\ActivityRepositoryContract
 */
final class ActivityWriteRepository extends BaseRepository implements ActivityRepositoryContract
{
    /**
     * Rows read under a lock, keyed by activity ID, so the matching save()
     * writes back to the same instance instead of fetching the row again.
     *
     * @var array<int, Activity>
     */
    private array $lockedModels = [];

    /**
     * @param Activity             $model
     * @param ActivityEntityMapper $mapper
     */
    public function __construct(
        Activity $model,
        private readonly ActivityEntityMapper $mapper,
    ) {
        parent::__construct($model);
    }

    /**
     * Persists the entity, inserting it when it has no ID yet.
     *
     * @param ActivityEntity $activity
     *
     * @return ActivityEntity
     *
     * @throws RuntimeException When the entity carries an ID that no longer exists.
     */
    public function save(ActivityEntity $activity): ActivityEntity
    {
        return $this->write($activity, $this->takeLockedRecord($activity));
    }

    /**
     * Fills and persists the given row from the entity's state.
     *
     * @param ActivityEntity $activity
     * @param Activity       $activityModel
     *
     * @return ActivityEntity
     */
    private function write(ActivityEntity $activity, Activity $activityModel): ActivityEntity
    {
        $activityModel->fill($this->toPersistence($activity));
        $activityModel->save();

        return $this->mapper->toEntity($activityModel->toArray());
    }

    /**
     * Resolves the row to write: the one a lock read produced for this ID, a
     * fresh instance for an insert, or a fetched row for an update.
     *
     * @param ActivityEntity $activity
     *
     * @return Activity
     *
     * @throws RuntimeException When the entity carries an ID that no longer exists.
     */
    private function takeLockedRecord(ActivityEntity $activity): Activity
    {
        $id = $activity->getId();

        if ($id === null) {
            return new Activity();
        }

        // Consumed once, so a later save() for the same ID reads current state
        // rather than reusing a stale model.
        if (isset($this->lockedModels[$id])) {
            $activityModel = $this->lockedModels[$id];

            unset($this->lockedModels[$id]);

            return $activityModel;
        }

        $activityModel = $this->findRecord($id);

        if (! $activityModel) {
            throw new RuntimeException(sprintf(
                'Activity with ID %d no longer exists.',
                (int) $id,
            ));
        }

        return $activityModel;
    }

    /**
     * Locks the row and returns its entity, keeping the model so the save() that
     * follows writes back to this same row.
     *
     * @param int $id
     *
     * @return ActivityEntity|null
     */
    public function findByIdForUpdate(int $id): ?ActivityEntity
    {
        $activityModel = $this->findRecordForUpdate($id);

        if (! $activityModel) {
            return null;
        }

        $this->lockedModels[$id] = $activityModel;

        return $this->mapper->toEntity($activityModel->toArray());
    }

    /**
     * Deletes an activity. The locked row is dropped either way: a delete has no
     * use for it, and a failed one must not leave it cached.
     *
     * @param ActivityEntity $activity
     *
     * @return void
     *
     * @throws RuntimeException When the row could not be deleted.
     */
    public function delete(ActivityEntity $activity): void
    {
        $id = (int) $activity->getId();

        try {
            $deleted = $this->deleteRecord($id);
        } finally {
            unset($this->lockedModels[$id]);
        }

        if (! $deleted) {
            throw new RuntimeException(sprintf(
                'Activity with ID %d could not be deleted.',
                $id,
            ));
        }
    }

    /**
     * Drops the row a lock read is holding. A write that never reaches save()
     * leaves its row cached, so a later save() for that ID would write back a
     * model read before the rollback.
     *
     * @param int $id
     *
     * @return void
     */
    public function clearLockedRecord(int $id): void
    {
        unset($this->lockedModels[$id]);
    }

    /**
     * Flattens the entity's own state onto the activity table's columns.
     *
     * @param ActivityEntity $activity
     *
     * @return array<string, mixed>
     */
    private function toPersistence(ActivityEntity $activity): array
    {
        return [
            'user_id' => $activity->getUserId(),
            'trackable_type' => $activity->getTrackableType()->toString(),
            'trackable_id' => $activity->getTrackableId(),
            'type' => $activity->getType()->value,
            'subject' => $activity->getSubject(),
            'description' => $activity->getDescription(),
            // Stored as UTC so the model's datetime cast reads back the same
            // instant regardless of the app timezone.
            'due_date' => $activity->getDueDate()?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'completed_at' => $activity->getCompletedAt()?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
    }
}
