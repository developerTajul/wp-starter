<?php

declare(strict_types=1);

namespace Core\Company\Application\Contracts;

use Core\Company\Application\DTOs\Requests\CompanyFilterDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * Handles company read use cases.
 */
interface CompanyQueryServiceContract
{
    /**
     * Returns the paginated company list matching the given criteria.
     *
     * @param CompanyFilterDTO $companyFilterDTO
     *
     * @return Result Contains the PaginatedResult of CompanyResponseDTO on success.
     */
    public function getPaginatedCompanies(CompanyFilterDTO $companyFilterDTO): Result;

    /**
     * Returns one company's stored values for an edit form to prefill.
     *
     * @param int $companyId
     *
     * @return Result Contains the CompanyEditResponseDTO on success.
     */
    public function getCompanyForEdit(int $companyId): Result;

    /**
     * Returns one company's 360-degree profile.
     *
     * @param int $companyId
     *
     * @return Result Contains the CompanyDetailResponseDTO on success.
     */
    public function getCompanyDetails(int $companyId): Result;
}
