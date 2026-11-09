<?php

declare(strict_types=1);

namespace Core\Contact\Application\Services;

use Core\Contact\Application\Contracts\ContactQueryContract;
use Core\Contact\Application\Contracts\ContactQueryServiceContract;
use Core\Contact\Application\DTOs\Requests\ContactFilterDTO;
use Core\Contact\Application\Mappers\ContactResponseMapper;
use Core\Contact\Domain\Exceptions\InvalidContactException;
use Core\Contact\Domain\ReadModels\ContactProjectionDTO;
use Core\Contact\Domain\ValueObjects\ContactFilter;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\TranslatorContract;
use Psr\Log\LoggerInterface;

/**
 * Handles contact read use cases.
 *
 * @implements ContactQueryServiceContract
 */
final class ContactQueryService implements ContactQueryServiceContract
{
    private const ERROR_INVALID = 'CONTACT_INVALID';

    private const ERROR_INDEX_FAILED = 'CONTACT_INDEX_FAILED';

    private const ERROR_NOT_FOUND = 'CONTACT_NOT_FOUND';

    private const ERROR_DETAIL_FAILED = 'CONTACT_DETAIL_FAILED';

    /**
     * The wording a missing contact is reported with, wherever it is noticed.
     */
    private const MESSAGE_CONTACT_NOT_FOUND = 'contacts.errors.contact_not_found';

    /**
     * @param ContactQueryContract  $contactQuery
     * @param ContactResponseMapper $responseMapper
     * @param TranslatorContract    $translator
     * @param LoggerInterface       $logger
     */
    public function __construct(
        private readonly ContactQueryContract $contactQuery,
        private readonly ContactResponseMapper $responseMapper,
        private readonly TranslatorContract $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Returns the paginated contact list matching the given criteria.
     *
     * @param ContactFilterDTO $contactFilterDTO
     *
     * @return Result Contains the PaginatedResult of ContactResponseDTO on success.
     */
    public function getPaginatedContacts(ContactFilterDTO $contactFilterDTO): Result
    {
        try {
            $contactFilter = new ContactFilter(
                search: $contactFilterDTO->search,
                perPage: $contactFilterDTO->perPage,
            );

            $paginatedContacts = $this->contactQuery->getFilteredContacts($contactFilter);

            // Projections become response DTOs here, so nothing downstream of
            // the Service ever sees a read model.
            $mappedPaginatedResult = $paginatedContacts->map(
                fn(ContactProjectionDTO $contact) => $this->responseMapper->toResponseDTOFromProjection($contact)
            );

            return Result::success(
                data: $mappedPaginatedResult,
                message: $this->translator->translate(key: 'contacts.retrieved'),
            );
        } catch (InvalidContactException $exception) {
            // A rejected filter is bad input, not a system failure.
            $this->logger->warning(
                message: 'Contact list rejected an invalid filter.',
                context: ['message' => $exception->getMessage()],
            );

            return Result::failure(
                message: $this->translate($exception),
                errorCode: self::ERROR_INVALID,
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                message: 'Failed to retrieve contacts.',
                context: ['message' => $exception->getMessage()],
            );

            return Result::failure(
                message: $this->translator->translate(key: 'contacts.errors.index_failed'),
                errorCode: self::ERROR_INDEX_FAILED,
            );
        }
    }

    /**
     * Returns one contact's full profile.
     *
     * @param string $id
     *
     * @return Result Contains the ContactDetailResponseDTO on success.
     */
    public function getContactDetails(string $id): Result
    {
        try {
            $contactDetail = $this->contactQuery->findDetailById((int) $id);

            // A missing contact is an ordinary outcome of a read, so it is
            // reported rather than thrown.
            if ($contactDetail === null) {
                return Result::failure(
                    message: $this->translator->translate(key: self::MESSAGE_CONTACT_NOT_FOUND),
                    errorCode: self::ERROR_NOT_FOUND,
                );
            }

            return Result::success(
                data: $this->responseMapper->toDetailResponseDTO($contactDetail),
                message: $this->translator->translate(key: 'contacts.retrieved_single'),
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                message: 'Failed to retrieve contact.',
                context: ['message' => $exception->getMessage()],
            );

            return Result::failure(
                message: $this->translator->translate(key: 'contacts.errors.detail_failed'),
                errorCode: self::ERROR_DETAIL_FAILED,
            );
        }
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
}
