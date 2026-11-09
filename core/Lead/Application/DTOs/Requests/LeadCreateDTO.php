<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Requests;

use Core\Lead\Domain\Enums\LeadStatus;
use Core\Shared\Application\DTOs\WriteDTO;

/**
 * Carries validated lead creation data from the delivery layer to the service.
 */
readonly class LeadCreateDTO extends WriteDTO
{
    public function __construct(
        public string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $companyName,
        public LeadStatus $status,
        public string $source,
    ) {}
}
