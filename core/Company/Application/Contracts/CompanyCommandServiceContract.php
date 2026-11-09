<?php

declare(strict_types=1);

namespace Core\Company\Application\Contracts;

use Core\Company\Application\DTOs\Requests\StoreCompanyDTO;
use Core\Company\Application\DTOs\Requests\UpdateCompanyDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * Handles company write use cases.
 */
interface CompanyCommandServiceContract
{
    /**
     * Creates a company.
     *
     * @param StoreCompanyDTO $storeCompanyDTO
     *
     * @return Result Contains the CompanyResponseDTO on success.
     */
    public function createCompany(StoreCompanyDTO $storeCompanyDTO): Result;

    /**
     * Updates a company.
     *
     * @param UpdateCompanyDTO $updateCompanyDTO
     *
     * @return Result Contains the CompanyResponseDTO on success.
     */
    public function updateCompany(UpdateCompanyDTO $updateCompanyDTO): Result;

    /**
     * Deletes a company.
     *
     * @param int $companyId
     *
     * @return Result
     */
    public function deleteCompany(int $companyId): Result;
}
