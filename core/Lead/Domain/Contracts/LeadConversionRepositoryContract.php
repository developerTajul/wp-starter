<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Contracts;

use Core\Lead\Domain\Entities\LeadConversionLinkEntity;

/**
 * Write side of the conversion-link port. It carries the write and the one read
 * that cannot be separated from it: the row lock taken just before the link is
 * written.
 */
interface LeadConversionRepositoryContract
{
    /**
     * Writes the link between the lead and what the conversion produced.
     *
     * @param LeadConversionLinkEntity $link
     *
     * @return LeadConversionLinkEntity The stored link.
     */
    public function create(LeadConversionLinkEntity $link): LeadConversionLinkEntity;

    /**
     * Reads the lead's conversion link for update, holding a row lock until the
     * transaction ends.
     *
     * Taken inside the transaction that is about to write the link, so two
     * conversions of the same lead are serialised: the second one sees the link
     * the first one committed and reports the conflict rather than attempting a
     * second row. The column is unique, so the index remains the backstop.
     *
     * @param int $leadId
     *
     * @return LeadConversionLinkEntity|null Null when the lead carries no link yet.
     */
    public function findByLeadIdForUpdate(int $leadId): ?LeadConversionLinkEntity;
}
