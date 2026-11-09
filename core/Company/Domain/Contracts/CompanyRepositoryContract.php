<?php

declare(strict_types=1);

namespace Core\Company\Domain\Contracts;

use Core\Company\Domain\Entities\CompanyEntity;

/**
 * PORT: persistence for companies.
 */
interface CompanyRepositoryContract
{
    /**
     * Inserts a company that has no ID yet.
     *
     * @param CompanyEntity $company
     *
     * @return CompanyEntity
     */
    public function create(CompanyEntity $company): CompanyEntity;

    /**
     * Writes a stored company's current state back to its row.
     *
     * @param CompanyEntity $company
     *
     * @return CompanyEntity
     */
    public function update(CompanyEntity $company): CompanyEntity;

    /**
     * Reads a company while holding a row lock, so a concurrent write cannot
     * slip between the read and the save.
     *
     * @param int $companyId
     *
     * @return CompanyEntity|null Null when no company carries the id.
     */
    public function findByIdForUpdate(int $companyId): ?CompanyEntity;

    /**
     * Deletes a company.
     *
     * @param int $companyId
     *
     * @return void
     */
    public function delete(int $companyId): void;
}
