<?php

declare(strict_types=1);

namespace Core\Company\Infrastructure\Repositories;

use Core\Company\Domain\Contracts\CompanyRepositoryContract;
use Core\Company\Domain\Entities\CompanyEntity;
use Core\Company\Domain\Exceptions\CompanyDuplicateException;
use Core\Company\Domain\Exceptions\CompanyNotFoundException;
use Core\Company\Infrastructure\Mappers\CompanyEntityMapper;
use Core\Shared\Infrastructure\Repositories\BaseRepository;
use RuntimeException;
use Throwable;
use wpdb;

/**
 * `$wpdb` writes for companies.
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

    private const OUTPUT_ASSOC = ARRAY_A;

    /**
     * @param CompanyEntityMapper $mapper
     * @param wpdb|null $wpdb Defaults to the `$wpdb` global, resolved lazily; see
     *                        {@see BaseRepository::db()}.
     */
    public function __construct(
        private readonly CompanyEntityMapper $mapper,
        ?wpdb $wpdb = null,
    ) {
        parent::__construct($wpdb);
    }

    /**
     * Reports a rejected unique index as the module's conflict.
     *
     * `companies.name` carries a unique index, so a second company under a name
     * already stored arrives here as an integrity violation rather than as a row.
     *
     * @param Throwable $exception
     *
     * @return Throwable
     */
    protected function duplicateException(Throwable $exception): Throwable
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
     * @throws CompanyDuplicateException When the unique name index rejects the row.
     * @throws RuntimeException When the insert fails.
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
     * @throws CompanyDuplicateException When the unique name index rejects the row.
     * @throws RuntimeException When the update fails.
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
     * `FOR UPDATE` needs a transaction to mean anything. Read outside one, MySQL
     * releases the lock as soon as the statement's implicit transaction ends, and
     * the call becomes an ordinary read that merely looks safe. The caller takes
     * this inside the transaction that is about to write.
     *
     * @param int $companyId
     *
     * @return CompanyEntity|null Null when no company carries the id.
     *
     * @throws RuntimeException When the read fails.
     */
    public function findByIdForUpdate(int $companyId): ?CompanyEntity
    {
        $db = $this->db();

        $sql = $db->prepare(
            sprintf(
                'SELECT * FROM `%s` WHERE `id` = %%d LIMIT 1 FOR UPDATE',
                $this->escapeIdentifier($this->tableName(self::TABLE_SUFFIX))
            ),
            $companyId
        );

        $row = $db->get_row($sql, self::OUTPUT_ASSOC);

        // `get_row()` returns null for "no such row" and for a failed statement
        // alike, so the failure is read from `last_error` rather than inferred
        // from the null — otherwise an unreachable database would be served to
        // the Service as "this company does not exist".
        if ($row === null && $db->last_error !== '') {
            throw new RuntimeException(sprintf(
                'Failed to lock company %d for update: %s',
                $companyId,
                $db->last_error
            ));
        }

        return is_array($row) ? $this->mapper->toEntity($row) : null;
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
     * @throws RuntimeException When the delete fails.
     */
    public function delete(int $companyId): void
    {
        $deleted = $this->executeQuery(
            sprintf('delete company %d', $companyId),
            function () use ($companyId): int {
                $db = $this->db();

                $affected = $db->query($db->prepare(
                    sprintf(
                        'DELETE FROM `%s` WHERE `id` = %%d',
                        $this->escapeIdentifier($this->tableName(self::TABLE_SUFFIX))
                    ),
                    $companyId
                ));

                // The message is the driver's alone: `executeQuery()` adds the
                // action that names the operation.
                if ($affected === false) {
                    throw new RuntimeException(
                        $db->last_error !== '' ? $db->last_error : 'the database reported no error message'
                    );
                }

                return (int) $affected;
            }
        );

        if ($deleted < 1) {
            throw new CompanyNotFoundException($companyId);
        }
    }

    /**
     * Reads back a row just written.
     *
     * Every write returns through here because the database owns the timestamps
     * and the id: an entity assembled from the caller's own values would be
     * missing both, and a Service returning it would report a timestamp that was
     * never stored.
     *
     * @param int $companyId
     *
     * @return CompanyEntity
     *
     * @throws RuntimeException When the row cannot be read back.
     */
    private function readBack(int $companyId): CompanyEntity
    {
        $db = $this->db();

        $row = $db->get_row($db->prepare(
            sprintf(
                'SELECT `%s` FROM `%s` WHERE `id` = %%d LIMIT 1',
                implode('`, `', self::COLUMNS),
                $this->escapeIdentifier($this->tableName(self::TABLE_SUFFIX))
            ),
            $companyId
        ), self::OUTPUT_ASSOC);

        if (! is_array($row)) {
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
     * The guard is what makes the assumption checkable: an entity with no id
     * would produce `WHERE id = 0` and update nothing, and the caller would be
     * told the write succeeded.
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
