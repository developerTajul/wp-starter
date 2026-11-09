<?php

declare(strict_types=1);

namespace Core\Lead\Application\Listeners;

use Core\Lead\Application\DTOs\Requests\UpdateLeadStatusQueueDTO;
use Core\Lead\Domain\Enums\LeadStatus;
use Core\Activity\Domain\Events\ActivityCreatedEvent;
use Core\Shared\Application\Contracts\QueueManagerContract;

final class UpdateLeadStatusOnActivityCreated
{
    /**
     * The morph alias an activity carries when it is attached to a lead.
     */
    private const LEAD_MORPH_ALIAS = 'lead';

    public function __construct(
        private readonly QueueManagerContract $queueManager
    ) {}

    /**
     * @param ActivityCreatedEvent $event
     */
    public function handle(ActivityCreatedEvent $event): void
    {
        if ($event->trackableType === self::LEAD_MORPH_ALIAS) {
            $queueData = new UpdateLeadStatusQueueDTO(
                leadId: $event->trackableId,
                status: LeadStatus::CONTACTED
            );

            $this->queueManager->push('lead_status_update_topic', $queueData);
        }
    }
}