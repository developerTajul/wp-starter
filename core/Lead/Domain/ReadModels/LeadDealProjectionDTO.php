<?php

declare(strict_types=1);

namespace Core\Lead\Domain\ReadModels;

/**
 * Read model for one deal a lead produced: what it is worth and where it sits
 * in its pipeline. The stage is already flattened to the name the panel prints,
 * so nothing above Infrastructure resolves a relation to draw a row.
 *
 * The amount stays a string. It is a decimal column and formatting it to a
 * float here would round currency on the way to the screen (P2).
 */
final readonly class LeadDealProjectionDTO
{
    /**
     * @param int    $id       The deal's identity.
     * @param string $title    The deal's title.
     * @param string $amount   Decimal amount as stored, e.g. "180000.00".
     * @param string $currency ISO-4217 code, e.g. "BDT".
     * @param string $stage    The deal stage's name, or empty when it has none.
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $amount,
        public string $currency,
        public string $stage,
    ) {}
}