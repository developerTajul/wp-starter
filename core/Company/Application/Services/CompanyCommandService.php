<?php

declare(strict_types=1);

namespace Core\Company\Application\Services;

use Core\Company\Application\Contracts\CompanyCommandServiceContract;
use Core\Company\Application\Contracts\CompanyResponseMapperContract;
use Core\Company\Application\DTOs\Requests\StoreCompanyDTO;
use Core\Company\Application\DTOs\Requests\UpdateCompanyDTO;
use Core\Company\Domain\Contracts\CompanyRepositoryContract;
use Core\Company\Domain\Entities\CompanyEntity;
use Core\Company\Domain\Exceptions\CompanyDuplicateException;
use Core\Company\Domain\Exceptions\CompanyNotFoundException;
use Core\Company\Domain\Exceptions\InvalidCompanyException;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\TransactionManagerContract;
use Core\Shared\Domain\Contracts\TranslatorContract;
use Psr\Log\LoggerInterface;

/**
 * Handles company write use cases.
 *
 * @implements CompanyCommandServiceContract
 */
final class CompanyCommandService implements CompanyCommandServiceContract
{
    private const ERROR_INVALID = 'COMPANY_INVALID';

    private const ERROR_CREATE_FAILED = 'COMPANY_CREATE_FAILED';

    private const ERROR_NOT_FOUND = 'COMPANY_NOT_FOUND';

    private const ERROR_ALREADY_EXISTS = 'COMPANY_ALREADY_EXISTS';

    private const ERROR_UPDATE_FAILED = 'COMPANY_UPDATE_FAILED';

    private const ERROR_DELETE_FAILED = 'COMPANY_DELETE_FAILED';

    /**
     * The wording a missing company is reported with, wherever it is noticed.
     * The key lives here so every not-found outcome carries the same message
     * and the delivery layer can map its error code in one place.
     */
    private const MESSAGE_COMPANY_NOT_FOUND = 'companies.errors.company_not_found';

