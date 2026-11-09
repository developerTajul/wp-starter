<?php

declare(strict_types=1);

namespace Core\Lead\Application\Services;

use Core\Lead\Application\Contracts\LeadDealContract;
use Core\Lead\Application\Contracts\LeadDealQueryServiceContract;
use Core\Lead\Application\Contracts\LeadQueryContract;
use Core\Lead\Application\DTOs\Responses\LeadDealResponseDTO;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\TranslatorContract;

/**
 * Handles the lead deals read.
 *
 * @implements LeadDealQueryServiceContract
 */
final class LeadDealQueryService implements LeadDealQueryServiceContract
{
    private const ERROR_LEAD_NOT_FOUND = 'LEAD_NOT_FOUND';

    /**
     * @param LeadDealContract   $deals
     * @param LeadQueryContract  $leadQuery
     * @param TranslatorContract $translator
     */
    public function __construct(
        private readonly LeadDealContract $deals,
        private readonly LeadQueryContract $leadQuery,
        private readonly TranslatorContract $translator,
    ) {}

    /**
     * Returns the deals a lead converted into, newest first.
     *
     * The lead is looked up first so a panel is only served for a lead that
     * exists: "this lead produced no deals" and "there is no such lead" are
     * different answers, and the page must not report the first when it means
     * the second.
     *
     * @param int $leadId
     *
     * @return Result Contains the LeadDealResponseDTO on success.
     */
    public function getDealsForLead(int $leadId): Result
    {
        if (! $this->leadQuery->exists($leadId)) {
            return Result::failure(
                message: $this->translator->translate('leads.errors.lead_not_found'),
                errorCode: self::ERROR_LEAD_NOT_FOUND,
            );
        }

        return Result::success(
            data: new LeadDealResponseDTO(
                deals: $this->deals->getDealsForLead($leadId),
            ),
        );
    }
}
