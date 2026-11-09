<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Requests;

use Core\Lead\Domain\Enums\LeadStatus;

/**
 * Carries the lead list criteria into the Service. Built by LeadMapper.
 */
final readonly class LeadFilterDTO
{
    public function __construct(
        public ?LeadStatus $status = null,
        public ?string $source = null,
        public ?string $search = null,
        public int $perPage = 15,
    ) {}
}
