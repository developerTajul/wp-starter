<?php

declare(strict_types=1);

namespace Core\Lead\Domain\ReadModels;

use Core\Lead\Domain\Enums\LeadStatus;
use DateTimeImmutable;

/**
 * Read model for the lead list and detail. Carries the columns the page shows,
 * without hydrating an entity that nothing on the read path needs.
 */
final readonly class LeadProjectionDTO
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $companyName,
        public LeadStatus $status,
        public string $source,
        public ?int $assignedTo = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}
}