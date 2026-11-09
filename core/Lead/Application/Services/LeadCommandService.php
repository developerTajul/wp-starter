<?php

declare(strict_types=1);

namespace Core\Lead\Application\Services;

use Core\Lead\Application\Contracts\LeadCommandServiceContract;
use Core\Lead\Application\Contracts\LeadQueryContract;
use Core\Lead\Application\DTOs\Requests\LeadCreateDTO;
use Core\Lead\Application\DTOs\Requests\LeadUpdateDTO;
use Core\Lead\Application\Mappers\LeadExceptionTranslator;
use Core\Lead\Application\Mappers\LeadResponseMapper;
use Core\Lead\Domain\Contracts\LeadRepositoryContract;
use Core\Lead\Domain\Entities\LeadEntity;
use Core\Lead\Domain\Enums\LeadStatus;
use Core\Lead\Domain\Exceptions\InvalidLeadException;
use Core\Lead\Domain\Exceptions\LeadDuplicateException;
use Core\Shared\Application\Contracts\QueueManagerContract;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\TransactionManagerContract;
use Core\Shared\Domain\Contracts\TranslatorContract;
use Psr\Log\LoggerInterface;

/**
 * Handles lead write use cases.
 *
 * @implements \Core\Lead\Application\Contracts\LeadCommandServiceContract
 */
final class LeadCommandService implements LeadCommandServiceContract
{
    private const ERROR_INVALID = 'LEAD_INVALID';

    private const ERROR_NOT_FOUND = 'LEAD_NOT_FOUND';

    private const ERROR_ALREADY_EXISTS = 'LEAD_ALREADY_EXISTS';

    private const ERROR_CREATE_FAILED = 'LEAD_CREATE_FAILED';

    private const ERROR_UPDATE_FAILED = 'LEAD_UPDATE_FAILED';

    private const ERROR_DELETE_FAILED = 'LEAD_DELETE_FAILED';

