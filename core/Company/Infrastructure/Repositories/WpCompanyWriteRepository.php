<?php

declare(strict_types=1);

namespace Core\Company\Infrastructure\Repositories;

use Core\Company\Domain\Contracts\CompanyRepositoryContract;
use Core\Company\Domain\Entities\CompanyEntity;
use Core\Company\Domain\Exceptions\CompanyDuplicateException;
use Core\Company\Domain\Exceptions\CompanyNotFoundException;
use Core\Company\Infrastructure\Mappers\CompanyEntityMapper;
use Core\Shared\Infrastructure\Repositories\BaseRepository;
use Framework\Database\Query\Exceptions\UniqueConstraintException;
use Framework\Database\Query\QueryBuilderInterface;
use RuntimeException;
use Throwable;

/**
 * Fluent-builder writes for companies.
 *
 * @implements CompanyRepositoryContract
 */
final class WpCompanyWriteRepository extends BaseRepository implements CompanyRepositoryContract
{
    /** The table's name without the WordPress prefix. */
    private const TABLE_SUFFIX = 'companies';

    /**
     * The columns a read-back selects, in a fixed order.
     */
    private const COLUMNS = [
        'id', 'name', 'website', 'industry', 'phone', 'address', 'created_at', 'updated_at',
    ];

    /**
     * @param QueryBuilderInterface $query  The fluent builder, bound to the
     *                                       WordPress connection by its provider.
     * @param CompanyEntityMapper    $mapper
     */
    public function __construct(
        QueryBuilderInterface $query,
        private readonly CompanyEntityMapper $mapper,
    ) {
        parent::__construct($query);
    }

    /**
     * Reports a rejected unique index as the module's conflict.
     *
     * @return \Throwable
     */
    protected function duplicateException(UniqueConstraintException $exception): Throwable
    {
        return new CompanyDuplicateException(previous: $exception);
    }

    /**
     * Inserts a company that has no ID yet.
     *
     * @param CompanyEntity $company
     *
     * @return CompanyEntity The stored row, carrying its new ID.
     *
     * @throws CompanyDuplicateException When a unique index rejects the row.
     * @throws RuntimeException          When the insert fails.
     */
    public function create(CompanyEntity $company): CompanyEntity
    {
        $now = $this->now();

        $id = $this->insert(
            [
                ...$this->mapper->toPersistenceArray($company),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            self::TABLE_SUFFIX,
            'insert into ' . self::TABLE_SUFFIX
        );

        return $this->readBack($id);
    }

    /**
     * Writes a stored company's current state back to its row.
     *
     * @param CompanyEntity $company
     *
     * @return CompanyEntity The stored row.
     *
     * @throws CompanyNotFoundException When the entity has no id.
     * @throws CompanyDuplicateException When a unique index rejects the row.
     * @throws RuntimeException          When the update fails.
     */
    public function update(CompanyEntity $company): CompanyEntity
    {
        $companyId = $this->storedId($company);

        $this->write(
            $companyId,
            [...$this->mapper->toPersistenceArray($company), 'updated_at' => $this->now()],
            self::TABLE_SUFFIX,
            sprintf('update company %d', $companyId)
        );

        return $this->readBack($companyId);
    }

    /**
     * Reads a company while holding a row lock.
     *
     * @param int $companyId
     *
     * @return CompanyEntity|null Null when no company carries the id.
     *
     * @throws RuntimeException When the read fails.
     */
    public function findByIdForUpdate(int $companyId): ?CompanyEntity
    {
        $row = $this->query
            ->table(self::TABLE_SUFFIX)
            ->select(...self::COLUMNS)
            ->where('id', $companyId)
            ->lockForUpdate()
            ->first();

        return $row === null ? null : $this->mapper->toEntity($row);
    }

    /**
     * Deletes a company.
     *
     * A zero-row delete means the row was removed between the caller loading it
     * and this statement running, which is a not-found outcome rather than a
     * silent success.
     *
     * @param int $companyId
     *
     * @return void
     *
     * @throws CompanyNotFoundException When the row no longer exists.
     * @throws RuntimeException         When the delete fails.
     */
    public function delete(int $companyId): void
    {
        $deleted = $this->executeQuery(
            sprintf('delete company %d', $companyId),
            fn(): int => $this->query->table(self::TABLE_SUFFIX)->where('id', $companyId)->delete()
        );

        if ($deleted < 1) {
            throw new CompanyNotFoundException($companyId);
        }
    }

    /**
     * Reads back a row just written.
     *
     * @param int $companyId
     *
     * @return CompanyEntity
     *
     * @throws RuntimeException When the row cannot be read back.
     */
    private function readBack(int $companyId): CompanyEntity
    {
        $row = $this->query
            ->table(self::TABLE_SUFFIX)
            ->select(...self::COLUMNS)
            ->where('id', $companyId)
            ->first();

        if ($row === null) {
            throw new RuntimeException(sprintf(
                'The row for company %d could not be read back after it was written.',
                $companyId
            ));
        }

        return $this->mapper->toEntity($row);
    }

    /**
     * The id an update must target.
     *
     * @param CompanyEntity $company
     *
     * @return int
     *
     * @throws RuntimeException When the entity has never been saved.
     */
    private function storedId(CompanyEntity $company): int
    {
        if ($company->id === null) {
            throw new RuntimeException(
                'An update was attempted for a company with no id. Use create() for a company '
                . 'that has never been saved.'
            );
        }

        return $company->id;
    }
}
