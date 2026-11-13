<?php

declare(strict_types=1);

namespace Core\Company\Application\Services;

use Core\Company\Application\Contracts\CompanyQueryContract;
use Core\Company\Application\Contracts\CompanyQueryServiceContract;
use Core\Company\Application\Contracts\CompanyResponseMapperContract;
use Core\Company\Application\DTOs\Requests\CompanyFilterDTO;
use Core\Company\Domain\Exceptions\InvalidCompanyException;
use Core\Company\Domain\ReadModels\CompanyProjectionDTO;
use Core\Company\Domain\ValueObjects\CompanyFilter;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\TranslatorContract;

/**
 * Handles company read use cases.
 *
 * @implements CompanyQueryServiceContract
 */
final class CompanyQueryService implements CompanyQueryServiceContract
{
    private const ERROR_INVALID = 'COMPANY_INVALID';

    private const ERROR_INDEX_FAILED = 'COMPANY_INDEX_FAILED';

    private const ERROR_NOT_FOUND = 'COMPANY_NOT_FOUND';

    private const ERROR_DETAIL_FAILED = 'COMPANY_DETAIL_FAILED';

    private const ERROR_FETCH_FAILED = 'COMPANY_FETCH_FAILED';

    /**
     * @param CompanyQueryContract          $companyQuery
     * @param CompanyResponseMapperContract $responseMapper
     * @param TranslatorContract            $translator
     */
    public function __construct(
        private readonly CompanyQueryContract $companyQuery,
        private readonly CompanyResponseMapperContract $responseMapper,
        private readonly TranslatorContract $translator,
    ) {}

    /**
     * Returns the paginated company list matching the given criteria.
     *
     * @param CompanyFilterDTO $companyFilterDTO
     *
     * @return Result Contains the PaginatedResult of CompanyResponseDTO on success.
     */
    public function getPaginatedCompanies(CompanyFilterDTO $companyFilterDTO): Result
    {
        try {
            $companyFilter = new CompanyFilter(
                perPage: $companyFilterDTO->perPage,
            );

            $paginatedCompanies = $this->companyQuery->getFilteredCompanies($companyFilter);

            // The list read returns projections, so nothing downstream of the
            // Service ever sees a domain object.
            $mappedPaginatedResult = $paginatedCompanies->map(
                fn(CompanyProjectionDTO $company) => $this->responseMapper->toResponseDTOFromProjection($company)
            );

            return Result::success(
                data: $mappedPaginatedResult,
                message: $this->translator->translate(key: 'companies.retrieved'),
            );
        } catch (InvalidCompanyException $exception) {
            // A rejected filter is bad input, not a system failure.
            return Result::failure(
                message: $this->translate($exception),
                errorCode: self::ERROR_INVALID,
            );
        } catch (\Throwable) {
            return Result::failure(
                message: $this->translator->translate(key: 'companies.errors.index_failed'),
                errorCode: self::ERROR_INDEX_FAILED,
            );
        }
    }

    /**
     * Returns one company's stored values for an edit form to prefill.
     *
     * @param int $companyId
     *
     * @return Result Contains the CompanyEditResponseDTO on success.
     */
    public function getCompanyForEdit(int $companyId): Result
    {
        try {
            $companyEntity = $this->companyQuery->findById($companyId);

            // A missing company is an ordinary outcome of a read, so it is
            // reported rather than thrown.
            if ($companyEntity === null) {
                return Result::failure(
                    message: $this->translator->translate(key: 'companies.errors.company_not_found'),
                    errorCode: self::ERROR_NOT_FOUND,
                );
            }

            return Result::success(
                data: $this->responseMapper->toEditResponseDTO($companyEntity),
                message: $this->translator->translate(key: 'companies.retrieved_single'),
            );
        } catch (\Throwable) {
            return Result::failure(
                message: $this->translator->translate(key: 'companies.errors.fetch_failed'),
                errorCode: self::ERROR_FETCH_FAILED,
            );
        }
    }

    /**
     * Returns one company's 360-degree profile.
     *
     * @param int $companyId
     *
     * @return Result Contains the CompanyDetailResponseDTO on success.
     */
    public function getCompanyDetails(int $companyId): Result
    {
        try {
            $companyDetail = $this->companyQuery->findDetailById($companyId);

            // A missing company is an ordinary outcome of a read, so it is
            // reported rather than thrown.
            if ($companyDetail === null) {
                return Result::failure(
                    message: $this->translator->translate(key: 'companies.errors.company_not_found'),
                    errorCode: self::ERROR_NOT_FOUND,
                );
            }

            return Result::success(
                data: $this->responseMapper->toDetailResponseDTO($companyDetail),
                message: $this->translator->translate(key: 'companies.retrieved_single'),
            );
        } catch (\Throwable) {
            return Result::failure(
                message: $this->translator->translate(key: 'companies.errors.detail_failed'),
                errorCode: self::ERROR_DETAIL_FAILED,
            );
        }
    }

    /**
     * Resolves a domain exception's wording. A replacement that is itself a
     * translation key is resolved too, so nested wording stays in the lang file.
     *
     * @param InvalidCompanyException $exception
     *
     * @return string
     */
    private function translate(InvalidCompanyException $exception): string
    {
        $replace = array_map(
            callback: fn(string $value) => str_contains($value, '.') ? $this->translator->translate(key: $value) : $value,
            array: $exception->replace,
        );

        return $this->translator->translate(key: $exception->translationKey, replace: $replace);
    }
}
