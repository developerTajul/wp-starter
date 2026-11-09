<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Events;

use DateTimeImmutable;

/**
 * Raised when a lead successfully becomes a converted lead.
 *
 * Carries the lead's identifying details as scalars so a listener can build
 * the company, contact and deal records without reading the lead back.
 */
final readonly class LeadConvertedEvent
{
    public function __construct(
        public int $leadId,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $companyName,
        public string $source,
        public DateTimeImmutable $occurredAt,
    ) {}
}