<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Entities;

use DateTimeImmutable;

/**
 * The link between a converted lead and the records the conversion produced.
 *
 * The link is a join row: it holds no state to guard and no transition to
 * enforce beyond the columns the table already constrains. The contact is
 * required because the column is NOT NULL; the company and the deal are
 * optional because a lead may carry no company name and a conversion may
 * complete before a deal is opened.
 */
readonly class LeadConversionLinkEntity
{
    /**
     * @param int|null          $id          Identity. Null until the link is stored.
     * @param int               $leadId
     * @param int               $contactId
     * @param int|null          $companyId
     * @param int|null          $dealId
     * @param DateTimeImmutable $convertedAt
     */
    public function __construct(
        public ?int $id,
        public int $leadId,
        public int $contactId,
        public ?int $companyId,
        public ?int $dealId,
        public DateTimeImmutable $convertedAt,
    ) {}

    /**
     * Creates a link that has not been stored yet.
     *
     * @param int               $leadId
     * @param int               $contactId
     * @param int|null          $companyId
     * @param int|null          $dealId
     * @param DateTimeImmutable $convertedAt
     *
     * @return self
     */
    public static function create(
        int $leadId,
        int $contactId,
        ?int $companyId,
        ?int $dealId,
        DateTimeImmutable $convertedAt,
    ): self {
        return new self(
            id: null,
            leadId: $leadId,
            contactId: $contactId,
            companyId: $companyId,
            dealId: $dealId,
            convertedAt: $convertedAt,
        );
    }
}