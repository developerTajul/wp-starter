<?php

declare(strict_types=1);

namespace Core\Lead\Application\Services;

use Core\Company\Application\Contracts\CompanyCommandServiceContract;
use Core\Company\Application\DTOs\Requests\StoreCompanyDTO;
use Core\Contact\Application\Contracts\ContactCommandServiceContract;
use Core\Contact\Application\DTOs\Requests\StoreContactDTO;
use Core\Deal\Application\Contracts\DealCommandServiceContract;
use Core\Deal\Application\Contracts\DealDefaultOptionsContract;
use Core\Deal\Application\DTOs\Requests\StoreDealDTO;
use Core\Deal\Domain\DealRules;
use Core\Lead\Application\Contracts\LeadConversionRecordsCommandServiceContract;
use Core\Lead\Application\DTOs\Requests\LeadConversionDTO;
use Core\Lead\Application\Mappers\LeadConversionLinkResponseMapper;
use Core\Lead\Domain\Contracts\LeadConversionRepositoryContract;
use Core\Lead\Domain\Entities\LeadConversionLinkEntity;
use Core\Lead\Domain\Exceptions\LeadConversionRecordsException;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\TransactionManagerContract;
use Core\Shared\Domain\Contracts\TranslatorContract;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Turns a converted lead into the records it became: a company, a contact, a
 * deal and the link that ties them to the lead.
 *
 * The lead's own status change happens in LeadConversionService; this service
 * runs after the dispatch and builds what the conversion produced.
 *
 * @implements LeadConversionRecordsCommandServiceContract
 */
final class LeadConversionCommandService implements LeadConversionRecordsCommandServiceContract
{
    private const ERROR_NOT_CONVERTED = 'LEAD_CONVERSION_NOT_READY';

    private const ERROR_ALREADY_LINKED = 'LEAD_ALREADY_CONVERTED';

    private const ERROR_FAILED = 'LEAD_CONVERSION_RECORDS_FAILED';

