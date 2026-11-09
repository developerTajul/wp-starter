<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Requests;

use Core\Lead\Domain\Enums\LeadStatus;
use Core\Shared\Application\DTOs\WriteDTO;

readonly class UpdateLeadStatusQueueDTO extends WriteDTO
{
    public function __construct(
        public int $leadId,
        public LeadStatus $status
    ) {}
}