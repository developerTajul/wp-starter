<?php

declare(strict_types=1);

namespace Core\Deal\Application\Services;

use Core\Deal\Application\Contracts\DealCommandServiceContract;
use Core\Deal\Application\DTOs\Requests\StoreDealDTO;
use Core\Deal\Application\DTOs\Requests\UpdateDealDTO;
use Core\Deal\Application\Mappers\DealResponseMapper;
use Core\Deal\Domain\Contracts\DealRepositoryContract;
use Core\Deal\Domain\Entities\DealEntity;
use Core\Deal\Domain\Exceptions\DealDuplicateException;
use Core\Deal\Domain\Exceptions\InvalidDealException;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\TransactionManagerContract;
use Core\Shared\Domain\Contracts\TranslatorContract;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Handles deal write use cases. Business rules live in DealEntity.
 *
 * @implements \Core\Deal\Application\Contracts\DealCommandServiceContract
 */
final class DealCommandService implements DealCommandServiceContract
{
    private const ERROR_INVALID = 'DEAL_INVALID';

    private const ERROR_CREATE_FAILED = 'DEAL_CREATE_FAILED';

    private const ERROR_NOT_FOUND = 'DEAL_NOT_FOUND';

    private const ERROR_UPDATE_FAILED = 'DEAL_UPDATE_FAILED';

    private const ERROR_DELETE_FAILED = 'DEAL_DELETE_FAILED';

    private const ERROR_ALREADY_EXISTS = 'DEAL_ALREADY_EXISTS';

    private const MESSAGE_NOT_FOUND = 'deals.errors.not_found';

    /**
     * The form the close date column holds.
     */
    private const DATE_FORMAT = 'Y-m-d';