    /**
     * @param LeadConversionRepositoryContract $conversionRepository
     * @param ContactCommandServiceContract    $contactService
     * @param CompanyCommandServiceContract    $companyService
     * @param DealCommandServiceContract       $dealService
     * @param DealDefaultOptionsContract       $dealDefaults
     * @param LeadConversionLinkResponseMapper $linkResponseMapper
     * @param TransactionManagerContract       $transactionManager
     * @param TranslatorContract               $translator
     * @param LoggerInterface                  $logger
     */
    public function __construct(
        private readonly LeadConversionRepositoryContract $conversionRepository,
        private readonly ContactCommandServiceContract $contactService,
        private readonly CompanyCommandServiceContract $companyService,
        private readonly DealCommandServiceContract $dealService,
        private readonly DealDefaultOptionsContract $dealDefaults,
        private readonly LeadConversionLinkResponseMapper $linkResponseMapper,
        private readonly TransactionManagerContract $transactionManager,
        private readonly TranslatorContract $translator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Builds the records a converted lead becomes.
     *
     * The whole build runs in one transaction: a conversion that creates a
     * contact but no deal would leave a customer behind with no deal attached,
     * which is worse than creating nothing and saying so.
     *
     * The lead's existing link is read under a row lock, taken inside the
     * transaction, so two conversions racing for the same lead are serialised:
     * the second one sees the link the first one committed and reports the
     * conflict instead of building a second company, contact and deal. A plain
     * existence check read no lock, so both passed it and only the unique index
     * stood between them and a duplicate set of records.
     *
     * @param LeadConversionDTO $leadConversionDTO
     *
     * @return Result Success carries the stored conversion link.
     */
    public function convertLead(LeadConversionDTO $leadConversionDTO): Result
    {
        return $this->executeInTransaction(
            operation: function () use ($leadConversionDTO): Result {
                if ($this->conversionRepository->findByLeadIdForUpdate($leadConversionDTO->leadId) !== null) {
                    return Result::failure(
                        message: $this->translator->translate('leads.errors.already_converted'),
                        errorCode: self::ERROR_ALREADY_LINKED,
                    );
                }

                $companyId = $this->createCompanyWhenNamed($leadConversionDTO);
                $contactId = $this->createContact($leadConversionDTO, $companyId);
                $dealId = $this->createDeal($leadConversionDTO, $companyId, $contactId);

                $storedLink = $this->conversionRepository->create(
                    LeadConversionLinkEntity::create(
                        leadId: $leadConversionDTO->leadId,
                        contactId: $contactId,
                        companyId: $companyId,
                        dealId: $dealId,
                        convertedAt: $leadConversionDTO->convertedAt,
                    ),
                );

                return Result::success(
                    data: $this->linkResponseMapper->toResponseDTO($storedLink),
                    message: $this->translator->translate('leads.conversion.records_created'),
                );
            },
            logMessage: 'Building the records for a converted lead failed.',
            fallbackMessageKey: 'leads.errors.conversion_records_failed',
            fallbackErrorCode: self::ERROR_FAILED,
            context: ['lead_id' => $leadConversionDTO->leadId],
        );
    }

    /**
     * Runs a use case in a transaction and turns its failures into Results.
     *
     * The one place this service opens and closes a transaction. A throwable
     * escaping the operation has already been rolled back by the port, so the
     * catch blocks only translate. A record a downstream module rejected comes
     * back carrying that module's own error code rather than a generic one.
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
        } catch (LeadConversionRecordsException $exception) {
            $this->logger->error(
                message: $logMessage,
                context: array_merge($context, ['message' => $exception->getMessage()]),
            );

            return Result::failure(
                message: $this->translator->translate($exception->translationKey),
                errorCode: $exception->downstreamErrorCode ?? $fallbackErrorCode,
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
     * Creates the company when the lead named one. A lead with no company name
     * converts into a contact and a deal that carry no company, which the
     * columns allow.
     *
     * @param LeadConversionDTO $leadConversionDTO
     *
     * @return int|null
     *
     * @throws LeadConversionRecordsException When the company service reports a failure.
     */
    private function createCompanyWhenNamed(LeadConversionDTO $leadConversionDTO): ?int
    {
        $companyName = $leadConversionDTO->companyName;

        if ($companyName === null || trim($companyName) === '') {
            return null;
        }

        $result = $this->companyService->createCompany(
            new StoreCompanyDTO(
                name: $companyName,
                phone: $leadConversionDTO->phone,
            ),
        );

        if ($result->isFailure()) {
            throw LeadConversionRecordsException::downstreamFailed(
                record: 'Company',
                reason: $result->getMessage(),
                errorCode: $result->errorCode,
            );
        }

        return (int) $result->getData()->id;
    }

    /**
     * Creates the contact the conversion requires. The lead's email passed the
     * entity's precondition before the status moved, so it is present here.
     *
     * @param LeadConversionDTO $leadConversionDTO
     * @param int|null          $companyId
     *
     * @return int
     *
     * @throws LeadConversionRecordsException When the contact service reports a failure.
     */
    private function createContact(LeadConversionDTO $leadConversionDTO, ?int $companyId): int
    {
        $result = $this->contactService->createContact(
            new StoreContactDTO(
                name: $leadConversionDTO->name,
                email: (string) $leadConversionDTO->email,
                phone: $leadConversionDTO->phone,
                companyId: $companyId,
                isPrimary: true,
            ),
        );

        if ($result->isFailure()) {
            throw LeadConversionRecordsException::downstreamFailed(
                record: 'Contact',
                reason: $result->getMessage(),
                errorCode: $result->errorCode,
            );
        }

        return (int) $result->getData()->id;
    }

    /**
     * Creates the deal the conversion opens, in the default pipeline's opening
     * stage. A lead that has just converted has no price agreed, so the amount
     * is the module's zero.
     *
     * @param LeadConversionDTO $leadConversionDTO
     * @param int|null          $companyId
     * @param int               $contactId
     *
     * @return int|null Null when the install carries no usable pipeline/stage.
     *
     * @throws LeadConversionRecordsException When deal creation fails.
     */
    private function createDeal(
        LeadConversionDTO $leadConversionDTO,
        ?int $companyId,
        int $contactId,
    ): ?int {
        $pipelineId = $this->dealDefaults->getDefaultPipelineId();

        if ($pipelineId === null) {
            $this->logger->warning(
                message: 'No default pipeline exists, so no deal was created for the conversion.',
                context: ['lead_id' => $leadConversionDTO->leadId],
            );

            return null;
        }

        $stageId = $this->dealDefaults->findStageIdByName($pipelineId, DealRules::OPENING_STAGE_NAME);

        if ($stageId === null) {
            $this->logger->warning(
                message: 'The default pipeline carries no opening stage, so no deal was created.',
                context: [
                    'lead_id' => $leadConversionDTO->leadId,
                    'pipeline_id' => $pipelineId,
                ],
            );

            return null;
        }

        $result = $this->dealService->createDeal(
            new StoreDealDTO(
                title: $this->buildDealTitle($leadConversionDTO),
                pipelineId: $pipelineId,
                dealStageId: $stageId,
                companyId: $companyId,
                contactId: $contactId,
                amount: DealRules::DEFAULT_AMOUNT,
                currency: DealRules::DEFAULT_CURRENCY,
            ),
        );

        if ($result->isFailure()) {
            throw LeadConversionRecordsException::downstreamFailed(
                record: 'Deal',
                reason: $result->getMessage(),
                errorCode: $result->errorCode,
            );
        }

        return (int) $result->getData()->id;
    }

    /**
     * Builds the deal's title from the lead. The name is the lead's own, so the
     * title reads the same way the lead does.
     *
     * @param LeadConversionDTO $leadConversionDTO
     *
     * @return string
     */
    private function buildDealTitle(LeadConversionDTO $leadConversionDTO): string
    {
        return trim($leadConversionDTO->name) . ' Deal';
    }
}
