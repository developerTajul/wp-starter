<?php

declare(strict_types=1);

namespace Core\Company\Application\Contracts;

use Core\Company\Application\DTOs\Requests\CompanyFilterDTO;
use Core\Company\Application\DTOs\Requests\StoreCompanyDTO;
use Core\Company\Application\DTOs\Requests\UpdateCompanyDTO;

/**
 * PORT: request-to-DTO mapping for companies.
 */
interface CompanyMapperContract
{
    /**
     * Builds a StoreCompanyDTO from validated input.
     *
     * @param array $validatedData
     *
     * @return StoreCompanyDTO
     */
    public function mapToStoreDTO(array $validatedData): StoreCompanyDTO;

    /**
     * Builds an UpdateCompanyDTO from validated input and the route-bound ID.
     *
     * @param int   $companyId     The company ID from the route.
     * @param array $validatedData The validated input data from the delivery layer.
     *
     * @return UpdateCompanyDTO
     */
    public function mapToUpdateDTO(int $companyId, array $validatedData): UpdateCompanyDTO;

    /**
     * Builds a CompanyFilterDTO from query input, rejecting unrecognised values.
     *
     * @param array $queryParams The raw query string values.
     *
     * @return CompanyFilterDTO
     *
     * @throws \InvalidArgumentException When a supplied value is not usable.
     */
    public function mapToFilterDTO(array $queryParams): CompanyFilterDTO;

}