    /**
     * @param DealRepositoryContract     $dealRepository
     * @param DealResponseMapper         $responseMapper
     * @param TransactionManagerContract $transactionManager
     * @param TranslatorContract         $translator
     * @param LoggerInterface            $logger
     */
    public function __construct(
        private readonly DealRepositoryContract $dealRepository,
        private readonly DealResponseMapper $responseMapper,
        private readonly TransactionManagerContract $transactionManager,
        private readonly TranslatorContract $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Creates a deal inside a transaction.
     *
     * The entity guards its own invariants; the DTO only carries input.
     *
     * @param StoreDealDTO $storeDealDTO
     *
     * @return Result Contains the DealResponseDTO on success.
     */
    public function createDeal(StoreDealDTO $storeDealDTO): Result
    {
        return $this->executeInTransaction(
            operation: function () use ($storeDealDTO): Result {
                $dealEntity = DealEntity::create(
                    title: $storeDealDTO->title,
                    pipelineId: $storeDealDTO->pipelineId,
                    dealStageId: $storeDealDTO->dealStageId,
                    companyId: $storeDealDTO->companyId,
                    contactId: $storeDealDTO->contactId,
                    amount: $storeDealDTO->amount,
                    currency: $storeDealDTO->currency,
                    expectedCloseDate: $storeDealDTO->expectedCloseDate,
                    actualCloseDate: $storeDealDTO->actualCloseDate,
                );

                $savedDeal = $this->dealRepository->create($dealEntity);

                return Result::success(
                    data: $this->responseMapper->toResponseDTO($savedDeal),
                    message: $this->translator->translate(key: 'deals.created'),
                );
            },
            logMessage: 'Deal creation failed.',
            fallbackMessageKey: 'deals.errors.create_failed',
            fallbackErrorCode: self::ERROR_CREATE_FAILED,
        );
    }

    /**
     * Updates a deal inside a transaction, holding a row lock from the read
     * through to the save.
     *
     * @param UpdateDealDTO $updateDealDTO
     *
     * @return Result Contains the DealResponseDTO on success.
     */
    public function updateDeal(UpdateDealDTO $updateDealDTO): Result
    {
        return $this->executeInTransaction(
            operation: function () use ($updateDealDTO): Result {
                $dealEntity = $this->dealRepository->findByIdForUpdate($updateDealDTO->id);

                if ($dealEntity === null) {
                    return $this->dealNotFound();
                }

                // Tell the entity to update itself; it guards its own invariants
                // and hands back the updated instance. The close date follows the
                // stage the deal lands in, which is the entity's rule to apply.
                $updatedDeal = $dealEntity->applyChanges(
                    title: $updateDealDTO->title,
                    companyId: $updateDealDTO->companyId,
                    unlinkCompany: $updateDealDTO->unlinkCompany,
                    contactId: $updateDealDTO->contactId,
                    unlinkContact: $updateDealDTO->unlinkContact,
                    amount: $updateDealDTO->amount,
                    currency: $updateDealDTO->currency,
                    pipelineId: $updateDealDTO->pipelineId,
                    dealStageId: $updateDealDTO->dealStageId,
                    stageName: $updateDealDTO->stageName,
                    expectedCloseDate: $updateDealDTO->expectedCloseDate,
                    closeDate: $this->today(),
                );

                $savedDeal = $this->dealRepository->update($updatedDeal);

                return Result::success(
                    data: $this->responseMapper->toResponseDTO($savedDeal),
                    message: $this->translator->translate(key: 'deals.updated'),
                );
            },
            logMessage: 'Deal update failed.',
            fallbackMessageKey: 'deals.errors.update_failed',
            fallbackErrorCode: self::ERROR_UPDATE_FAILED,
            context: ['deal_id' => $updateDealDTO->id],
        );
    }

    /**
     * Deletes a deal inside a transaction, holding a row lock from the read
     * through to the delete.
     *
     * @param int $id
     *
     * @return Result
     */
    public function deleteDeal(int $id): Result
    {
        return $this->executeInTransaction(
            operation: function () use ($id): Result {
                // The delete takes the id directly: the use case has no need for
                // the row's state, so the lock read only proves it is there.
                $dealEntity = $this->dealRepository->findByIdForUpdate($id);

                if ($dealEntity === null) {
                    return $this->dealNotFound();
                }

                $this->dealRepository->delete($id);

                return Result::success(
                    data: null,
                    message: $this->translator->translate(key: 'deals.deleted'),
                );
            },
            logMessage: 'Deal deletion failed.',
            fallbackMessageKey: 'deals.errors.delete_failed',
            fallbackErrorCode: self::ERROR_DELETE_FAILED,
            context: ['deal_id' => $id],
        );
    }

    /**
     * Runs a write use case inside the transaction boundary and converts its
     * failures into a Result. The callback throws on a violation after a write,
     * so a partial write is rolled back rather than committed.
     *
     * @param callable             $operation
     * @param string               $logMessage
     * @param string               $fallbackMessageKey
     * @param string               $fallbackErrorCode
     * @param array<string, mixed> $context
     *
     * @return Result
     */
    private function executeInTransaction(
        callable $operation,
        string $logMessage,
        string $fallbackMessageKey,
        string $fallbackErrorCode,
        array $context = [],
    ): Result {
        try {
            /** @var Result $result */
            $result = $this->transactionManager->run($operation);

            return $result;
        } catch (InvalidDealException $exception) {
            // A rejected deal rule is bad input, not a system failure.
            return Result::failure(
                message: $this->translate($exception),
                errorCode: self::ERROR_INVALID,
            );
        } catch (DealDuplicateException $exception) {
            // run() already rolled back: the race lost to the unique index.
            return Result::failure(
                message: $this->translator->translate(key: $exception->translationKey),
                errorCode: self::ERROR_ALREADY_EXISTS,
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                message: $logMessage,
                context: array_merge($context, ['message' => $exception->getMessage()]),
            );

            return Result::failure(
                message: $this->translator->translate(key: $fallbackMessageKey),
                errorCode: $fallbackErrorCode,
            );
        }
    }

    /**
     * Builds the failure a use case reports when the deal it was handed does not
     * exist. One place, so every such outcome carries the same wording and error
     * code and delivery maps them alike.
     *
     * @return Result
     */
    private function dealNotFound(): Result
    {
        return Result::failure(
            message: $this->translator->translate(key: self::MESSAGE_NOT_FOUND),
            errorCode: self::ERROR_NOT_FOUND,
        );
    }

    /**
     * Resolves a domain exception's wording. A replacement that is itself a
     * translation key is resolved too, so nested wording stays in the lang file.
     *
     * @param InvalidDealException $exception
     *
     * @return string
     */
    private function translate(InvalidDealException $exception): string
    {
        $replace = array_map(
            callback: fn(string $value) => str_contains($value, '.') ? $this->translator->translate(key: $value) : $value,
            array: $exception->replace,
        );

        return $this->translator->translate(key: $exception->translationKey, replace: $replace);
    }

    /**
     * Today's date, as the wall-clock day the close date column holds. The clock
     * is read here rather than inside the entity, so the rule itself stays a
     * pure function of the values it is handed.
     *
     * @return string
     */
    private function today(): string
    {
        return (new DateTimeImmutable())->format(self::DATE_FORMAT);
    }
}
