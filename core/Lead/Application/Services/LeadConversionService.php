<?php

declare(strict_types=1);

namespace Core\Lead\Application\Services;

use Core\Lead\Application\Contracts\LeadConversionServiceContract;
use Core\Lead\Application\Mappers\LeadConversionResponseMapper;
use Core\Lead\Application\Mappers\LeadExceptionTranslator;
use Core\Lead\Domain\Contracts\LeadRepositoryContract;
use Core\Lead\Domain\Exceptions\InvalidLeadException;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\EventDispatcherContract;
use Core\Shared\Domain\Contracts\TransactionManagerContract;
use Core\Shared\Domain\Contracts\TranslatorContract;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Handles converting a qualified lead.
 *
 * @implements LeadConversionServiceContract
 */
final class LeadConversionService implements LeadConversionServiceContract
{
    private const ERROR_INVALID = 'LEAD_INVALID';

    private const ERROR_NOT_FOUND = 'LEAD_NOT_FOUND';

    private const ERROR_CONVERT_FAILED = 'LEAD_CONVERT_FAILED';

    public function __construct(
        private readonly LeadRepositoryContract $leadRepository,
        private readonly LeadConversionResponseMapper $responseMapper,
        private readonly LeadExceptionTranslator $exceptionTranslator,
        private readonly TransactionManagerContract $transactionManager,
        private readonly EventDispatcherContract $eventDispatcher,
        private readonly LoggerInterface $logger,
        private readonly TranslatorContract $translator,
    ) {}

    /**
     * Moves the lead to converted and announces it to the rest of the system.
     *
     * The lead's own rules decide whether it may convert, so a lead that is not
     * qualified is rejected before anything is written. The lead is read
     * through the write port's row lock, taken inside the transaction, so two
     * conversions racing for the same lead are serialised: the second one
     * decides from the status the first one committed instead of from the state
     * it read before. An unlocked read let both pass the qualified check and
     * convert the same lead twice.
     *
     * Events are released before the write and dispatched after the commit, so
     * a listener never sees a conversion that was later rolled back.
     *
     * @param int $leadId
     *
     * @return Result Contains the LeadConversionResponseDTO on success.
     */
    public function convert(int $leadId): Result
    {
        // The conversion is stamped when it happens rather than from the row.
        // The stored updated_at moves with the write, but converted_at records
        // the moment the lead was qualified into a deal.
        $convertedAt = new DateTimeImmutable();

        $events = [];

        $result = $this->executeInTransaction(
            operation: function () use ($leadId, $convertedAt, &$events): Result {
                $lead = $this->leadRepository->findByIdForUpdate($leadId);

                if ($lead === null) {
                    return Result::failure(
                        $this->translator->translate('leads.errors.lead_not_found'),
                        self::ERROR_NOT_FOUND,
                    );
                }

                // The entity returns a converted copy carrying the event; the
                // original is kept so a failed write leaves nothing half-changed.
                $convertedLead = $lead->converted($convertedAt);

                // Released before the write: the repository hands back a fresh
                // instance with an empty list.
                $events = $convertedLead->releaseEvents();

                $savedLead = $this->leadRepository->updateStatus($convertedLead);

                return Result::success(
                    data: $this->responseMapper->toResponseDTO($savedLead, $convertedAt),
                    message: $this->translator->translate('leads.converted'),
                );
            },
            logMessage: 'Lead conversion failed.',
            fallbackMessageKey: 'leads.errors.convert_failed',
            fallbackErrorCode: self::ERROR_CONVERT_FAILED,
            context: ['lead_id' => $leadId],
        );

        // Past this point the write has committed: a failing listener must not
        // turn a converted lead into a reported failure.
        if (! $result->isFailure()) {
            $this->dispatchEvents($events);
        }

        return $result;
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
     * Announces the conversion to whoever listens.
     *
     * The lead is already converted and committed by the time this runs, so a
     * failing listener must not turn a converted lead into a reported failure.
     * The port declares a void return, so the one failure shape visible here is
     * a listener that throws, which the framework hands straight back out of
     * dispatch; it is logged and the caller's Result is left alone. A listener
     * that fails without throwing is that listener's own to report - it holds
     * its own logger, and its failure is invisible from this side by design.
     *
     * @param list<object> $events
     *
     * @return void
     */
    private function dispatchEvents(array $events): void
    {
        try {
            foreach ($events as $event) {
                $this->eventDispatcher->dispatch($event);
            }
        } catch (\Throwable $exception) {
            $this->logger->error(
                message: 'Lead converted event listener raised an error.',
                context: ['message' => $exception->getMessage()],
            );
        }
    }
}