<?php

declare(strict_types=1);

namespace Core\Company\Application\Contracts;

use Core\Company\Domain\DataObjects\CompanyDetailData;
use Core\Company\Domain\Entities\CompanyEntity;
use Core\Company\Domain\ValueObjects\CompanyFilter;
use Core\Shared\Domain\DataObjects\PaginatedResult;

/**
 * Read side of the company persistence port.
 */
interface CompanyQueryContract
{
    /**
     * Returns one page of companies in the list's own order.
     *
     * @param CompanyFilter $filter
     *
     * @return PaginatedResult Items are CompanyEntity instances.
     */
    public function getFilteredCompanies(CompanyFilter $filter): PaginatedResult;

    /**
     * Returns one company together with its contacts, deals, deal stages and
     * deal activities.
     *
     * @param int $companyId
     *
     * @return CompanyDetailData|null Null when no company carries the id.
     */
    public function findDetailById(int $companyId): ?CompanyDetailData;

    /**
     * Returns one company on its own, without the related records the detail
     * read drags in.
     *
     * @param int $companyId
     *
     * @return CompanyEntity|null Null when no company carries the id.
     */
    public function findById(int $companyId): ?CompanyEntity;
}