    /**
     * @param CompanyRepositoryContract       $companyRepository
     * @param CompanyResponseMapperContract   $responseMapper
     * @param TransactionManagerContract      $transactionManager
     * @param TranslatorContract              $translator
     * @param LoggerInterface                 $logger
     */
    public function __construct(
        private readonly CompanyRepositoryContract $companyRepository,
        private readonly CompanyResponseMapperContract $responseMapper,
        private readonly TransactionManagerContract $transactionManager,
        private readonly TranslatorContract $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Creates a company inside a transaction.
     *
     * @param StoreCompanyDTO $storeCompanyDTO
     *
     * @return Result Contains the CompanyResponseDTO on success.
     */
    public function createCompany(StoreCompanyDTO $storeCompanyDTO): Result
    {
        return $this->executeInTransaction(
            operation: function () use ($storeCompanyDTO): Result {
                // The entity guards its own invariants; the DTO only carries input.
                $companyEntity = CompanyEntity::create(
                    name: $storeCompanyDTO->name,
                    website: $storeCompanyDTO->website,
                    industry: $storeCompanyDTO->industry,
                    phone: $storeCompanyDTO->phone,
                    address: $storeCompanyDTO->address,
                );

                $savedCompany = $this->companyRepository->create($companyEntity);

                return Result::success(
                    data: $this->responseMapper->toResponseDTO($savedCompany),
                    message: $this->translator->translate(key: 'companies.created'),
                );
            },
            logMessage: 'Company creation failed.',
            fallbackMessageKey: 'companies.errors.create_failed',
            fallbackErrorCode: self::ERROR_CREATE_FAILED,
        );
    }

    /**
     * Updates a company inside a transaction, holding a row lock from the read
     * through to the save.
     *
     * @param UpdateCompanyDTO $updateCompanyDTO
     *
     * @return Result Contains the CompanyResponseDTO on success.
     */
    public function updateCompany(UpdateCompanyDTO $updateCompanyDTO): Result
    {
        return $this->executeInTransaction(
            operation: function () use ($updateCompanyDTO): Result {
                $companyEntity = $this->companyRepository->findByIdForUpdate($updateCompanyDTO->id);

                if ($companyEntity === null) {
                    return $this->companyNotFound();
                }

                // Tell the entity to update itself; it guards its own invariants
                // and hands back the updated instance.
                $updatedCompany = $companyEntity->applyChanges(
                    name: $updateCompanyDTO->name,
                    website: $updateCompanyDTO->website,
                    industry: $updateCompanyDTO->industry,
                    phone: $updateCompanyDTO->phone,
                    address: $updateCompanyDTO->address,
                    clearWebsite: $updateCompanyDTO->clearWebsite,
                    clearIndustry: $updateCompanyDTO->clearIndustry,
                    clearPhone: $updateCompanyDTO->clearPhone,
                    clearAddress: $updateCompanyDTO->clearAddress,
                );

                $savedCompany = $this->companyRepository->update($updatedCompany);

                return Result::success(
                    data: $this->responseMapper->toResponseDTO($savedCompany),
                    message: $this->translator->translate(key: 'companies.updated'),
                );
            },
            logMessage: 'Company update failed.',
            fallbackMessageKey: 'companies.errors.update_failed',
            fallbackErrorCode: self::ERROR_UPDATE_FAILED,
            context: ['company_id' => $updateCompanyDTO->id],
        );
    }

    /**
     * Deletes a company inside a transaction.
     *
     * @param int $companyId
     *
     * @return Result
     */
    public function deleteCompany(int $companyId): Result
    {
        return $this->executeInTransaction(
            operation: function () use ($companyId): Result {
                // The delete takes the id directly: the use case has no need for
                // the row's state, so hydrating an entity to satisfy the port
                // would be work nobody reads.
                $this->companyRepository->delete($companyId);

                return Result::success(
                    data: null,
                    message: $this->translator->translate(key: 'companies.deleted'),
                );
            },
            logMessage: 'Company deletion failed.',
            fallbackMessageKey: 'companies.errors.delete_failed',
            fallbackErrorCode: self::ERROR_DELETE_FAILED,
            context: ['company_id' => $companyId],
        );
    }

    /**
     * Runs a write use case inside the transaction boundary and converts its
     * failures into a Result. The callback throws on a violation after a write,
     * so a partial write is rolled back rather than committed.
     *
     * @param callable              $operation
     * @param string                $logMessage
     * @param string                $fallbackMessageKey
     * @param string                $fallbackErrorCode
     * @param array<string, mixed>  $context
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
        } catch (InvalidCompanyException $exception) {
            // A rejected company rule is bad input, not a system failure.
            return Result::failure(
                message: $this->translate($exception),
                errorCode: self::ERROR_INVALID,
            );
        } catch (CompanyDuplicateException $exception) {
            // run() already rolled back: the race lost to the unique index.
            return Result::failure(
                message: $this->translator->translate(key: $exception->translationKey),
                errorCode: self::ERROR_ALREADY_EXISTS,
            );
        } catch (CompanyNotFoundException $exception) {
            // The row went between the lock and the write. Same outcome as a
            // guard that never found it, so it carries the same error code.
            return Result::failure(
                message: $this->translator->translate(key: $exception->translationKey),
                errorCode: self::ERROR_NOT_FOUND,
            );
        } catch (\Throwable $exception) {
            $this->logger->error(message: $logMessage, context: array_merge($context, [
                'message' => $exception->getMessage(),
            ]));

            return Result::failure(
                message: $this->translator->translate(key: $fallbackMessageKey),
                errorCode: $fallbackErrorCode,
            );
        }
    }

    /**
     * Builds the failure a use case reports when the company it was handed does
     * not exist. One place, so every such outcome carries the same wording and
     * error code and delivery maps them alike.
     *
     * @return Result
     */
    private function companyNotFound(): Result
    {
        return Result::failure(
            message: $this->translator->translate(key: self::MESSAGE_COMPANY_NOT_FOUND),
            errorCode: self::ERROR_NOT_FOUND,
        );
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
