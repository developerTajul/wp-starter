<?php

declare(strict_types=1);

namespace Core\Company\Infrastructure\Repositories;

use App\Models\Company;
use Core\Company\Domain\Contracts\CompanyRepositoryContract;
use Core\Company\Domain\Entities\CompanyEntity;
use Core\Company\Domain\Exceptions\CompanyDuplicateException;
use Core\Company\Domain\Exceptions\CompanyNotFoundException;
use Core\Company\Infrastructure\Mappers\CompanyEntityMapper;
use Core\Shared\Infrastructure\Repositories\BaseRepository;
use Illuminate\Database\QueryException;

/**
 * Eloquent-backed writes for companies. Every method returns the domain entity,
 * never a read model.
 *
 * @implements CompanyRepositoryContract
 */
final class CompanyWriteRepository extends BaseRepository implements CompanyRepositoryContract
{
    /**
     * SQLSTATE for an integrity constraint violation - a unique index rejecting
     * a duplicate row.
     */
    private const SQLSTATE_INTEGRITY_VIOLATION = '23000';

    /**
     * @param Company             $model
     * @param CompanyEntityMapper $mapper
     */
    public function __construct(
        Company $model,
        private readonly CompanyEntityMapper $mapper,
    ) {
        parent::__construct($model);
    }

    /**
     * Inserts a company that has no ID yet.
     *
     * @param CompanyEntity $company
     *
     * @return CompanyEntity
     *
     * @throws CompanyDuplicateException When a unique index rejects the row.
     */
    public function create(CompanyEntity $company): CompanyEntity
    {
        try {
            $companyModel = $this->createRecord($this->mapper->toPersistenceArray($company));
        } catch (QueryException $exception) {
            throw $this->translateWriteFailure($exception);
        }

        return $this->mapper->toEntity($companyModel->toArray());
    }

    /**
     * Writes a stored company's current state back to its row.
     *
     * @param CompanyEntity $company
     *
     * @return CompanyEntity
     *
     * @throws CompanyNotFoundException When the entity carries an ID that no longer exists.
     * @throws CompanyDuplicateException When a unique index rejects the row.
     */
    public function update(CompanyEntity $company): CompanyEntity
    {
        $companyModel = $this->resolveRecord($company);

        try {
            $companyModel->fill($this->mapper->toPersistenceArray($company));
            $companyModel->save();
        } catch (QueryException $exception) {
            throw $this->translateWriteFailure($exception);
        }

        return $this->mapper->toEntity($companyModel->toArray());
    }

    /**
     * Resolves the row to write: a fresh instance for an insert, otherwise the
     * existing row.
     *
     * @param CompanyEntity $company
     *
     * @return Company
     *
     * @throws CompanyNotFoundException When the entity carries an ID that no longer exists.
     */
    private function resolveRecord(CompanyEntity $company): Company
    {
        if ($company->id === null) {
            return new Company();
        }

        $companyModel = $this->findRecord($company->id);

        if (! $companyModel) {
            throw new CompanyNotFoundException($company->id);
        }

        return $companyModel;
    }

    /**
     * Reads a company while holding a row lock, so a concurrent write cannot
     * slip between the read and the save.
     *
     * @param int $companyId
     *
     * @return CompanyEntity|null Null when no company carries the id.
     */
    public function findByIdForUpdate(int $companyId): ?CompanyEntity
    {
        $companyModel = $this->findRecordForUpdate($companyId);

        if (! $companyModel instanceof Company) {
            return null;
        }

        return $this->mapper->toEntity($companyModel->toArray());
    }

    /**
     * Deletes a company.
     *
     * @param int $companyId
     *
     * @return void
     *
     * @throws CompanyNotFoundException When the row no longer exists.
     */
    public function delete(int $companyId): void
    {
        $deleted = $this->deleteRecord($companyId);

        if (! $deleted) {
            throw new CompanyNotFoundException($companyId);
        }
    }

    /**
     * Turns a failed write into the exception the Core is allowed to see.
     *
     * A unique-index violation means the company already exists and becomes the
     * module's own conflict exception. Any other database error is a real write
     * failure, so it keeps its original type rather than being disguised as a
     * duplicate - a caller must not treat an outage as "already exists".
     *
     * @param QueryException $exception
     *
     * @return \Throwable
     */
    private function translateWriteFailure(QueryException $exception): \Throwable
    {
        if ((string) $exception->getCode() === self::SQLSTATE_INTEGRITY_VIOLATION) {
            return new CompanyDuplicateException();
        }

        return $exception;
    }
}
