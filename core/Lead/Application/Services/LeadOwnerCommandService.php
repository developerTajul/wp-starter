<?php

declare(strict_types=1);

namespace Core\Lead\Application\Services;

use Core\Lead\Application\Contracts\LeadOwnerCommandServiceContract;
use Core\Lead\Application\DTOs\Requests\LeadOwnerAssignDTO;
use Core\Lead\Application\DTOs\Responses\LeadOwnerResponseDTO;
use Core\Lead\Application\Mappers\LeadExceptionTranslator;
use Core\Lead\Domain\Contracts\LeadRepositoryContract;
use Core\Lead\Domain\Entities\LeadEntity;
use Core\Lead\Domain\Exceptions\InvalidLeadException;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\TransactionManagerContract;
use Core\Shared\Domain\Contracts\TranslatorContract;
use Core\User\Application\Contracts\UserEntityQueryContract;
use Psr\Log\LoggerInterface;

/**
 * Handles handing a lead to a user, and releasing it again.
 *
 * @implements LeadOwnerCommandServiceContract
 */
final class LeadOwnerCommandService implements LeadOwnerCommandServiceContract
{
    private const ERROR_INVALID = 'LEAD_INVALID';

    private const ERROR_NOT_FOUND = 'LEAD_NOT_FOUND';

    private const ERROR_OWNER_NOT_FOUND = 'LEAD_OWNER_NOT_FOUND';

    private const ERROR_ASSIGN_FAILED = 'LEAD_ASSIGN_FAILED';

    /**
     * @param LeadRepositoryContract     $leadRepository
     * @param UserEntityQueryContract    $userQuery
     * @param LeadExceptionTranslator    $exceptionTranslator
     * @param TransactionManagerContract $transactionManager
     * @param TranslatorContract         $translator
     * @param LoggerInterface            $logger
     */
    public function __construct(
        private readonly LeadRepositoryContract $leadRepository,
        private readonly UserEntityQueryContract $userQuery,
        private readonly LeadExceptionTranslator $exceptionTranslator,
        private readonly TransactionManagerContract $transactionManager,
        private readonly TranslatorContract $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Hands the lead to a user, or releases it when the dto carries no user.
     *
     * The owner is resolved before the transaction opens: a missing user is a
     * rejected request, not a write that has to be undone, so it returns a
     * failure before any boundary is entered.
     *
     * The read-then-write sequence takes its row lock inside the transaction,
     * so a status change committed between the load and this save cannot be
     * overwritten by it.
     *
     * @param LeadOwnerAssignDTO $dto
     *
     * @return Result Contains the LeadOwnerResponseDTO on success.
     */
    public function assignOwner(LeadOwnerAssignDTO $dto): Result
    {
        // Resolved before the transaction opens: a missing user is a rejected
        // request, not a write that has to be undone.
        $ownerName = null;

        if ($dto->assignedTo !== null) {
            $ownerName = $this->userQuery->findEntityById($dto->assignedTo)?->name;

            if ($ownerName === null) {
                return Result::failure(
                    $this->translator->translate('leads.errors.owner_not_found'),
                    self::ERROR_OWNER_NOT_FOUND,
                );
            }
        }

        return $this->executeInTransaction(
            operation: function () use ($dto, $ownerName): Result {
                $lead = $this->leadRepository->findByIdForUpdate($dto->leadId);

                if ($lead === null) {
                    return Result::failure(
                        $this->translator->translate('leads.errors.lead_not_found'),
                        self::ERROR_NOT_FOUND,
                    );
                }

                $updatedLead = $dto->assignedTo === null
                    ? $lead->unassign()
                    : $lead->assignTo($dto->assignedTo);

                $savedLead = $this->leadRepository->updateOwner($updatedLead);

                return Result::success(
                    data: $this->toResponseDTO($savedLead, $ownerName),
                    message: $this->translator->translate(
                        $savedLead->isAssigned() ? 'leads.owner.assigned' : 'leads.owner.released'
                    ),
                );
            },
            logMessage: 'Lead owner assignment failed.',
            fallbackMessageKey: 'leads.owner.failed',
            fallbackErrorCode: self::ERROR_ASSIGN_FAILED,
            context: ['lead_id' => $dto->leadId],
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

    /**
     * Builds the response from the stored lead.
     *
     * @param LeadEntity  $lead
     * @param string|null $ownerName
     *
     * @return LeadOwnerResponseDTO
     */
    private function toResponseDTO(LeadEntity $lead, ?string $ownerName): LeadOwnerResponseDTO
    {
        return new LeadOwnerResponseDTO(
            id: (int) $lead->id,
            assigned_to: $lead->getAssignedTo(),
            assigned_to_name: $ownerName,
        );
    }
}
