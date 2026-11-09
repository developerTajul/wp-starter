<?php

declare(strict_types=1);

namespace Core\Deal\Infrastructure\Repositories;

use App\Models\Deal;
use Core\Deal\Domain\Contracts\DealRepositoryContract;
use Core\Deal\Domain\Entities\DealEntity;
use Core\Deal\Domain\Exceptions\DealDuplicateException;
use Core\Deal\Infrastructure\Mappers\DealEntityMapper;
use Core\Shared\Infrastructure\Repositories\BaseRepository;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Eloquent-backed writes for deals. Every method takes or returns the domain
 * entity, never a read model.
 *
 * @implements \Core\Deal\Domain\Contracts\DealRepositoryContract
 */
final class DealWriteRepository extends BaseRepository implements DealRepositoryContract
{
    /**
     * SQLSTATE for an integrity constraint violation - a unique index rejecting
     * the row.
     */
    private const SQLSTATE_INTEGRITY_VIOLATION = '23000';

    /**
     * Rows read under a lock, keyed by deal ID. The lock read keeps the model
     * here so the update() that follows writes back to the same instance
     * instead of fetching the row a second time.
     *
     * @var array<int, Deal>
     */
    private array $lockedModels = [];

    /**
     * @param Deal             $model
     * @param DealEntityMapper $mapper
     */
    public function __construct(
        Deal $model,
        private readonly DealEntityMapper $mapper,
    ) {
        parent::__construct($model);
    }

    /**
     * Locks the row and returns its entity. The locked model is kept so the
     * update() that follows writes back to this same row.
     *
     * @param int $id
     *
     * @return DealEntity|null
     */
    public function findByIdForUpdate(int $id): ?DealEntity
    {
        $dealModel = $this->findRecordForUpdate($id);

        if (! $dealModel) {
            return null;
        }

        $this->lockedModels[$id] = $dealModel;

        return $this->mapper->toEntity($dealModel->toArray());
    }

    /**
     * Inserts a new deal. The entity carries no ID, so a fresh row is written.
     *
     * @param DealEntity $deal
     *
     * @return DealEntity
     *
     * @throws RuntimeException        When the row could not be written.
     * @throws DealDuplicateException  When a unique index rejects the row.
     */
    public function create(DealEntity $deal): DealEntity
    {
        return $this->write(deal: $deal, dealModel: new Deal());
    }

    /**
     * Writes an existing deal back to its row, reusing the model the lock read
     * produced when there is one.
     *
     * @param DealEntity $deal
     *
     * @return DealEntity
     *
     * @throws RuntimeException        When the entity carries an ID that no longer exists.
     * @throws DealDuplicateException  When a unique index rejects the row.
     */
    public function update(DealEntity $deal): DealEntity
    {
        return $this->write(deal: $deal, dealModel: $this->takeLockedRecord($deal));
    }

    /**
     * Fills and persists the given row from the entity's state.
     *
     * @param DealEntity $deal
     * @param Deal       $dealModel
     *
     * @return DealEntity
     *
     * @throws RuntimeException        When the row could not be written.
     * @throws DealDuplicateException  When a unique index rejects the row.
     */
    private function write(DealEntity $deal, Deal $dealModel): DealEntity
    {
        try {
            $dealModel->fill($this->mapper->toPersistenceArray($deal));
            $dealModel->save();
        } catch (QueryException $exception) {
            throw $this->translateWriteFailure($exception);
        }

        return $this->mapper->toEntity($dealModel->toArray());
    }

    /**
     * The row an update writes to: the one a lock read already produced for this
     * ID, otherwise a freshly fetched row.
     *
     * The cache is consumed once and the entry dropped, so a later update() reads
     * current state rather than a stale model. An entry left by a rolled-back
     * transaction is dropped by the next lock read for that ID.
     *
     * @param DealEntity $deal
     *
     * @return Deal
     *
     * @throws RuntimeException When the entity carries an ID that no longer exists.
     */
    private function takeLockedRecord(DealEntity $deal): Deal
    {
        $id = (int) $deal->getId();

        if (isset($this->lockedModels[$id])) {
            $dealModel = $this->lockedModels[$id];

            unset($this->lockedModels[$id]);

            return $dealModel;
        }

        $dealModel = $this->findRecord($id);

        if (! $dealModel) {
            throw new RuntimeException(sprintf(
                'Deal with ID %d no longer exists.',
                $id,
            ));
        }

        return $dealModel;
    }

    /**
     * Deletes a deal. The locked row is dropped either way: a delete has no
     * further use for it, and a failed one must not leave it cached.
     *
     * @param int $id
     *
     * @return void
     *
     * @throws RuntimeException When the row could not be deleted.
     */
    public function delete(int $id): void
    {
        try {
            $deleted = $this->deleteRecord($id);
        } finally {
            unset($this->lockedModels[$id]);
        }

        if (! $deleted) {
            throw new RuntimeException(sprintf(
                'Deal with ID %d could not be deleted.',
                $id,
            ));
        }
    }

    /**
     * Turns a failed write into the exception Core is allowed to see.
     *
     * A unique-index violation becomes the module's conflict exception. Any other
     * database error keeps its original type, so a caller cannot mistake an outage
     * for "already exists".
     *
     * @param QueryException $exception
     *
     * @return \Throwable
     */
    private function translateWriteFailure(QueryException $exception): \Throwable
    {
        if ((string) $exception->getCode() === self::SQLSTATE_INTEGRITY_VIOLATION) {
            return new DealDuplicateException();
        }

        return $exception;
    }
}
