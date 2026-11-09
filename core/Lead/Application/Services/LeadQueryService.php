<?php

declare(strict_types=1);

namespace Core\Lead\Application\Services;

use Core\Lead\Application\Contracts\LeadQueryContract;
use Core\Lead\Application\Contracts\LeadQueryServiceContract;
use Core\Lead\Application\DTOs\Requests\LeadFilterDTO;
use Core\Lead\Application\Mappers\LeadExceptionTranslator;
use Core\Lead\Application\Mappers\LeadResponseMapper;
use Core\Lead\Domain\Exceptions\InvalidLeadException;
use Core\Lead\Domain\ReadModels\LeadProjectionDTO;
use Core\Lead\Domain\ValueObjects\LeadFilter;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\TranslatorContract;

/**
 * Handles lead read use cases.
 *
 * @implements \Core\Lead\Application\Contracts\LeadQueryServiceContract
 */
final class LeadQueryService implements LeadQueryServiceContract
{
    private const ERROR_INVALID = 'LEAD_INVALID';

    private const ERROR_INDEX_FAILED = 'LEAD_INDEX_FAILED';

    private const ERROR_NOT_FOUND = 'LEAD_NOT_FOUND';

    private const ERROR_FETCH_FAILED = 'LEAD_FETCH_FAILED';

    public function __construct(
        private readonly LeadQueryContract $leadQuery,
        private readonly LeadResponseMapper $leadResponseMapper,
        private readonly LeadExceptionTranslator $exceptionTranslator,
        private readonly TranslatorContract $translator,
    ) {}

    /**
     * Returns the paginated lead list matching the given criteria.
     *
     * @param LeadFilterDTO $leadFilterDTO
     *
     * @return Result Contains the PaginatedResult of LeadResponseDTO on success.
     */
    public function getLeads(LeadFilterDTO $leadFilterDTO): Result
    {
        try {
            $leadFilter = new LeadFilter(
                status: $leadFilterDTO->status,
                source: $leadFilterDTO->source,
                search: $leadFilterDTO->search,
                perPage: $leadFilterDTO->perPage,
            );

            $paginatedLeads = $this->leadQuery->getFilteredLeads($leadFilter);

            // Entities become response DTOs here, so nothing downstream of the
            // Service ever sees a domain object.
            $mappedPaginatedResult = $paginatedLeads->map(
                fn(LeadProjectionDTO $projection) => $this->leadResponseMapper->toResponseDTO($projection)
            );

            return Result::success(
                data: $mappedPaginatedResult,
                message: $this->translator->translate('leads.retrieved')
            );
        } catch (InvalidLeadException $exception) {
            // A rejected filter is bad input, not a system failure.
            return Result::failure(
                $this->exceptionTranslator->translate($exception),
                self::ERROR_INVALID,
            );
        } catch (\Throwable) {
            return Result::failure(
                $this->translator->translate('leads.errors.index_failed'),
                self::ERROR_INDEX_FAILED,
            );
        }
    }

    /**
     * Returns a single lead by its ID.
     *
     * @param int $id
     *
     * @return Result Contains the LeadResponseDTO on success.
     */
    public function getLeadById(int $id): Result
    {
        try {
            $leadProjection = $this->leadQuery->findById($id);

            if (! $leadProjection) {
                return Result::failure(
                    $this->translator->translate('leads.errors.lead_not_found'),
                    self::ERROR_NOT_FOUND,
                );
            }

            return Result::success(
                data: $this->leadResponseMapper->toResponseDTO($leadProjection),
                message: $this->translator->translate('leads.retrieved_single')
            );
        } catch (\Throwable) {
            return Result::failure(
                $this->translator->translate('leads.errors.fetch_failed'),
                self::ERROR_FETCH_FAILED,
            );
        }
    }
}
