<?php

declare(strict_types=1);

namespace Core\Company\Application\Mappers;

use Core\Company\Application\Contracts\CompanyMapperContract;
use Core\Company\Application\DTOs\Requests\CompanyFilterDTO;
use Core\Company\Application\DTOs\Requests\StoreCompanyDTO;
use Core\Company\Application\DTOs\Requests\UpdateCompanyDTO;
use Core\Company\Domain\CompanyRules;
use InvalidArgumentException;

/**
 * Maps validated request input to Company DTOs.
 *
 * @implements \Core\Company\Application\Contracts\CompanyMapperContract
 */
final class CompanyMapper implements CompanyMapperContract
{
    private const DEFAULT_PER_PAGE = 15;

    /**
     * Builds a StoreCompanyDTO from validated input. An absent nullable field
     * and an explicit null both land as null: the client is clearing a field
     * that has no value to begin with.
     *
     * @param array $validatedData
     *
     * @return StoreCompanyDTO
     */
    public function mapToStoreDTO(array $validatedData): StoreCompanyDTO
    {
        return new StoreCompanyDTO(
            name: (string) $validatedData['name'],
            website: $this->toNullableString($validatedData['website'] ?? null),
            industry: $this->toNullableString($validatedData['industry'] ?? null),
            phone: $this->toNullableString($validatedData['phone'] ?? null),
            address: $this->toNullableString($validatedData['address'] ?? null),
        );
    }

    /**
     * Builds an UpdateCompanyDTO from validated input.
     *
     * An absent key leaves the field unchanged. A supplied null or blank string
     * clears it, which is what a client gets by submitting an emptied form
     * field, and the clear flag is what carries that intent down to the entity.
     * Any other value replaces the field. The name has no clear flag: a company
     * cannot be left without one.
     *
     * @param int   $companyId     The company ID from the route.
     * @param array $validatedData The validated input data from the delivery layer.
     *
     * @return UpdateCompanyDTO
     */
    public function mapToUpdateDTO(int $companyId, array $validatedData): UpdateCompanyDTO
    {
        $clearsWebsite = $this->isCleared(validatedData: $validatedData, field: 'website');
        $clearsIndustry = $this->isCleared(validatedData: $validatedData, field: 'industry');
        $clearsPhone = $this->isCleared(validatedData: $validatedData, field: 'phone');
        $clearsAddress = $this->isCleared(validatedData: $validatedData, field: 'address');

        return new UpdateCompanyDTO(
            id: $companyId,
            name: $validatedData['name'] ?? null,
            website: $clearsWebsite ? null : $this->toNullableString($validatedData['website'] ?? null),
            industry: $clearsIndustry ? null : $this->toNullableString($validatedData['industry'] ?? null),
            phone: $clearsPhone ? null : $this->toNullableString($validatedData['phone'] ?? null),
            address: $clearsAddress ? null : $this->toNullableString($validatedData['address'] ?? null),
            clearWebsite: $clearsWebsite,
            clearIndustry: $clearsIndustry,
            clearPhone: $clearsPhone,
            clearAddress: $clearsAddress,
        );
    }

    /**
     * Whether a supplied value is an explicit request to clear the field. Only
     * a key the client actually sent counts: an omitted field stays unchanged.
     *
     * @param array  $validatedData
     * @param string $field
     *
     * @return bool
     */
    private function isCleared(array $validatedData, string $field): bool
    {
        if (! array_key_exists($field, $validatedData)) {
            return false;
        }

        $value = $validatedData[$field];

        return $value === null || $value === '';
    }

    /**
     * Builds a CompanyFilterDTO from query input.
     *
     * @param array $queryParams
     *
     * @return CompanyFilterDTO
     *
     * @throws InvalidArgumentException When a supplied value is not usable.
     */
    public function mapToFilterDTO(array $queryParams): CompanyFilterDTO
    {
        return new CompanyFilterDTO(
            perPage: $this->toPerPage($queryParams['per_page'] ?? self::DEFAULT_PER_PAGE),
            page: $this->toPage($queryParams['paged'] ?? 1),
        );
    }

    /**
     * Narrows a page number to a usable value.
     *
     * Page numbers are 1-based. Anything below 1 is someone editing the URL
     * rather than a fault, so it is clamped to the first page instead of
     * rejected - unlike {@see toPerPage()}, which fails hard so the service
     * can report bad input as a failure.
     *
     * @param mixed $page
     *
     * @return int
     */
    private function toPage(mixed $page): int
    {
        if (! is_numeric($page)) {
            return 1;
        }

        return max(1, (int) $page);
    }

    /**
     * Converts a page size, rejecting anything outside the bounds the domain
     * filter accepts. The bound is checked here so an out-of-range value fails
     * as bad input rather than as a domain exception inside the Service.
     *
     * @param mixed $perPage
     *
     * @return int
     *
     * @throws InvalidArgumentException When the value is not a usable page size.
     */
    private function toPerPage(mixed $perPage): int
    {
        if (! is_numeric($perPage)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported value [%s] for filter [per_page].',
                is_scalar($perPage) ? (string) $perPage : get_debug_type($perPage),
            ));
        }

        $pageSize = (int) $perPage;

        if ($pageSize < CompanyRules::MIN_PER_PAGE || $pageSize > CompanyRules::MAX_PER_PAGE) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported value [%s] for filter [per_page]. Page size must be between %d and %d.',
                (string) $pageSize,
                CompanyRules::MIN_PER_PAGE,
                CompanyRules::MAX_PER_PAGE,
            ));
        }

        return $pageSize;
    }

    /**
     * Narrows a supplied value to a string, reading an absent or empty one as
     * null so the DTO does not carry an empty string where it means "not set".
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private function toNullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
