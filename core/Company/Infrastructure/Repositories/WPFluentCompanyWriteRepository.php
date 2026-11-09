<?php

declare(strict_types=1);

namespace Core\Company\Infrastructure\Repositories;

use Core\Company\Domain\Contracts\CompanyRepositoryContract;
use Core\Company\Domain\Entities\CompanyEntity;
use Core\Company\Domain\Exceptions\CompanyDuplicateException;
use Core\Company\Domain\Exceptions\CompanyNotFoundException;
use Core\Company\Infrastructure\Mappers\CompanyEntityMapper;
use Framework\Database\Query\Exceptions\UniqueConstraintException;
use Framework\Database\Query\QueryBuilderInterface;
use RuntimeException;

/**
 * Fluent-builder writes for companies. Every method takes and returns the
 * domain entity, never a read model.
 *
 * @implements CompanyRepositoryContract
 */
final class WPFluentCompanyWriteRepository implements CompanyRepositoryContract
{
    /** The table's name without the WordPress prefix. */
    private const TABLE_SUFFIX = 'companies';

    /**
     * The columns a read-back selects, in a fixed order.
     *
     * A named list rather than `*`: the entity mapper's shape stays coupled to
     * this constant, so a renamed column fails at the mapper rather than
     * arriving as a missing key.
     */
    private const COLUMNS = [
        'id', 'name', 'website', 'industry', 'phone', 'address', 'created_at', 'updated_at',
    ];

    /**
     * @param QueryBuilderInterface $query The fluent builder, bound to the
     *                                     WordPress connection by its provider.
     * @param CompanyEntityMapper   $mapper
     */
    public function __construct(
        private readonly QueryBuilderInterface $query,
        private readonly CompanyEntityMapper $mapper,
    ) {
    }

    /**
     * Inserts a company that has no ID yet.
     *
     * @param CompanyEntity $company
     *
     * @return CompanyEntity The stored row, carrying its new ID.
     *
     * @throws CompanyDuplicateException When a unique index rejects the row.
     * @throws RuntimeException         When the insert fails for any other reason.
     */
    public function create(CompanyEntity $company): CompanyEntity
    {
        $now = $this->now();

        // Timestamps are written explicitly rather than left to the column's
        // DEFAULT: a MySQL default is evaluated in the server's timezone, and
        // every other timestamp this project writes is UTC.
        $id = $this->insert(
            [
                ...$this->mapper->toPersistenceArray($company),
                'created_at' => $now,
                'updated_at' => $now,
            ],
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
     * @throws CompanyNotFoundException When the entity carries an ID that no longer exists.
     * @throws CompanyDuplicateException When a unique index rejects the row.
     * @throws RuntimeException         When the update fails for any other reason.
     */
    public function update(CompanyEntity $company): CompanyEntity
    {
        $companyId = $this->storedId($company);

        $this->write(
            $companyId,
            [...$this->mapper->toPersistenceArray($company), 'updated_at' => $this->now()],
            sprintf('update company %d', $companyId)
        );

        return $this->readBack($companyId);
    }

    /**
     * Reads a company while holding a row lock, so a concurrent write cannot
     * slip between the read and the save.
     *
     * @param int $companyId
     *
     * @return CompanyEntity|null Null when no company carries the id.
     *
     * @throws RuntimeException When the read fails.
     */
    public function findByIdForUpdate(int $companyId): ?CompanyEntity
    {
        // `FOR UPDATE` needs a transaction to mean anything. Read outside one,
        // MySQL releases the lock as the statement's implicit transaction ends,
        // and the call becomes an ordinary read that looks safe without being
        // so - which is why the Command Service wraps every read-then-write in
        // `TransactionManagerContract::run()`.
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
     * @param int $companyId
     *
     * @return void
     *
     * @throws CompanyNotFoundException When the row no longer exists.
     * @throws RuntimeException         When the delete fails.
     */
    public function delete(int $companyId): void
    {
        try {
            $deleted = $this->query
                ->table(self::TABLE_SUFFIX)
                ->where('id', $companyId)
                ->delete();
        } catch (UniqueConstraintException $exception) {
            throw new CompanyDuplicateException();
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                sprintf('Failed to delete company %d: %s', $companyId, $exception->getMessage()),
                0,
                $exception
            );
        }

        // A zero-row delete means the row was removed between the caller loading
        // it and this statement running, which is a not-found outcome rather
        // than a silent success.
        if ($deleted < 1) {
            throw new CompanyNotFoundException($companyId);
        }
    }

    /**
     * Runs an insert and returns the new id.
     *
     * The builder refuses an empty column list and reports a duplicate through
     * its own exception, so both failures are translated here rather than
     * crossing into Core.
     *
     * @param array<string, mixed> $values
     * @param string               $action Phrased for the failure message.
     *
     * @return int
     *
     * @throws CompanyDuplicateException When a unique index rejects the row.
     * @throws RuntimeException         When the insert fails for any other reason.
     */
    private function insert(array $values, string $action): int
    {
        try {
            return $this->query->table(self::TABLE_SUFFIX)->insertGetId($values);
        } catch (UniqueConstraintException $exception) {
            throw new CompanyDuplicateException();
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                sprintf('Failed to %s: %s', $action, $exception->getMessage()),
                0,
                $exception
            );
        }
    }

    /**
     * Runs an update over one row and confirms the row was there.
     *
     * The builder reports rows *changed*, not rows *matched*, so a save that
     * writes the values already stored returns 0 without anything being wrong.
     * The missing row is confirmed with a separate existence check rather than
     * read into a zero.
     *
     * @param int                  $companyId
     * @param array<string, mixed> $values
     * @param string               $action    Phrased for the failure message.
     *
     * @return void
     *
     * @throws CompanyNotFoundException When the row no longer exists.
     * @throws CompanyDuplicateException When a unique index rejects the row.
     * @throws RuntimeException         When the update fails for any other reason.
     */
    private function write(int $companyId, array $values, string $action): void
    {
        try {
            $changed = $this->query
                ->table(self::TABLE_SUFFIX)
                ->where('id', $companyId)
                ->update($values);
        } catch (UniqueConstraintException $exception) {
            throw new CompanyDuplicateException();
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                sprintf('Failed to %s: %s', $action, $exception->getMessage()),
                0,
                $exception
            );
        }

        if ($changed === 0 && ! $this->rowExists($companyId)) {
            throw new CompanyNotFoundException($companyId);
        }
    }

    /**
     * Reads back a row just written, so the entity carries the stored state.
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
     * Whether a row with the given id exists.
     *
     * @param int $companyId
     *
     * @return bool
     */
    private function rowExists(int $companyId): bool
    {
        return $this->query
            ->table(self::TABLE_SUFFIX)
            ->where('id', $companyId)
            ->count() > 0;
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

    /**
     * The current time, as the UTC string the columns store.
     *
     * Converted rather than taken from `current_time()` or `date()`: the first
     * returns the site's configured timezone, the second the process's, and
     * neither is the UTC every other timestamp in this project is written in.
     *
     * @return string
     */
    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s');
    }
}
