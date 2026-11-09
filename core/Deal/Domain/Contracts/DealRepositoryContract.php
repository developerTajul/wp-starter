<?php

declare(strict_types=1);

namespace Core\Deal\Domain\Contracts;

use Core\Deal\Domain\Entities\DealEntity;

/**
 * Write side of the deal persistence port. Read operations live in
 * Core\Deal\Application\Contracts\DealQueryContract, which returns read models.
 */
interface DealRepositoryContract
{
    /**
     * Finds the entity by its ID with a row lock, for use inside a transaction.
     *
     * @param int $id
     *
     * @return DealEntity|null
     */
    public function findByIdForUpdate(int $id): ?DealEntity;

    /**
     * Inserts a deal. The entity is expected to carry no ID yet.
     *
     * @param DealEntity $deal
     *
     * @return DealEntity The stored deal, rebuilt from storage.
     */
    public function create(DealEntity $deal): DealEntity;

    /**
     * Writes an existing deal back to its row.
     *
     * @param DealEntity $deal
     *
     * @return DealEntity The stored deal, rebuilt from storage.
     */
    public function update(DealEntity $deal): DealEntity;

    /**
     * Deletes a deal. The use case has no need for the row's state, so the ID
     * is all it passes.
     *
     * @param int $id
     *
     * @return void
     */
    public function delete(int $id): void;
}
