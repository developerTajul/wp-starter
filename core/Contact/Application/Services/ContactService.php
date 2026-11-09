<?php

declare(strict_types=1);

namespace Core\Contact\Application\Services;

use Core\Contact\Application\Contracts\ContactCommandServiceContract;
use Core\Contact\Application\DTOs\Requests\StoreContactDTO;
use Core\Contact\Application\DTOs\Requests\UpdateContactDTO;
use Core\Contact\Application\Mappers\ContactResponseMapper;
use Core\Contact\Domain\Contracts\ContactRepositoryContract;
use Core\Contact\Domain\Entities\ContactEntity;
use Core\Contact\Domain\Exceptions\ContactDuplicateException;
use Core\Contact\Domain\Exceptions\InvalidContactException;
use Core\Contact\Domain\ValueObjects\CompanyLink;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\TransactionManagerContract;
use Core\Shared\Domain\Contracts\TranslatorContract;
use Psr\Log\LoggerInterface;

/**
 * Handles contact write use cases.
 *
 * @implements ContactCommandServiceContract
 */
final class ContactService implements ContactCommandServiceContract
{
    private const ERROR_INVALID = 'CONTACT_INVALID';

    private const ERROR_CREATE_FAILED = 'CONTACT_CREATE_FAILED';

    private const ERROR_NOT_FOUND = 'CONTACT_NOT_FOUND';

    private const ERROR_ALREADY_EXISTS = 'CONTACT_EMAIL_ALREADY_EXISTS';

    private const ERROR_UPDATE_FAILED = 'CONTACT_UPDATE_FAILED';

    private const ERROR_DELETE_FAILED = 'CONTACT_DELETE_FAILED';

    /**
     * The wording a missing contact is reported with, wherever it is noticed.
     * The key lives here so every not-found outcome carries the same message
     * and the delivery layer can map its error code in one place.
     */
    private const MESSAGE_CONTACT_NOT_FOUND = 'contacts.errors.contact_not_found';

