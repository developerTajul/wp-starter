<?php

declare(strict_types=1);

namespace Core\Lead\Application\Services;

use Core\Lead\Application\Contracts\LeadNoteCommandServiceContract;
use Core\Lead\Application\DTOs\Requests\LeadNoteCreateDTO;
use Core\Lead\Application\Mappers\LeadExceptionTranslator;
use Core\Lead\Application\Mappers\LeadNoteResponseMapper;
use Core\Lead\Domain\Contracts\LeadNoteRepositoryContract;
use Core\Lead\Domain\Contracts\LeadRepositoryContract;
use Core\Lead\Domain\Entities\LeadNoteEntity;
use Core\Lead\Domain\Exceptions\InvalidLeadException;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\TransactionManagerContract;
use Core\Shared\Domain\Contracts\TranslatorContract;
use Psr\Log\LoggerInterface;

/**
 * Handles lead note write use cases.
 *
 * @implements LeadNoteCommandServiceContract
 */
final class LeadNoteCommandService implements LeadNoteCommandServiceContract
{
    private const ERROR_NOT_FOUND = 'LEAD_NOTE_NOT_FOUND';

    private const ERROR_LEAD_NOT_FOUND = 'LEAD_NOT_FOUND';

    private const ERROR_INVALID = 'LEAD_NOTE_INVALID';

    private const ERROR_CREATE_FAILED = 'LEAD_NOTE_CREATE_FAILED';

    private const ERROR_UPDATE_FAILED = 'LEAD_NOTE_UPDATE_FAILED';

    public function __construct(
        private readonly LeadNoteRepositoryContract $noteRepository,
        private readonly LeadRepositoryContract $leadRepository,
        private readonly LeadNoteResponseMapper $noteMapper,
        private readonly LeadExceptionTranslator $exceptionTranslator,
        private readonly TransactionManagerContract $transactionManager,
        private readonly TranslatorContract $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Writes a quick note against a lead.
     *
     * The lead is read through the write port's row lock, taken inside the
     * transaction before any write, so a note cannot be attached to a lead that
     * does not exist and a lead removed concurrently cannot slip one past the
     * check. The entity then enforces the note's own rules, and a rejection
     * throws so run() rolls back rather than committing a partial write.
     *
     * @param LeadNoteCreateDTO $dto
     *
     * @return Result Contains the LeadNoteResponseDTO on success.
     */
    public function addNote(LeadNoteCreateDTO $dto): Result
    {
        return $this->executeInTransaction(
            operation: function () use ($dto): Result {
                // Taken for its lock as much as its answer: the row is held for
                // the rest of the transaction so the lead cannot disappear
                // between this check and the insert.
                $lead = $this->leadRepository->findByIdForUpdate($dto->leadId);

                if ($lead === null) {
                    // A guard, not a failed write: nothing has been persisted, so
                    // returning a failure here is safe - run() commits an empty transaction.
                    return Result::failure(
                        message: $this->translator->translate('leads.errors.lead_not_found'),
                        errorCode: self::ERROR_LEAD_NOT_FOUND,
                    );
                }

                $note = LeadNoteEntity::create(
                    leadId: $dto->leadId,
                    body: $dto->body,
                );

                $storedNote = $this->noteRepository->create($note);

                // The repository hands back the stored row, so this response
                // carries the timestamps the write produced.
                return Result::success(
                    data: $this->noteMapper->toResponseDTOFromEntity($storedNote),
                    message: $this->translator->translate('leads.notes.created'),
                );
            },
            logMessage: 'Lead note creation failed.',
            fallbackMessageKey: 'leads.errors.note_create_failed',
            fallbackErrorCode: self::ERROR_CREATE_FAILED,
            context: ['lead_id' => $dto->leadId],
        );
    }

    /**
     * Pins or unpins a note.
     *
     * Read-then-write, so the note is read under a row lock inside the
     * transaction. The not-found guard runs before any write, so returning a
     * failure from inside the closure commits nothing.
     *
     * @param int  $noteId
     * @param bool $isPinned
     *
     * @return Result Contains the LeadNoteResponseDTO on success.
     */
    public function setPinned(int $noteId, bool $isPinned): Result
    {
        return $this->executeInTransaction(
            operation: function () use ($noteId, $isPinned): Result {
                $note = $this->noteRepository->findByIdForUpdate($noteId);

                if ($note === null) {
                    // A guard, not a failed write: nothing has been persisted, so
                    // returning a failure here is safe - run() commits an empty transaction.
                    return Result::failure(
                        message: $this->translator->translate('leads.errors.note_not_found'),
                        errorCode: self::ERROR_NOT_FOUND,
                    );
                }

                $updated = $isPinned ? $note->pinned() : $note->unpinned();

                $savedNote = $this->noteRepository->savePinState($updated);

                return Result::success(
                    data: $this->noteMapper->toResponseDTOFromEntity($savedNote),
                    message: $this->translator->translate(
                        $isPinned ? 'leads.notes.pinned' : 'leads.notes.unpinned'
                    ),
                );
            },
            logMessage: 'Lead note pin change failed.',
            fallbackMessageKey: 'leads.errors.note_update_failed',
            fallbackErrorCode: self::ERROR_UPDATE_FAILED,
            context: ['note_id' => $noteId],
        );
    }

    /**
     * Runs a use case in a transaction and turns its failures into Results.
     *
     * The one place this service opens and closes a transaction. A throwable
     * escaping the operation has already been rolled back by the port, so the
     * catch blocks only translate.
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
}