    public function __construct(
        private readonly LeadRepositoryContract $leadRepository,
        private readonly LeadQueryContract $leadQuery,
        private readonly LeadResponseMapper $leadResponseMapper,
        private readonly LeadExceptionTranslator $exceptionTranslator,
        private readonly QueueManagerContract $queueManager,
        private readonly TransactionManagerContract $transactionManager,
        private readonly TranslatorContract $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Captures an inbound lead by dispatching it to the background queue.
     *
     * @param LeadCreateDTO $dto
     */
    public function captureLead(LeadCreateDTO $dto): void
    {
        $this->queueManager->push('lead.inbound.process', $dto);
    }

    /**
     * Creates a lead inside a transaction.
     *
     * The lookup below rejects the common duplicate, but two writers can both
     * pass it before either inserts. The unique email/phone index is what
     * actually decides; the write adapter turns that rejection into
     * LeadDuplicateException, so both paths report the same conflict.
     *
     * A failure after the insert throws, so run() rolls back rather than
     * committing partial work. Queued inbound leads rely on the conflict
     * arriving as a plain failure Result: the job only needs to know the lead
     * was not stored.
     *
     * @param LeadCreateDTO $leadCreateDTO
     *
     * @return Result Contains the LeadResponseDTO on success.
     */
    public function createLead(LeadCreateDTO $leadCreateDTO): Result
    {
        return $this->executeInTransaction(
            operation: function () use ($leadCreateDTO): Result {
                $existingLead = $this->leadQuery->findEntityByEmail($leadCreateDTO->email)
                            ?? $this->leadQuery->findEntityByPhone($leadCreateDTO->phone);

                if ($existingLead) {
                    return $this->alreadyExists();
                }

                $newLead = LeadEntity::create(
                    name: $leadCreateDTO->name,
                    email: $leadCreateDTO->email,
                    phone: $leadCreateDTO->phone,
                    companyName: $leadCreateDTO->companyName,
                    status: $leadCreateDTO->status,
                    source: $leadCreateDTO->source,
                );

                $storedLead = $this->leadRepository->create($newLead);

                // The repository hands back the persisted row, so this response
                // carries stored timestamps rather than the ones the input implied.
                return Result::success(
                    data: $this->leadResponseMapper->toResponseDTOFromEntity($storedLead),
                    message: $this->translator->translate('leads.created')
                );
            },
            logMessage: 'Lead creation failed.',
            fallbackMessageKey: 'leads.errors.create_failed',
            fallbackErrorCode: self::ERROR_CREATE_FAILED,
        );
    }

    /**
     * Moves a lead to a new status, letting the domain entity decide whether
     * the change is allowed.
     *
     * The lead is read through the write port's row lock, taken inside the
     * transaction, rather than through the read port's unlocked lookup: two
     * changes racing for the same lead are serialised, so the second one
     * decides from the status the first one committed instead of from the
     * state it read before. An unlocked read let a stale move overwrite a
     * newer one, resurrecting a lead another request had already lost.
     *
     * @param int        $leadId
     * @param LeadStatus $status
     *
     * @return Result Contains the LeadResponseDTO on success.
     */
    public function changeStatus(int $leadId, LeadStatus $status): Result
    {
        return $this->executeInTransaction(
            operation: function () use ($leadId, $status): Result {
                $lead = $this->leadRepository->findByIdForUpdate($leadId);

                if ($lead === null) {
                    return Result::failure(
                        $this->translator->translate('leads.errors.lead_not_found'),
                        self::ERROR_NOT_FOUND,
                    );
                }

                $updatedLead = $lead->transitionTo($status);

                // The repository hands back the stored row, so the response
                // carries the timestamp this write produced rather than the one
                // we loaded.
                $savedLead = $this->leadRepository->updateStatus($updatedLead);

                return Result::success(
                    data: $this->leadResponseMapper->toResponseDTOFromEntity($savedLead),
                    message: $this->translator->translate('leads.status_changed')
                );
            },
            logMessage: 'Lead status change failed.',
            fallbackMessageKey: 'leads.errors.update_failed',
            fallbackErrorCode: self::ERROR_UPDATE_FAILED,
            context: ['lead_id' => $leadId],
        );
    }

    /**
     * Saves an edit to a lead's own fields.
     *
     * The read-then-write sequence takes its row lock inside the transaction,
     * so a status or owner change committed between the load and this save
     * cannot be overwritten by it - the entity handed back carries the stored
     * status and owner forward untouched.
     *
     * @param LeadUpdateDTO $leadUpdateDTO
     *
     * @return Result Contains the LeadResponseDTO on success.
     */
    public function updateLead(LeadUpdateDTO $leadUpdateDTO): Result
    {
        return $this->executeInTransaction(
            operation: function () use ($leadUpdateDTO): Result {
                $lead = $this->leadRepository->findByIdForUpdate($leadUpdateDTO->id);

                if ($lead === null) {
                    return Result::failure(
                        $this->translator->translate('leads.errors.lead_not_found'),
                        self::ERROR_NOT_FOUND,
                    );
                }

                // Tell the entity to edit itself; it guards its own invariants and
                // hands back a new instance. The stored status and owner travel
                // with it, so this write cannot disturb either.
                $updatedLead = $lead->applyChanges(
                    name: $leadUpdateDTO->name,
                    email: $leadUpdateDTO->email,
                    clearEmail: $leadUpdateDTO->clearEmail,
                    phone: $leadUpdateDTO->phone,
                    clearPhone: $leadUpdateDTO->clearPhone,
                    companyName: $leadUpdateDTO->companyName,
                    clearCompanyName: $leadUpdateDTO->clearCompanyName,
                    source: $leadUpdateDTO->source,
                );

                $savedLead = $this->leadRepository->update($updatedLead);

                return Result::success(
                    data: $this->leadResponseMapper->toResponseDTOFromEntity($savedLead),
                    message: $this->translator->translate('leads.updated')
                );
            },
            logMessage: 'Lead update failed.',
            fallbackMessageKey: 'leads.errors.update_failed',
            fallbackErrorCode: self::ERROR_UPDATE_FAILED,
            context: ['lead_id' => $leadUpdateDTO->id],
        );
    }

    /**
     * Removes a lead.
     *
     * The row lock is taken before the delete, so a concurrent status or owner
     * change cannot land between the read and the removal. Rows bound to the
     * lead by a cascade foreign key go with it.
     *
     * @param int $leadId
     *
     * @return Result Success carries no data.
     */
    public function deleteLead(int $leadId): Result
    {
        return $this->executeInTransaction(
            operation: function () use ($leadId): Result {
                $lead = $this->leadRepository->findByIdForUpdate($leadId);

                if ($lead === null) {
                    return Result::failure(
                        $this->translator->translate('leads.errors.lead_not_found'),
                        self::ERROR_NOT_FOUND,
                    );
                }

                $this->leadRepository->delete($leadId);

                return Result::success(
                    data: null,
                    message: $this->translator->translate('leads.deleted'),
                );
            },
            logMessage: 'Lead deletion failed.',
            fallbackMessageKey: 'leads.errors.delete_failed',
            fallbackErrorCode: self::ERROR_DELETE_FAILED,
            context: ['lead_id' => $leadId],
        );
    }

    /**
     * Runs a use case in a transaction and turns its failures into Results.
     *
     * The one place this service opens and closes a transaction. A throwable
     * escaping the operation has already been rolled back by the port, so the
     * catch blocks only translate; the failure Result is built outside the
     * boundary, never returned from a half-written closure.
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
        } catch (InvalidLeadException $exception) {
            return Result::failure(
                message: $this->exceptionTranslator->translate($exception),
                errorCode: self::ERROR_INVALID,
            );
        } catch (LeadDuplicateException $exception) {
            // A race the lookup could not see lost to the unique index.
            return $this->duplicateFailure($exception);
        } catch (\Throwable $exception) {
            $this->logger->error(
                message: $logMessage,
                context: array_merge($context, ['message' => $exception->getMessage()]),
            );

            return Result::failure(
                message: $this->translator->translate($fallbackMessageKey),
                errorCode: $fallbackErrorCode,
            );
        }
    }

    /**
     * Reports a lead that the system already holds. The wording is the
     * duplicate exception's own key, so both duplicate paths read the same.
     *
     * @return Result
     */
    private function alreadyExists(): Result
    {
        return Result::failure(
            message: $this->translator->translate(LeadDuplicateException::TRANSLATION_PREFIX . 'duplicate'),
            errorCode: self::ERROR_ALREADY_EXISTS,
        );
    }

    /**
     * Reports a duplicate the unique index rejected after the lookup passed.
     *
     * Runs in the catch, so run() has already rolled back and no partial write
     * survives. To the caller it is the same conflict the lookup reports.
     *
     * @param LeadDuplicateException $exception
     *
     * @return Result
     */
    private function duplicateFailure(LeadDuplicateException $exception): Result
    {
        return Result::failure(
            message: $this->translator->translate($exception->translationKey),
            errorCode: self::ERROR_ALREADY_EXISTS,
        );
    }
}