    /**
     * @param ContactRepositoryContract  $contactRepository
     * @param ContactResponseMapper      $responseMapper
     * @param TransactionManagerContract $transactionManager
     * @param TranslatorContract         $translator
     * @param LoggerInterface            $logger
     */
    public function __construct(
        private readonly ContactRepositoryContract $contactRepository,
        private readonly ContactResponseMapper $responseMapper,
        private readonly TransactionManagerContract $transactionManager,
        private readonly TranslatorContract $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Creates a contact, attaching it to a company when the payload names one.
     *
     * The entity guards the contact's own invariants and the CompanyLink value
     * object guards the pivot values; the DTO only carries input.
     *
     * @param StoreContactDTO $storeContactDTO
     *
     * @return Result Contains the ContactResponseDTO on success.
     */
    public function createContact(StoreContactDTO $storeContactDTO): Result
    {
        return $this->executeInTransaction(
            operation: function () use ($storeContactDTO): Result {
                $contactEntity = ContactEntity::create(
                    name: $storeContactDTO->name,
                    email: $storeContactDTO->email,
                    phone: $storeContactDTO->phone,
                    companyLink: $this->toCompanyLink($storeContactDTO),
                );

                $savedContact = $this->contactRepository->create($contactEntity);

                return Result::success(
                    data: $this->responseMapper->toResponseDTO($savedContact),
                    message: $this->translator->translate(key: 'contacts.created'),
                );
            },
            logMessage: 'Contact creation failed.',
            fallbackMessageKey: 'contacts.errors.create_failed',
            fallbackErrorCode: self::ERROR_CREATE_FAILED,
        );
    }

    /**
     * Updates a contact inside a transaction, holding a row lock from the read
     * through to the save.
     *
     * @param UpdateContactDTO $updateContactDTO
     *
     * @return Result Contains the ContactResponseDTO on success.
     */
    public function updateContact(UpdateContactDTO $updateContactDTO): Result
    {
        return $this->executeInTransaction(
            operation: function () use ($updateContactDTO): Result {
                $contactEntity = $this->contactRepository->findByIdForUpdate($updateContactDTO->id);

                if ($contactEntity === null) {
                    return $this->contactNotFound();
                }

                // Tell the entity to update itself; it guards its own invariants
                // and hands back the updated instance. The company change runs
                // through the same instance, so the entity owns the union of its
                // own state and the links it carries.
                $updatedContact = $contactEntity
                    ->applyChanges(
                        name: $updateContactDTO->name,
                        email: $updateContactDTO->email,
                        phone: $updateContactDTO->phone,
                        clearPhone: $updateContactDTO->clearPhone,
                    )
                    ->applyCompanyChange(
                        companyLink: $updateContactDTO->companyLink,
                        clearCompany: $updateContactDTO->clearCompany,
                    );

                $savedContact = $this->contactRepository->update($updatedContact);

                return Result::success(
                    data: $this->responseMapper->toResponseDTO($savedContact),
                    message: $this->translator->translate(key: 'contacts.updated'),
                );
            },
            logMessage: 'Contact update failed.',
            fallbackMessageKey: 'contacts.errors.update_failed',
            fallbackErrorCode: self::ERROR_UPDATE_FAILED,
            context: ['contact_id' => $updateContactDTO->id],
        );
    }

    /**
     * Deletes a contact inside a transaction.
     *
     * @param int $contactId
     *
     * @return Result
     */
    public function deleteContact(int $contactId): Result
    {
        return $this->executeInTransaction(
            operation: function () use ($contactId): Result {
                // The delete takes the id directly: the use case has no need for
                // the row's state, so hydrating an entity to satisfy the port
                // would be work nobody reads.
                $this->contactRepository->delete($contactId);

                return Result::success(
                    data: null,
                    message: $this->translator->translate(key: 'contacts.deleted'),
                );
            },
            logMessage: 'Contact deletion failed.',
            fallbackMessageKey: 'contacts.errors.delete_failed',
            fallbackErrorCode: self::ERROR_DELETE_FAILED,
            context: ['contact_id' => $contactId],
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
        } catch (InvalidContactException $exception) {
            // A rejected contact rule is bad input, not a system failure.
            return Result::failure(
                message: $this->translate($exception),
                errorCode: self::ERROR_INVALID,
            );
        } catch (ContactDuplicateException $exception) {
            // run() already rolled back: the race lost to the unique index.
            return Result::failure(
                message: $this->translator->translate(key: $exception->translationKey),
                errorCode: self::ERROR_ALREADY_EXISTS,
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
     * Builds the failure a use case reports when the contact it was handed does
     * not exist. One place, so every such outcome carries the same wording and
     * error code and delivery maps them alike.
     *
     * @return Result
     */
    private function contactNotFound(): Result
    {
        return Result::failure(
            message: $this->translator->translate(key: self::MESSAGE_CONTACT_NOT_FOUND),
            errorCode: self::ERROR_NOT_FOUND,
        );
    }

    /**
     * Resolves a domain exception's wording. A replacement that is itself a
     * translation key is resolved too, so nested wording stays in the lang file.
     *
     * @param InvalidContactException $exception
     *
     * @return string
     */
    private function translate(InvalidContactException $exception): string
    {
        $replace = array_map(
            callback: fn(string $value) => str_contains($value, '.') ? $this->translator->translate(key: $value) : $value,
            array: $exception->replace,
        );

        return $this->translator->translate(key: $exception->translationKey, replace: $replace);
    }

    /**
     * Builds the company link the payload asks for, or null when it names no
     * company. The job title and the primary flag are meaningless without one,
     * so they are dropped here rather than carried as loose values.
     *
     * @param StoreContactDTO $storeContactDTO
     *
     * @return CompanyLink|null
     *
     * @throws InvalidContactException When the link values would break an invariant.
     */
    private function toCompanyLink(StoreContactDTO $storeContactDTO): ?CompanyLink
    {
        if ($storeContactDTO->companyId === null) {
            return null;
        }

        return CompanyLink::create(
            companyId: $storeContactDTO->companyId,
            jobTitle: $storeContactDTO->jobTitle,
            isPrimary: $storeContactDTO->isPrimary,
        );
    }
}
