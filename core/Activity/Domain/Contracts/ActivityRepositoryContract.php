<?php

declare(strict_types=1);

namespace Core\Activity\Domain\Contracts;

use Core\Activity\Domain\Entities\ActivityEntity;

/**
 * Write side of the activity persistence port. Read operations live in
 * Core\Activity\Application\Contracts\ActivityQueryContract, which returns
 * Application read models.
 */
interface ActivityRepositoryContract
{
    /**
     * Persists the activity. An entity without an ID is inserted, one that
     * already carries an ID is updated in place.
     *
     * @param ActivityEntity $activity
     *
     * @return ActivityEntity
     */
    public function save(ActivityEntity $activity): ActivityEntity;

    /**
     * Finds the entity by its ID with a row lock, for use inside a transaction.
     *
     * @param int $id
     *
     * @return ActivityEntity|null
     */
    public function findByIdForUpdate(int $id): ?ActivityEntity;

    /**
     * Deletes an activity.
     *
     * @param ActivityEntity $activity
     *
     * @return void
     */
    public function delete(ActivityEntity $activity): void;

    /**
     * Drops the row held from a lock read, for a write that never reaches
     * save(). Called once the transaction has ended, so a rolled-back read
     * cannot be written back later.
     *
     * @param int $id
     *
     * @return void
     */
    public function clearLockedRecord(int $id): void;
}
