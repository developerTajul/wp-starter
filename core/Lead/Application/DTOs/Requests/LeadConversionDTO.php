<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Requests;

use DateTimeImmutable;

/**
 * Carries what a conversion needs to build the records the lead became.
 *
 * The values come from the lead at the moment it converted, so the builder
 * never reads the lead back and cannot pick up a later edit mid-conversion.
 */
final readonly class LeadConversionDTO
{
    /**
     * @param int               $leadId
     * @param string            $name
     * @param string|null       $email     Present: the entity refuses conversion without one.
     * @param string|null       $phone
     * @param string|null       $companyName
     * @param string            $source
     * @param DateTimeImmutable $convertedAt
     */
    public function __construct(
        public int $leadId,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $companyName,
        public string $source,
        public DateTimeImmutable $convertedAt,
    ) {}
}
