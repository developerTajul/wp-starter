<?php

declare(strict_types=1);

namespace Core\Lead\Application\Services;

use Core\Lead\Application\Contracts\LeadQueryContract;
use Core\Lead\Application\Contracts\LeadTimelineContract;
use Core\Lead\Application\Contracts\LeadTimelineQueryServiceContract;
use Core\Lead\Application\DTOs\Responses\LeadTimelineResponseDTO;
use Core\Lead\Domain\Rules\LeadRules;
use Core\Shared\Application\DTOs\Result;
use Core\Shared\Domain\Contracts\TranslatorContract;

/**
 * Handles the lead activity timeline read.
 *
 * @implements LeadTimelineQueryServiceContract
 */
final class LeadTimelineQueryService implements LeadTimelineQueryServiceContract
{
    private const ERROR_LEAD_NOT_FOUND = 'LEAD_NOT_FOUND';

    /**
     * @param LeadTimelineContract $timeline
     * @param LeadQueryContract    $leadQuery
     * @param TranslatorContract   $translator
     */
    public function __construct(
        private readonly LeadTimelineContract $timeline,
        private readonly LeadQueryContract $leadQuery,
        private readonly TranslatorContract $translator,
    ) {}

    /**
     * Returns a lead's activity timeline, newest first.
     *
     * The lead is looked up first so a timeline is only served for a lead that
     * exists: an empty timeline and a missing lead are different answers, and
     * the page must not report the first when it means the second.
     *
     * @param int $leadId
     * @param int $limit
     *
     * @return Result Contains the LeadTimelineResponseDTO on success.
     */
    public function getTimelineForLead(int $leadId, int $limit = LeadRules::TIMELINE_DEFAULT_LIMIT): Result
    {
        if (! $this->leadQuery->exists($leadId)) {
            return Result::failure(
                message: $this->translator->translate('leads.errors.lead_not_found'),
                errorCode: self::ERROR_LEAD_NOT_FOUND,
            );
        }

        $clampedLimit = max(
            LeadRules::TIMELINE_MIN_LIMIT,
            min($limit, LeadRules::TIMELINE_MAX_LIMIT)
        );

        return Result::success(
            data: new LeadTimelineResponseDTO(
                entries: $this->timeline->getTimelineForLead($leadId, $clampedLimit),
                total: $this->timeline->countTimelineForLead($leadId),
                limit: $clampedLimit,
            ),
        );
    }
}
