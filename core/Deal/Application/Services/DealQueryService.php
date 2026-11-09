<?php

declare(strict_types=1);

namespace Core\Deal\Application\Services;

use Core\Deal\Application\Contracts\DealQueryContract;
use Core\Deal\Application\Contracts\DealQueryServiceContract;
use Core\Deal\Application\DTOs\Requests\DealFilterDTO;
use Core\Deal\Application\Mappers\DealResponseMapper;
use Core\Deal\Domain\Exceptions\InvalidDealException;
use Core\Deal\Domain\ReadModels\DealProjectionDTO;
use Core\Deal\Domain\ValueObjects\DealFilter;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\TranslatorContract;
use Psr\Log\LoggerInterface;

/**
 * Handles deal read use cases.
 *
 * @implements \Core\Deal\Application\Contracts\DealQueryServiceContract
 */
final class DealQueryService implements DealQueryServiceContract
{
    private const ERROR_INVALID = 'DEAL_INVALID';

    private const ERROR_INDEX_FAILED = 'DEAL_INDEX_FAILED';

    private const ERROR_NOT_FOUND = 'DEAL_NOT_FOUND';

    private const ERROR_DETAIL_FAILED = 'DEAL_DETAIL_FAILED';

    /**
     * @param DealQueryContract  $dealQuery
     * @param DealResponseMapper $responseMapper
     * @param TranslatorContract $translator
     * @param LoggerInterface    $logger
     */
    public function __construct(
        private readonly DealQueryContract $dealQuery,
        private readonly DealResponseMapper $responseMapper,
        private readonly TranslatorContract $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Returns the paginated deal list matching the given criteria.
     *
     * @param DealFilterDTO $dealFilterDTO
     *
     * @return Result Contains the PaginatedResult of DealResponseDTO on success.
     */
    public function getPaginatedDeals(DealFilterDTO $dealFilterDTO): Result
    {
        try {
            $dealFilter = new DealFilter(
                pipelineId: $dealFilterDTO->pipelineId,
                dealStageId: $dealFilterDTO->dealStageId,
                perPage: $dealFilterDTO->perPage,
            );

            $paginatedDeals = $this->dealQuery->getFilteredDeals($dealFilter);

            // Rows arrive as read models, so nothing downstream of the Service
            // ever sees a domain object.
            $mappedPaginatedResult = $paginatedDeals->map(
                fn(DealProjectionDTO $projection) => $this->responseMapper->toResponseDTOFromProjection($projection)
            );

            return Result::success(
                data: $mappedPaginatedResult,
                message: $this->translator->translate(key: 'deals.retrieved'),
            );
        } catch (InvalidDealException $exception) {
            // A rejected filter is bad input, not a system failure.
            $this->logger->warning(
                message: 'Deal list rejected an invalid filter.',
                context: ['message' => $exception->getMessage()],
            );

            return Result::failure(
                message: $this->translator->translate(key: $exception->translationKey),
                errorCode: self::ERROR_INVALID,
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                message: 'Failed to retrieve deals.',
                context: ['exception' => $exception],
            );

            return Result::failure(
                message: $this->translator->translate(key: 'deals.errors.index_failed'),
                errorCode: self::ERROR_INDEX_FAILED,
            );
        }
    }

    /**
     * Returns one deal with everything attached to it.
     *
     * @param int $id
     *
     * @return Result Contains the DealDetailResponseDTO on success.
     */
    public function getDealDetails(int $id): Result
    {
        try {
            $storedDeal = $this->dealQuery->findDetailById($id);

            // A missing deal is an ordinary outcome of a read, so it is
            // reported rather than thrown.
            if ($storedDeal === null) {
                return Result::failure(
                    message: $this->translator->translate(key: 'deals.errors.not_found'),
                    errorCode: self::ERROR_NOT_FOUND,
                );
            }

            return Result::success(
                data: $this->responseMapper->toDetailResponseDTO($storedDeal),
                message: $this->translator->translate(key: 'deals.retrieved_single'),
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                message: 'Failed to retrieve deal.',
                context: ['exception' => $exception],
            );

            return Result::failure(
                message: $this->translator->translate(key: 'deals.errors.detail_failed'),
                errorCode: self::ERROR_DETAIL_FAILED,
            );
        }
    }
}
